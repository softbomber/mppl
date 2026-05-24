local cjson = require "cjson"
local redis = require "resty.redis"

-- ============================================================================
-- === 1. КОНФИГУРАЦИЯ ===
-- ============================================================================
local MAX_DEVICES       = 2
local HEARTBEAT_TTL     = 25
local REDIS_HOST        = "45.9.73.98"
local REDIS_PASS        = "qw34rfvgtU9snaWE"
local CDN_CONFIG_TTL    = 10   -- было 5, стало 10 (меньше disk I/O / Redis RTT)
local PROVIDERS_CACHE_TTL = 10 -- кэш провайдеров в Lua-памяти
local CDN_STATS_TTL     = 3600

-- Anti-zapping конфигурация
local ZAP_BURST_MAX        = 6
local ZAP_BURST_WINDOW     = 5
local ZAP_MAX_SWITCHES     = 10
local ZAP_WINDOW           = 60
local ZAP_BAN_TIME         = 60
local ZAP_BAN_TIME_REPEAT  = 120
local ZAP_MAX_VIOLATIONS   = 5
local ZAP_VIOLATIONS_RESET = 60

-- Seed PRNG один раз на воркер при загрузке скрипта.
math.randomseed(ngx.now() * 1000 + ngx.worker.pid())
for _ = 1, 5 do math.random() end  -- "прогрев" генератора

-- Кэши на уровне воркера
local cdn_config_cache      = nil
local last_config_check     = 0
local providers_cache       = nil
local providers_cache_time  = 0

-- ============================================================================
-- === HELPERS: Redis connection ===
-- ============================================================================
local function connect_redis()
    local red = redis:new()
    red:set_timeout(1000)
    local ok, err = red:connect(REDIS_HOST, 6379)
    if not ok then
        ngx.log(ngx.ERR, "[REDIS] connect fail: ", err)
        return ngx.exit(500)
    end
    red:auth(REDIS_PASS)
    return red
end

-- ============================================================================
-- === HELPERS: schedule / time ===
-- ============================================================================
local function is_time_allowed(schedule)
    if not schedule or schedule == ngx.null then return true end
    local now_str = os.date("%H:%M")
    local intervals = (schedule.start) and {schedule} or schedule
    for _, range in ipairs(intervals) do
        local s, e = range.start, range["end"]
        if s and e then
            if s <= e then
                if now_str >= s and now_str <= e then return true end
            else
                if now_str >= s or now_str <= e then return true end
            end
        end
    end
    return false
end

-- ============================================================================
-- === CDN CONFIG (единый источник: Redis, кэш 10 сек) ===
-- Убран file-based read_file() — Nginx синхронизирует конфиг в Redis
-- ============================================================================
local function get_cdn_config(red)
    local now = ngx.time()
    if cdn_config_cache and (now - last_config_check <= CDN_CONFIG_TTL) then
        return cdn_config_cache
    end
    local raw = red:get("config:cdn_json")
    if not raw or raw == ngx.null then
        ngx.log(ngx.WARN, "[CDN_CFG] config:cdn_json is empty in Redis")
        return cdn_config_cache or {}
    end
    local ok, data = pcall(cjson.decode, raw)
    if not ok then
        ngx.log(ngx.ERR, "[CDN_CFG] JSON decode failed: ", data)
        return cdn_config_cache or {}
    end
    cdn_config_cache, last_config_check = data, now
    return data
end

-- ============================================================================
-- === PROVIDERS CACHE (кэш в Lua-памяти, 10 сек, уже отсортированы) ===
-- Убираем hgetall + JSON-декоды + table.sort на каждом запросе
-- ============================================================================
local function get_providers(red)
    local now = ngx.time()
    if providers_cache and (now - providers_cache_time <= PROVIDERS_CACHE_TTL) then
        return providers_cache
    end
    local providers_json = red:hgetall("config:providers")
    local providers = {}
    for i = 1, #providers_json, 2 do
        local ok, cfg = pcall(cjson.decode, providers_json[i + 1])
        if ok and cfg then
            providers[#providers + 1] = {
                name     = providers_json[i],
                allow    = cfg.allow_channels or 1,
                priority = cfg.priority or 999,
                cfg      = cfg,
            }
        end
    end
    table.sort(providers, function(a, b) return a.priority < b.priority end)
    providers_cache      = providers
    providers_cache_time = now
    return providers
end

-- ============================================================================
-- === HELPERS: normalize / validate ===
-- ============================================================================
local function normalize_ip_list(val)
    if not val then return nil end
    local res = {}
    if type(val) == "table" then
        for _, v in ipairs(val) do table.insert(res, v) end
    elseif type(val) == "string" then
        for match in (val .. ","):gmatch("(.-),") do
            local trimmed = match:match("^%s*(.-)%s*$")
            if trimmed and trimmed ~= "" then table.insert(res, trimmed) end
        end
    end
    return #res > 0 and res or nil
end

local function normalize_base(base)
    if not base then return nil end
    return base:match("^https?://") and base:gsub("/$", "") or "http://" .. base:gsub("/$", "")
end

-- Валидация URL: хост содержит только ASCII-символы
local function validate_source_url(url)
    if not url or url == "" then return false end
    local host = url:match("^https?://([^/:]+)")
    if not host then return false end
    if host:match("[^A-Za-z0-9%.%-]") then return false end
    if not host:match("%.") then return false end
    return true
end

local function string_split(str, delim)
    local result = {}
    for match in (str .. delim):gmatch("(.-)" .. delim) do table.insert(result, match) end
    return result
end

-- ============================================================================
-- === HELPERS: chanmap accessor (O(1) точечный доступ) ===
-- ============================================================================
local function get_channel_info(red, channel)
    if not channel or channel == "0" then return nil end
    local raw = red:hget("config:chanmap", channel)
    if not raw or raw == ngx.null then return nil end
    local ok, info = pcall(cjson.decode, raw)
    return ok and info or nil
end

-- ============================================================================
-- === HELPERS: URL resolvers ===
-- ============================================================================
local function resolve_tvclub(ch, base)
    local nb = normalize_base(base)
    return (nb:match("/iptv/") and nb or nb .. "/iptv") .. "/" .. ch .. "/index.m3u8"
end

local function resolve_admuspeh(ch_id, token)
    return "http://cdn.admuspeh.my/" .. (ch_id or "0") .. "/video.m3u8?token=" .. token
end

local function resolve_elbrus(channel_path, token, origin)
    if not channel_path or channel_path == "" then
        ngx.log(ngx.ERR, "[ELBRUS] Missing channel_path")
        return nil
    end
    if not token or token == "" then
        ngx.log(ngx.ERR, "[ELBRUS] Missing token")
        return nil
    end
    if not origin or origin == "" then
        origin = "https://cdn.balelbrus.com"
    end
    local origin_with_scheme = origin
    if not origin:match("^https?://") then
        origin_with_scheme = "http://" .. origin
    end
    origin_with_scheme = origin_with_scheme:gsub("/$", "")
    channel_path = channel_path:gsub("^/+", ""):gsub("/+$", "")
    local url = string.format("%s/%s/index.m3u8?token=%s", origin_with_scheme, channel_path, token)
    ngx.log(ngx.INFO, "[ELBRUS] Resolved URL: ", url)
    return url
end

local function urljoin(base, path)
    local nb = normalize_base(base)
    return nb and nb .. "/" .. path:gsub("^/", "") or path
end

-- ============================================================================
-- === CORE: Weighted CDN selection (единое ядро) ===
-- Заменяет 4 дубликата логики "лотерейного барабана"
-- ============================================================================
local function pick_weighted_cdn(candidates, red, opts)
    opts = opts or {}
    if not candidates or #candidates == 0 then return nil end

    -- 1. Sticky: если запрошен и сервер не перегружен — остаёмся.
    if opts.sticky_key then
        local cur_ip = red:get(opts.sticky_key)
        if cur_ip and cur_ip ~= ngx.null then
            for _, c in ipairs(candidates) do
                if c.ip == cur_ip then
                    local stats = red:hmget("cdn_stats:" .. cur_ip, "tx", "rx", "updated")
                    if stats and stats[3] and stats[3] ~= ngx.null then
                        local load  = math.max(tonumber(stats[1]) or 0, tonumber(stats[2]) or 0)
                        local limit = tonumber(c.cfg.limit_mbps) or 100
                        if load < limit * 0.85 then return cur_ip end
                    end
                end
            end
        end
    end

    -- 2. Основной проход: weighted pool + min_load fallback.
    local weighted_pool = {}
    local min_load, fallback_ip = 999999, nil

    for _, cand in ipairs(candidates) do
        if not opts.exclude_ip or cand.ip ~= opts.exclude_ip then
            local limit = tonumber(cand.cfg.limit_mbps) or 100
            -- ОДИН hmget вместо 3×hget (снижение RTT)
            local stats = red:hmget("cdn_stats:" .. cand.ip, "tx", "rx", "updated")
            local alive = stats and stats[3] and stats[3] ~= ngx.null

            if alive or opts.require_heartbeat == false then
                local tx   = alive and tonumber(stats[1]) or 0
                local rx   = alive and tonumber(stats[2]) or 0
                local load = math.max(tx or 0, rx or 0)

                if load < min_load then
                    min_load, fallback_ip = load, cand.ip
                end

                if alive and load < (limit - 10) then
                    local w = math.max(tonumber(cand.cfg.weight) or 1, 1)
                    for _ = 1, w do weighted_pool[#weighted_pool + 1] = cand.ip end
                end
            else
                ngx.log(ngx.WARN, "[CDN] Skipping DEAD CDN ", cand.ip, " (no heartbeat)")
            end
        end
    end

    if #weighted_pool > 0 then
        return weighted_pool[math.random(1, #weighted_pool)]
    end
    if fallback_ip then return fallback_ip end

    ngx.log(ngx.ERR, "[CDN] CRITICAL: no alive CDNs, falling back to random candidate")
    return candidates[math.random(1, #candidates)].ip
end

-- ============================================================================
-- === CDN SELECTORS ===
-- ============================================================================
local function get_random_active_cdn(red)
    local config = get_cdn_config(red)
    local candidates = {}
    for ip, cfg in pairs(config) do
        if cfg.active ~= false and is_time_allowed(cfg.schedule) then
            candidates[#candidates + 1] = { ip = ip, cfg = cfg }
        end
    end
    if #candidates == 0 then
        for ip, cfg in pairs(config) do
            if cfg.active ~= false then
                candidates[#candidates + 1] = { ip = ip, cfg = cfg }
            end
        end
    end
    return pick_weighted_cdn(candidates, red, { require_heartbeat = false })
end

local function get_cdn_ip(key_prefix, red, allow_raw, disallow_raw, is_sticky, slot_index)
    local config = get_cdn_config(red)
    local allow_list    = normalize_ip_list(allow_raw)
    local disallow_list = normalize_ip_list(disallow_raw)

    local candidates = {}
    for ip, cfg in pairs(config) do
        local is_valid = (cfg.active ~= false) and is_time_allowed(cfg.schedule)
        if is_valid and allow_list then
            local found = false
            for _, a_ip in ipairs(allow_list) do if ip == a_ip then found = true break end end
            if not found then is_valid = false end
        end
        if is_valid and disallow_list then
            for _, b_ip in ipairs(disallow_list) do if ip == b_ip then is_valid = false break end end
        end
        if is_valid then candidates[#candidates + 1] = { ip = ip, cfg = cfg } end
    end

    if #candidates == 0 then
        for ip, cfg in pairs(config) do
            if cfg.active ~= false and is_time_allowed(cfg.schedule) then
                candidates[#candidates + 1] = { ip = ip, cfg = cfg }
            end
        end
        if #candidates == 0 then return nil end
    end

    local slot_cdn_key = key_prefix .. "_cdn"
    local sticky_key   = (is_sticky and slot_index) and slot_cdn_key or nil
    local best_ip      = pick_weighted_cdn(candidates, red, { sticky_key = sticky_key })

    if best_ip then
        red:setex(slot_cdn_key, CDN_STATS_TTL, best_ip)
    end
    return best_ip
end

local function get_cdn_ip_for_slot(provider_name, slot_idx, allowed_cdns, red, exclude_cdn)
    local cdn_config = get_cdn_config(red)
    local available_cdns = {}

    for cdn_ip, cfg in pairs(cdn_config) do
        if cfg.active then
            if not allowed_cdns or #allowed_cdns == 0 then
                available_cdns[#available_cdns + 1] = { ip = cdn_ip, cfg = cfg }
            else
                for _, a in ipairs(allowed_cdns) do
                    if cdn_ip == a then
                        available_cdns[#available_cdns + 1] = { ip = cdn_ip, cfg = cfg }
                        break
                    end
                end
            end
        end
    end

    if #available_cdns == 0 then
        ngx.log(ngx.ERR, "[CDN_SELECT] No available CDN for provider=", provider_name,
                " slot=", slot_idx)
        return nil
    end

    local selected_ip = pick_weighted_cdn(available_cdns, red, { exclude_ip = exclude_cdn })

    ngx.log(ngx.INFO, "[CDN_SELECT] provider=", provider_name, " slot=", slot_idx,
            " selected=", selected_ip, " available=", #available_cdns,
            (exclude_cdn and (" exclude=" .. exclude_cdn) or ""))
    return selected_ip
end

-- get_archive_cdn вынесена наверх (была внутри main execution — баг оригинала)
local function get_archive_cdn(red, channel, config, excluded_cdn)
    local cached_cdn = red:hget("archive_history", channel)
    if cached_cdn and cached_cdn ~= ngx.null then
        if config[cached_cdn] and config[cached_cdn].active ~= false then
            -- Один hmget вместо одиночного hget
            local stats = red:hmget("cdn_stats:" .. cached_cdn, "tx", "rx", "updated")
            if stats and stats[3] and stats[3] ~= ngx.null then
                return cached_cdn
            end
            ngx.log(ngx.WARN, "[ARCHIVE] Cached CDN ", cached_cdn, " is DEAD. Searching new...")
        end
    end

    local candidates = {}
    for ip, cfg in pairs(config) do
        if ip ~= excluded_cdn and cfg.active ~= false and is_time_allowed(cfg.schedule) then
            -- Один hmget вместо одиночного hget
            local stats = red:hmget("cdn_stats:" .. ip, "tx", "rx", "updated")
            if stats and stats[3] and stats[3] ~= ngx.null then
                candidates[#candidates + 1] = { ip = ip, cfg = cfg }
            end
        end
    end

    if #candidates == 0 then
        if excluded_cdn then return get_archive_cdn(red, channel, config, nil) end
        for ip, cfg in pairs(config) do
            if cfg.active ~= false then
                candidates[#candidates + 1] = { ip = ip, cfg = cfg }
            end
        end
        if #candidates == 0 then return nil end
    end

    -- Консистентное хэширование
    local sorted_ips = {}
    for _, c in ipairs(candidates) do sorted_ips[#sorted_ips + 1] = c.ip end
    table.sort(sorted_ips)
    local channel_hash = ngx.md5(tostring(channel))
    local hash_num = tonumber(channel_hash:sub(1, 8), 16)
    return sorted_ips[(hash_num % #sorted_ips) + 1]
end

-- ============================================================================
-- === STUB FACTORY (HLS-заглушки) ===
-- ============================================================================
local STUB_PATHS = {
    black  = "/black.ts",
    ban    = "/ban/black.ts",
    splash = "/stub/splash.ts",
    limit  = "/limit.ts",
}

local function serve_stub(red, stub_type, http_status, error_text)
    local r_cdn = get_random_active_cdn(red) or "127.0.0.1"
    local path  = STUB_PATHS[stub_type] or STUB_PATHS.black
    red:set_keepalive(10000, 50)
    ngx.status = http_status or 200
    ngx.header["Content-Type"] = "application/vnd.apple.mpegurl"
    if error_text then
        ngx.say("#EXTM3U\n#EXT-X-ERROR: " .. error_text .. "\n#EXT-X-ENDLIST")
    else
        ngx.say("#EXTM3U\n#EXT-X-VERSION:3\n#EXT-X-TARGETDURATION:10\n" ..
                "#EXTINF:10.0,\nhttp://" .. r_cdn .. ":8123" .. path .. "\n#EXT-X-ENDLIST")
    end
    return ngx.exit(http_status or 200)
end

-- ============================================================================
-- === CDN SIGNAL ===
-- ============================================================================
local function signal_cdn(alloc, key, channel, red)
    if not (alloc.cdn_ip and alloc.provider and alloc.slot and alloc.source_url) then return end
    red:publish("channel_starts", cjson.encode({
        cdn_ip       = alloc.cdn_ip,
        key          = key,
        token        = alloc.token,
        channel      = channel,
        provider     = alloc.provider,
        slot         = alloc.slot,
        source_url   = alloc.source_url,
        user_agent   = alloc.user_agent,
        bandwidth    = alloc.bandwidth,
        quality      = alloc.quality,
        sources      = alloc.sources,
        referer      = alloc.referer,
        allocated_at = alloc.allocated_at,
    }))
end

-- ============================================================================
-- === SLOT USAGE DECREMENT (единая функция) ===
-- ============================================================================
local function decrement_slot_usage(red, provider, slot, log_prefix)
    if not provider or provider == "direct_url" or not slot then return false end
    local usage_key = provider .. ":usage"
    local current = tonumber(red:lindex(usage_key, slot)) or 0
    if current <= 0 then
        ngx.log(ngx.WARN, (log_prefix or "[SLOT]") .. " usage already 0 for ",
                provider, " slot=", slot, " — skip decrement")
        return false
    end
    red:lset(usage_key, slot, tostring(current - 1))
    ngx.log(ngx.INFO, (log_prefix or "[SLOT]") .. " decremented: provider=",
            provider, " slot=", slot, " new_usage=", current - 1)
    return true
end

-- ============================================================================
-- === ONLINE SESSIONS SEARCH (индекс-множество online:tokens) ===
-- Замена red:keys("online:users:*") на smembers — в 10-100× быстрее
-- ============================================================================
local function find_other_sessions(red, channel, exclude_session_id)
    local tokens = red:smembers("online:tokens")
    if not tokens then return { found = false, new_owner = nil } end

    local result = { found = false, new_owner = nil }

    for _, token in ipairs(tokens) do
        local meta_key = "online:users:" .. token .. ":meta"
        local all_meta = red:hgetall(meta_key)

        -- Ленивая очистка: если метаданных нет — токен мёртв, удаляем из индекса
        if not all_meta or #all_meta == 0 then
            red:srem("online:tokens", token)
        else
            for i = 1, #all_meta, 2 do
                local sess_id   = all_meta[i]
                local meta_json = all_meta[i + 1]
                if sess_id ~= exclude_session_id and meta_json then
                    local ok, m = pcall(cjson.decode, meta_json)
                    if ok and m and tostring(m.channel) == tostring(channel) then
                        result.found = true
                        if not result.new_owner then
                            result.new_owner = sess_id
                        end
                    end
                end
            end
        end
    end
    return result
end

-- ============================================================================
-- === ARCHIVE CLEANUP (вынесено в отдельную функцию) ===
-- ============================================================================
local function cleanup_stale_archive(red, channel)
    local archive_key   = "archive_last_seen:" .. channel
    local last_seen_raw = red:get(archive_key)
    local last_seen     = tonumber(last_seen_raw) or 0
    local now_time      = ngx.time()

    local expired = (last_seen_raw == nil) or
                    (last_seen > 0 and (now_time - last_seen) > 60)
    if not expired then
        ngx.log(ngx.INFO, "[ARCHIVE] archive_last_seen still active for channel=", channel)
        return false
    end

    local alloc_json = red:hget("channel_allocations", channel)
    if alloc_json and alloc_json ~= ngx.null then
        ngx.log(ngx.INFO, "[ARCHIVE] channel_allocations exists — keeping slot for ", channel)
        red:hdel("archive_history", channel)
        red:del(archive_key)
        return false
    end

    local others = find_other_sessions(red, channel, nil)
    if others.found then
        ngx.log(ngx.INFO, "[ARCHIVE] active session found on ", channel, " — keeping slot")
        red:hdel("archive_history", channel)
        red:del(archive_key)
        return false
    end

    ngx.log(ngx.INFO, "[ARCHIVE] === FREEING SLOT === channel=", channel)
    red:hdel("archive_history", channel)
    red:del(archive_key)

    -- Декремент LIVE-слота (на случай остатков)
    if alloc_json and alloc_json ~= ngx.null then
        local ok, alloc = pcall(cjson.decode, alloc_json)
        if ok and alloc then
            decrement_slot_usage(red, alloc.provider, alloc.slot, "[ARCHIVE/LIVE]")
        end
    end

    -- Декремент архивного tvclub-слота
    local arc_json = red:hget("archive_tvclub_url", channel)
    if arc_json and arc_json ~= ngx.null then
        local ok, arc = pcall(cjson.decode, arc_json)
        if ok and arc then
            decrement_slot_usage(red, arc.provider, arc.slot, "[ARCHIVE/TVCLUB]")
        end
    end

    red:hdel("channel_allocations", channel)
    red:hdel("archive_tvclub_url", channel)
    ngx.log(ngx.INFO, "[ARCHIVE] === SLOT FREED === channel=", channel)
    return true
end

-- ============================================================================
-- === SELECT CDN SLOT (с O(1) chanmap и кэшем провайдеров) ===
-- ============================================================================
local function select_cdn_slot(red, channel, client_ua)
    ngx.log(ngx.INFO, "[SLOT_ALLOC] select_cdn_slot called: channel=", channel)

    -- O(1): точечный hget вместо hgetall по всей chanmap (сотни каналов)
    local channel_info  = get_channel_info(red, channel)
    local final_ua      = (channel_info and channel_info.agent) or client_ua
    local final_referer = channel_info and channel_info.referer

    -- Прямая ссылка из chanmap
    if channel_info and channel_info.url then
        local usage_key = "direct_url:usage"
        if red:llen(usage_key) == 0 then
            red:rpush(usage_key, 1)
        else
            red:lset(usage_key, 0, (tonumber(red:lindex(usage_key, 0)) or 0) + 1)
        end

        local cdn_ip = get_cdn_ip("direct_url_" .. channel, red,
                                  channel_info.allow, channel_info.disallow, false, 0)
        local alloc = {
            provider     = "direct_url",
            slot         = 0,
            allocated_at = ngx.time(),
            cdn_ip       = cdn_ip,
            source_url   = channel_info.url,
            quality      = channel_info.quality,
            bandwidth    = channel_info.bandwidth,
            sources      = {},
            user_agent   = final_ua,
            referer      = final_referer,
        }
        return "direct_url", 0, cdn_ip, nil, alloc
    end

    -- Провайдеры: из Lua-кэша (10 сек), уже отсортированы по priority
    local providers = get_providers(red)

    local acquire_slot_script = [[
        local key = KEYS[1]
        local idx = tonumber(ARGV[1])
        local limit = tonumber(ARGV[2])
        local current = tonumber(redis.call('LINDEX', key, idx) or '0')
        if current >= limit then return {0, 'slot_full'} end
        redis.call('LSET', key, idx, current + 1)
        return {1, 'ok'}
    ]]

    for _, provider in ipairs(providers) do
        local usage_key = provider.name .. ":usage"
        local len = red:llen(usage_key)
        if len > 0 then
            local now = ngx.time()
            local last_used_key = provider.name .. ":last_used"
            local ttl = math.max(300, len * 10)

            local slot_scores = {}
            for i = 0, len - 1 do
                local current_usage = tonumber(red:lindex(usage_key, i)) or 0
                local last_used     = tonumber(red:hget(last_used_key, tostring(i))) or 0
                slot_scores[#slot_scores + 1] = {
                    idx            = i,
                    usage          = current_usage,
                    last_used      = last_used,
                    time_since_use = now - last_used,
                    score          = now - last_used,
                }
            end
            table.sort(slot_scores, function(a, b) return a.score > b.score end)

            local slot_allocated = false
            for _, slot_info in ipairs(slot_scores) do
                local idx = slot_info.idx

                local items = provider.cfg.tokens or provider.cfg.bases or {}
                local item  = items[idx + 1]

                local base_url
                local allowed_cdns = {}
                if type(item) == "table" then
                    base_url     = item.url
                    allowed_cdns = item.allowed_cdns or {}
                else
                    base_url = item
                end

                local source_url = nil
                if provider.name == "admuspeh" then
                    local adm_id = channel_info and (channel_info.admuspeh or channel_info.id)
                    source_url = resolve_admuspeh(adm_id, base_url)
                elseif provider.name == "tvclub" then
                    source_url = resolve_tvclub(channel, base_url)
                elseif provider.name == "elbrus" then
                    local elbrus_path = channel_info and channel_info.elbrus
                    source_url = resolve_elbrus(elbrus_path, base_url, provider.cfg.origin)
                else
                    source_url = urljoin(base_url, channel .. "/index.m3u8")
                end

                if not source_url then
                    ngx.log(ngx.INFO, "[SLOT_SKIP] Provider ", provider.name,
                            " cannot play channel ", channel)
                    goto continue_slot_search
                end

                if not validate_source_url(source_url) then
                    ngx.log(ngx.ERR, "[SLOT_SKIP] Invalid source_url for provider=",
                            provider.name, " channel=", channel, " url=", source_url)
                    goto continue_slot_search
                end

                local res = red:eval(acquire_slot_script, 1, usage_key, idx, provider.allow)

                if type(res) == "table" and res[1] == 1 then
                    slot_allocated = true
                    red:hset(last_used_key, tostring(idx), tostring(now))
                    red:expire(last_used_key, ttl)

                    local cdn_ip = get_cdn_ip_for_slot(provider.name, idx, allowed_cdns, red)
                    if not cdn_ip then
                        ngx.log(ngx.ERR, "[SLOT_ALLOC] No available CDN for slot, rolling back")
                        red:eval([[
                            local key = KEYS[1]
                            local idx = tonumber(ARGV[1])
                            local current = tonumber(redis.call('LINDEX', key, idx) or '0')
                            if current > 0 then redis.call('LSET', key, idx, current - 1) end
                            return 1
                        ]], 1, usage_key, idx)
                        goto continue_slot_search
                    end

                    local alloc = {
                        provider     = provider.name,
                        slot         = idx,
                        allocated_at = ngx.time(),
                        cdn_ip       = cdn_ip,
                        source_url   = source_url,
                        token        = base_url,
                        user_agent   = final_ua,
                        referer      = final_referer,
                    }
                    ngx.log(ngx.INFO, "[SLOT_ALLOC] ALLOCATED: channel=", channel,
                            " provider=", provider.name, " slot=", idx)
                    return provider.name, idx, cdn_ip, base_url, alloc
                end

                ::continue_slot_search::
            end
            if not slot_allocated then
                ngx.log(ngx.WARN, "[SLOT_ALLOC] All slots full for provider=", provider.name,
                        " limit=", provider.allow, " — skipping")
            end
        end
    end

    return nil, nil, nil, nil, nil
end

-- ============================================================================
-- === MAIN EXECUTION ===
-- ============================================================================
local red = connect_redis()

-- === Token parsing ===
local raw_token = ngx.var.arg_token
local token     = raw_token
local utc, lutc = nil, nil

if raw_token then
    local q_pos = string.find(raw_token, "?", 1, true)
    if q_pos then
        token = string.sub(raw_token, 1, q_pos - 1)
        local tail = ngx.unescape_uri(string.sub(raw_token, q_pos + 1))
        for key, value in string.gmatch(tail, "([%w_]+)=([%d]+)") do
            if key == "utc"  then utc  = value; ngx.log(ngx.INFO, "[ARCHIVE] Extracted utc=",  utc)  end
            if key == "lutc" then lutc = value; ngx.log(ngx.INFO, "[ARCHIVE] Extracted lutc=", lutc) end
        end
    end
end

if not utc then
    local full_uri = ngx.var.request_uri
    utc  = string.match(full_uri, "[?&]utc=(%d+)")
    lutc = lutc or string.match(full_uri, "[?&]lutc=(%d+)")
end
if not lutc then
    lutc = tostring(os.time() + 3600)
end

local token_suffix = token and "?token=" .. token or ""
if not token or token == "" then
    red:set_keepalive(10000, 50)
    ngx.status = 403
    ngx.say("Token missing")
    return ngx.exit(403)
end

-- === Channel extraction ===
local channel = ngx.var.channel or "0"
if channel == "0" then
    local s, e = string.find(ngx.var.uri, "/(%d+)/")
    if s then
        channel = string.sub(ngx.var.uri, s + 1, e - 1)
    else
        channel = ngx.var.uri:match("/(%d+)%.m3u8") or "0"
    end
    if channel == "0" then
        local args = ngx.req.get_uri_args()
        if args.ch then
            channel = tostring(args.ch)
            ngx.log(ngx.INFO, "[ARCHIVE] Channel extracted from ch= parameter: ", channel)
        end
    end
end

-- === Blacklist check ===
if channel ~= "0" then
    local disabled_json = red:hget("config:chanmap", "disabled")
    if disabled_json and disabled_json ~= ngx.null then
        local ok, disabled_data = pcall(cjson.decode, disabled_json)
        local disabled_str = ""
        if ok and type(disabled_data) == "table" and disabled_data.id then
            disabled_str = tostring(disabled_data.id)
        elseif type(disabled_json) == "string" then
            disabled_str = disabled_json:gsub('"', '')
        end

        if disabled_str ~= "" then
            for disabled_ch in disabled_str:gmatch("([^,]+)") do
                local trim_ch = disabled_ch:match("^%s*(.-)%s*$")
                if trim_ch == channel then
                    ngx.log(ngx.WARN, "[ACCESS_DENIED] Channel ", channel, " is globally disabled")
                    red:set_keepalive(10000, 50)
                    ngx.status = 403
                    ngx.header["Content-Type"] = "application/vnd.apple.mpegurl"
                    ngx.say("#EXTM3U\n#EXT-X-ERROR: Channel is currently disabled by administrator.\n#EXT-X-ENDLIST")
                    return ngx.exit(403)
                end
            end
        end
    end
end

-- === Session info ===
local user_agent = ngx.req.get_headers()["User-Agent"] or "unknown"
local client_ip  = ngx.var.remote_addr
local session_id = ngx.md5(token .. client_ip .. user_agent)
local now        = ngx.time()

-- === Archive switch: cleanup old channel allocation ===
if utc and channel ~= "0" then
    local meta_json = red:hget("online:users:" .. token .. ":meta", session_id)
    if meta_json and meta_json ~= ngx.null then
        local ok, meta = pcall(cjson.decode, meta_json)
        if ok and meta and meta.channel and tostring(meta.channel) ~= tostring(channel) then
            local old_channel = meta.channel
            ngx.log(ngx.INFO, "[ARCHIVE] User switched from channel ", old_channel, " to ", channel)

            local archive_last_seen = red:get("archive_last_seen:" .. old_channel)
            local archive_active = (archive_last_seen ~= nil and archive_last_seen ~= ngx.null)

            if not archive_active then
                local others = find_other_sessions(red, old_channel, nil)
                if not others.found then
                    ngx.log(ngx.INFO, "[ARCHIVE] No active sessions on channel ", old_channel,
                            ", clearing allocation")
                    red:hdel("channel_allocations", old_channel)
                    red:hdel("archive_tvclub_url", old_channel)
                else
                    ngx.log(ngx.INFO, "[ARCHIVE] LIVE session active on channel ", old_channel,
                            ", keeping allocation")
                end
            else
                ngx.log(ngx.INFO, "[ARCHIVE] Archive still active on channel ", old_channel)
            end
        end
    end
end

-- === Legacy device lock ===
local legacy_key = "blocked:devices:" .. token
local lock_key   = "ban:lock:" .. token .. ":" .. session_id
if red:sismember(legacy_key, session_id) == 1 then
    local locked_ch = red:get(lock_key)
    if locked_ch == ngx.null then
        red:setex(lock_key, 3600, channel)
        locked_ch = channel
    end
    if tostring(locked_ch) == tostring(channel) or locked_ch == "all" then
        return serve_stub(red, "black", 200)
    else
        red:srem(legacy_key, session_id)
        red:del(lock_key)
    end
end

-- === User status check ===
local user_status = red:get("user:" .. token .. ":status")
local user_expire = red:get("user:" .. token .. ":expire")
local exp_time    = tonumber(user_expire)

if tostring(user_status) == "blocked" then
    ngx.log(ngx.ERR, "[AUTH] USER BLOCKED: token=", token)
    return serve_stub(red, "ban", 200)
end
if tostring(user_status) ~= "active" or not exp_time or exp_time < now then
    ngx.log(ngx.WARN, "[AUTH] USER EXPIRED/INACTIVE: status=", user_status, " expire=", exp_time)
    return serve_stub(red, "splash", 200)
end
ngx.log(ngx.INFO, "[AUTH] USER ACTIVE: token=", token)

-- === Device limit + heartbeat (Lua-скрипт в Redis) ===
-- ДОБАВЛЕНО: SADD 'online:tokens' — индекс-множество для быстрого поиска сессий
local limit_script = [[
    local key_user = KEYS[1]
    local key_channel_online = KEYS[2]
    local key_channel_daily = KEYS[3]
    local key_channels_list = KEYS[4]
    local key_history = 'history:users:' .. ARGV[6]
    local key_meta = key_user .. ':meta'
    local session_id = ARGV[1]
    local limit = tonumber(ARGV[2])
    local now = tonumber(ARGV[3])
    local ttl = tonumber(ARGV[4])
    local token = ARGV[6]
    local channel_id = ARGV[7]
    local current_ua = ARGV[8]
    local deadline = now - ttl
    local all_sessions = redis.call('HKEYS', key_meta)
    local active_count = 0
    if #all_sessions > 0 then
        for _, sess in ipairs(all_sessions) do
            local score = redis.call('ZSCORE', key_user, sess)
            if not score or tonumber(score) < deadline then
                redis.call('HDEL', key_meta, sess)
                redis.call('ZREM', key_user, sess)
            else active_count = active_count + 1 end
        end
    end
    local start_time = now
    local meta_str = redis.call('HGET', key_meta, session_id) or redis.call('HGET', key_history, session_id)
    if meta_str then
        local status, old_data = pcall(cjson.decode, meta_str)
        if status and old_data and tostring(old_data.channel) == tostring(channel_id) then
            if (now - (tonumber(old_data.last_seen) or 0)) <= 60 then
                start_time = tonumber(old_data.start) or now
            end
        end
    end
    if not redis.call('ZSCORE', key_user, session_id) and active_count >= limit then
        local replaced = false
        local all_meta = redis.call('HGETALL', key_meta)
        for i = 1, #all_meta, 2 do
            local old_sess = all_meta[i]
            local old_meta_json = all_meta[i + 1]
            if old_sess ~= session_id and old_meta_json then
                local ok, old_data = pcall(cjson.decode, old_meta_json)
                if ok and old_data and old_data.ua == current_ua then
                    redis.call('HDEL', key_meta, old_sess)
                    redis.call('ZREM', key_user, old_sess)
                    replaced = true
                    break
                end
            end
        end
        if not replaced then return 0 end
    end
    local final_meta = {ip=ARGV[9], server=ARGV[10], channel=channel_id, start=start_time, last_seen=now, ua=current_ua}
    local new_meta_json = cjson.encode(final_meta)
    redis.call('ZADD', key_user, now, session_id)
    redis.call('HSET', key_meta, session_id, new_meta_json)
    redis.call('EXPIRE', key_user, ttl * 3)
    redis.call('EXPIRE', key_meta, ttl * 3)
    redis.call('HSET', key_history, session_id, new_meta_json)
    redis.call('EXPIRE', key_history, 604800)
    redis.call('ZREMRANGEBYSCORE', key_channel_online, 0, deadline)
    redis.call('ZADD', key_channel_online, now, token)
    redis.call('EXPIRE', key_channel_online, ttl)
    redis.call('PFADD', key_channel_daily, token)
    redis.call('EXPIRE', key_channel_daily, 172800)
    redis.call('SADD', key_channels_list, channel_id)
    -- ИНДЕКС-МНОЖЕСТВО: добавляем токен для быстрого поиска сессий (O(1) vs O(N))
    redis.call('SADD', 'online:tokens', token)
    redis.call('EXPIRE', 'online:tokens', 172800)
    return 1
]]

local res = red:eval(limit_script, 4,
    "online:users:" .. token,
    "stats:online:channel:" .. channel,
    "stats:daily:" .. os.date("%Y-%m-%d") .. ":channel:" .. channel,
    "stats:channels_list",
    session_id, MAX_DEVICES, now, HEARTBEAT_TTL,
    "meta_placeholder", token, channel, user_agent, client_ip,
    ngx.var.server_addr or "balancer")

if res == 0 then
    return serve_stub(red, "limit", 200)
end

-- ============================================================================
-- === 5. ARCHIVE PROCESSING ===
-- ============================================================================
if utc and (string.find(ngx.var.uri, "%.m3u8$")) then
    ngx.log(ngx.INFO, "[ARCHIVE] === START === channel=", channel,
            " utc=", utc, " lutc=", lutc or "nil")

    local target_cdn  = nil
    local source_url  = nil
    local final_alloc = nil
    local config      = get_cdn_config(red)

    -- === ПРИОРИТЕТ 1: archive_history ===
    local cached_cdn = red:hget("archive_history", channel)
    if cached_cdn and cached_cdn ~= ngx.null then
        if config[cached_cdn] and config[cached_cdn].active ~= false then
            target_cdn = cached_cdn
            ngx.log(ngx.INFO, "[ARCHIVE] Using archive_history CDN=", target_cdn)

            local existing_alloc_json = red:hget("channel_allocations", channel)
            if existing_alloc_json and existing_alloc_json ~= ngx.null then
                local ok_e, existing_alloc = pcall(cjson.decode, existing_alloc_json)
                if ok_e and existing_alloc and existing_alloc.provider then

                    if existing_alloc.provider == "direct_url" then
                        ngx.log(ngx.INFO, "[ARCHIVE] LIVE used direct_url. Checking archive_tvclub_url...")

                        local tvclub_alloc_json = red:hget("archive_tvclub_url", channel)
                        if tvclub_alloc_json and tvclub_alloc_json ~= ngx.null then
                            local ok_t, tvclub_alloc = pcall(cjson.decode, tvclub_alloc_json)
                            if ok_t and tvclub_alloc and tvclub_alloc.provider then
                                target_cdn = tvclub_alloc.cdn_ip
                                source_url = tvclub_alloc.source_url
                                ngx.log(ngx.INFO, "[ARCHIVE] Reusing existing archive tvclub slot: ", tvclub_alloc.slot)
                                goto archive_slot_found
                            end
                        end

                        ngx.log(ngx.INFO, "[ARCHIVE] Allocating NEW tvclub slot for archive...")
                        local prov, idx, cdn_ip, t_item, alloc = select_cdn_slot(red, channel, user_agent)
                        if prov and cdn_ip and alloc and alloc.source_url then
                            target_cdn = cdn_ip
                            source_url = alloc.source_url

                            local tvclub_alloc = {
                                provider     = prov,
                                slot         = idx,
                                cdn_ip       = cdn_ip,
                                source_url   = source_url,
                                token        = alloc.token,
                                allocated_at = ngx.time(),
                            }
                            red:hset("archive_tvclub_url", channel, cjson.encode(tvclub_alloc))
                            ngx.log(ngx.INFO, "[ARCHIVE] Allocated tvclub slot for archive: provider=",
                                    prov, " slot=", idx, " CDN=", cdn_ip)
                            goto archive_slot_found
                        end
                    end

                    target_cdn = existing_alloc.cdn_ip
                    source_url = existing_alloc.source_url
                    ngx.log(ngx.INFO, "[ARCHIVE] Reusing existing slot: provider=",
                            existing_alloc.provider, " slot=", existing_alloc.slot, " CDN=", target_cdn)
                    goto archive_slot_found
                end
            end

            local chan_info = get_channel_info(red, channel)
            if chan_info and chan_info.url then
                source_url = chan_info.url
                ngx.log(ngx.INFO, "[ARCHIVE] source_url from chanmap: ", source_url)

                if not existing_alloc_json or existing_alloc_json == ngx.null then
                    ngx.log(ngx.INFO, "[ARCHIVE] channel_allocations missing, allocating new slot...")
                    local prov, idx, cdn_ip, t_item, alloc = select_cdn_slot(red, channel, user_agent)
                    if prov and cdn_ip and alloc and alloc.source_url then
                        if cached_cdn and cached_cdn ~= cdn_ip then
                            ngx.log(ngx.INFO, "[ARCHIVE] Using archive_history CDN=", cached_cdn,
                                    " instead of slot CDN=", cdn_ip)
                            cdn_ip = cached_cdn
                        end
                        target_cdn  = cdn_ip
                        source_url  = alloc.source_url
                        final_alloc = alloc
                        local archive_alloc = {
                            provider     = alloc.provider,
                            slot         = alloc.slot,
                            cdn_ip       = cdn_ip,
                            source_url   = source_url,
                            token        = alloc.token,
                            is_archive   = true,
                            is_live      = false,
                            allocated_at = ngx.time(),
                        }
                        red:hset("channel_allocations", channel, cjson.encode(archive_alloc))
                        ngx.log(ngx.INFO, "[ARCHIVE] Allocated new slot: provider=", prov,
                                " slot=", idx, " CDN=", cdn_ip)
                        goto archive_slot_found
                    end
                end

                if chan_info.url:match("/iptv/") then
                    local tvclub_alloc_json = red:hget("archive_tvclub_url", channel)
                    if tvclub_alloc_json and tvclub_alloc_json ~= ngx.null then
                        local ok_t, tvclub_alloc = pcall(cjson.decode, tvclub_alloc_json)
                        if ok_t and tvclub_alloc then
                            local archive_last_seen = red:get("archive_last_seen:" .. channel)
                            local archive_active = (archive_last_seen ~= nil and archive_last_seen ~= ngx.null)

                            if archive_active then
                                target_cdn = tvclub_alloc.cdn_ip
                                source_url = tvclub_alloc.source_url
                                ngx.log(ngx.INFO, "[ARCHIVE] Reusing existing tvclub URL for channel=",
                                        channel, " slot=", tvclub_alloc.slot)
                                goto archive_slot_found
                            else
                                local others = find_other_sessions(red, channel, nil)
                                if not others.found then
                                    ngx.log(ngx.INFO, "[ARCHIVE] No active sessions on channel ",
                                            channel, ", clearing tvclub URL")
                                    red:hdel("archive_tvclub_url", channel)
                                else
                                    target_cdn = tvclub_alloc.cdn_ip
                                    source_url = tvclub_alloc.source_url
                                    ngx.log(ngx.INFO, "[ARCHIVE] Reusing tvclub URL (LIVE active) for channel=", channel)
                                    goto archive_slot_found
                                end
                            end
                        end
                    end

                    ngx.log(ngx.INFO, "[ARCHIVE] direct_url detected, allocating tvclub slot for archive...")
                    local prov, idx, cdn_ip, t_item, alloc = select_cdn_slot(red, channel, user_agent)
                    if prov and cdn_ip and alloc and alloc.source_url then
                        target_cdn = cdn_ip
                        source_url = alloc.source_url
                        local tvclub_alloc = {
                            provider     = prov,
                            slot         = idx,
                            cdn_ip       = cdn_ip,
                            source_url   = source_url,
                            token        = alloc.token,
                            allocated_at = ngx.time(),
                        }
                        red:hset("archive_tvclub_url", channel, cjson.encode(tvclub_alloc))
                        ngx.log(ngx.INFO, "[ARCHIVE] Allocated tvclub slot: provider=", prov,
                                " slot=", idx, " CDN=", cdn_ip)
                        goto archive_slot_found
                    end
                end

                local archive_alloc = {
                    provider     = "direct_url",
                    slot         = 0,
                    cdn_ip       = target_cdn,
                    source_url   = source_url,
                    token        = "",
                    allocated_at = ngx.time(),
                }
                red:hset("channel_allocations", channel, cjson.encode(archive_alloc))
            end

            if not source_url then
                ngx.log(ngx.INFO, "[ARCHIVE] chanmap.url is nil, allocating slot for archive...")
                local prov, idx, cdn_ip, t_item, alloc = select_cdn_slot(red, channel, user_agent)
                if prov and cdn_ip and alloc and alloc.source_url then
                    target_cdn = cdn_ip
                    source_url = alloc.source_url
                    local archive_alloc = {
                        provider     = prov,
                        slot         = idx,
                        cdn_ip       = cdn_ip,
                        source_url   = source_url,
                        token        = alloc.token,
                        allocated_at = ngx.time(),
                    }
                    red:hset("channel_allocations", channel, cjson.encode(archive_alloc))
                    ngx.log(ngx.INFO, "[ARCHIVE] Allocated slot: provider=", prov,
                            " CDN=", target_cdn, " slot=", idx)
                end
            end

            ::archive_slot_found::
        else
            red:hdel("archive_history", channel)
        end
    end

    -- === ПРИОРИТЕТ 2: LIVE CDN ===
    local live_cdn, live_source = nil, nil
    local alloc_json = red:hget("channel_allocations", channel)
    if alloc_json and alloc_json ~= ngx.null then
        local ok_a, alloc = pcall(cjson.decode, alloc_json)
        if ok_a and alloc then
            live_cdn    = alloc.cdn_ip
            live_source = alloc.source_url
            ngx.log(ngx.INFO, "[ARCHIVE] LIVE exists on CDN=", live_cdn)
        end
    end

    if not target_cdn and live_cdn then
        target_cdn = live_cdn
        source_url = live_source
        red:hset("archive_history", channel, target_cdn)
        red:expire("archive_history", 3600)
        ngx.log(ngx.INFO, "[ARCHIVE] Using LIVE CDN for archive: ", target_cdn)
    end

    -- === ПРИОРИТЕТ 3: select_cdn_slot ===
    if not target_cdn then
        ngx.log(ngx.INFO, "[ARCHIVE] === ENTERING PRIORITY 3: select_cdn_slot === channel=", channel)

        cleanup_stale_archive(red, channel)

        local prov, idx, cdn_ip, t_item, alloc = select_cdn_slot(red, channel, user_agent)
        if prov and cdn_ip and alloc and alloc.source_url then
            target_cdn  = cdn_ip
            source_url  = alloc.source_url
            final_alloc = alloc
            red:hset("archive_history", channel, target_cdn)
            red:expire("archive_history", 3600)
            red:setex("archive_last_seen:" .. channel, 600, tostring(ngx.time()))
            ngx.log(ngx.INFO, "[ARCHIVE] Allocated slot: provider=", prov,
                    " CDN=", target_cdn, " slot=", idx)
        else
            ngx.log(ngx.WARN, "[ARCHIVE] Failed to allocate slot (limit reached?)")
        end
    end

    -- === ПРИОРИТЕТ 4: consistent hash fallback ===
    if not target_cdn then
        target_cdn = get_archive_cdn(red, channel, config, nil)
        ngx.log(ngx.INFO, "[ARCHIVE] Using consistent hash CDN=", target_cdn)

        local chan_info = get_channel_info(red, channel)
        if chan_info and chan_info.url then
            source_url = chan_info.url
            ngx.log(ngx.INFO, "[ARCHIVE] source_url from chanmap: ", source_url)
        end

        if target_cdn then
            red:hset("archive_history", channel, target_cdn)
            red:expire("archive_history", 3600)
        end
    end

    -- === Archive redirect ===
    if target_cdn and source_url then
        local sep = string.find(source_url, "?") and "&" or "?"
        local final_p_url = source_url .. sep .. "utc=" .. utc .. (lutc and "&lutc=" .. lutc or "")

        local existing_alloc_json = red:hget("channel_allocations", channel)
        if existing_alloc_json and existing_alloc_json ~= ngx.null then
            local ok_e, existing_alloc = pcall(cjson.decode, existing_alloc_json)
            if ok_e and existing_alloc then
                existing_alloc.is_archive = true
                if existing_alloc.is_live == nil then
                    existing_alloc.is_live = false
                end
                red:hset("channel_allocations", channel, cjson.encode(existing_alloc))
                ngx.log(ngx.INFO, "[ARCHIVE] Updated allocation: is_archive=true")
            end
        else
            local live_source_url = source_url
            local live_provider   = final_alloc and final_alloc.provider or "tvclub"
            local live_slot       = final_alloc and final_alloc.slot or 0

            local chan_info = get_channel_info(red, channel)
            if chan_info and chan_info.url then
                live_source_url = chan_info.url
                live_provider   = "direct_url"
                live_slot       = 0
            end

            local archive_alloc = {
                provider     = live_provider,
                slot         = live_slot,
                cdn_ip       = target_cdn,
                source_url   = live_source_url,
                token        = final_alloc and final_alloc.token or "",
                is_archive   = true,
                is_live      = false,
                allocated_at = ngx.time(),
            }
            red:hset("channel_allocations", channel, cjson.encode(archive_alloc))
            ngx.log(ngx.INFO, "[ARCHIVE] Created safe LIVE placeholder: provider=",
                    archive_alloc.provider, " slot=", archive_alloc.slot)
        end

        local redirect_url = "http://" .. target_cdn .. ":8123/archive_proxy/index.m3u8?token="
                           .. token .. "&ch=" .. channel .. "&p_url=" .. ngx.escape_uri(final_p_url)
        ngx.log(ngx.INFO, "[ARCHIVE] 301 Redirect to: ", redirect_url)

        red:setex("archive_fallback:" .. token .. ":" .. channel, 300, target_cdn)
        red:set_keepalive(10000, 50)
        return ngx.redirect(redirect_url, 301)
    else
        ngx.log(ngx.ERR, "[ARCHIVE] FAILED: No target_cdn or source_url")
    end
end

-- ============================================================================
-- === ANTI-ZAPPING ===
-- ============================================================================
local key_zap_ban        = "zap:ban:" .. session_id
local key_violations     = "zap:violations:" .. session_id
local key_last_violation = "zap:last_violation:" .. session_id
local key_device_info    = "zap:device:" .. session_id

local device_info = cjson.encode({
    token = token, ip = client_ip, user_agent = user_agent,
    channel = channel, last_seen = now,
})
red:setex(key_device_info, 3600, device_info)

if red:exists(key_zap_ban) == 1 then
    local ban_time_left = red:ttl(key_zap_ban)
    ngx.log(ngx.WARN, "[ZAPPING] DEVICE BANNED: session_id=", session_id,
            " time_left=", ban_time_left, "s")
    red:set_keepalive(10000, 50)
    ngx.status = 429
    ngx.header["Retry-After"]   = tostring(ban_time_left > 0 and ban_time_left or ZAP_BAN_TIME)
    ngx.header["Content-Type"]  = "application/vnd.apple.mpegurl"
    ngx.say("#EXTM3U\n#EXT-X-ERROR: Zapping limit. Wait " ..
            (ban_time_left > 0 and ban_time_left or ZAP_BAN_TIME) .. "s.\n#EXT-X-ENDLIST")
    return ngx.exit(429)
end

local last_violation = tonumber(red:get(key_last_violation)) or 0
if last_violation > 0 and (now - last_violation) > ZAP_VIOLATIONS_RESET then
    local current_violations = tonumber(red:get(key_violations)) or 0
    if current_violations > 0 then
        ngx.log(ngx.INFO, "[ZAPPING] Resetting violations for device session_id=", session_id)
        red:del(key_violations, key_last_violation)
    end
end

local zap_script = [[
    local k_last = KEYS[1]
    local k_burst = KEYS[2]
    local k_sust = KEYS[3]
    local ch = ARGV[1]
    local w_burst = tonumber(ARGV[2])
    local w_sust = tonumber(ARGV[3])
    local last_ch = redis.call('GET', k_last)
    if last_ch == ch then
        return {0, 0, 0}
    end
    redis.call('SETEX', k_last, w_sust * 2, ch)
    local burst = redis.call('INCR', k_burst)
    if burst == 1 then redis.call('EXPIRE', k_burst, w_burst) end
    local sust = redis.call('INCR', k_sust)
    if sust == 1 then redis.call('EXPIRE', k_sust, w_sust) end
    return { 1, burst, sust}
]]

local k_last  = "zap:last:"  .. session_id
local k_burst = "zap:burst:" .. session_id
local k_sust  = "zap:sust:"  .. session_id
local zap_res, err = red:eval(zap_script, 3, k_last, k_burst, k_sust,
                              channel, ZAP_BURST_WINDOW, ZAP_WINDOW)

if zap_res and zap_res[1] == 1 then
    local burst_count = zap_res[2]
    local sust_count  = zap_res[3]
    ngx.log(ngx.INFO, "[ZAPPING] Switch. Burst: ", burst_count, "/", ZAP_BURST_MAX,
            " Sustained: ", sust_count, "/", ZAP_MAX_SWITCHES, " sess=", session_id)

    if burst_count > ZAP_BURST_MAX or sust_count > ZAP_MAX_SWITCHES then
        local violations = tonumber(red:incr(key_violations)) or 1
        red:expire(key_violations, 3600)
        red:setex(key_last_violation, ZAP_VIOLATIONS_RESET * 2, tostring(now))

        local ban_duration = ZAP_BAN_TIME
        if violations >= 3 then
            ban_duration = ZAP_BAN_TIME_REPEAT * 4
            ngx.log(ngx.WARN, "[ZAPPING] 3rd violation - 8 min ban for session_id=", session_id)
        elseif violations >= 2 then
            ban_duration = ZAP_BAN_TIME_REPEAT
            ngx.log(ngx.WARN, "[ZAPPING] 2nd violation - 2 min ban for session_id=", session_id)
        else
            ngx.log(ngx.WARN, "[ZAPPING] 1st violation - 1 min ban for session_id=", session_id)
        end

        red:setex(key_zap_ban, ban_duration, "1")
        red:del(k_burst, k_sust)

        if violations >= ZAP_MAX_VIOLATIONS then
            ngx.log(ngx.ERR, "[ZAPPING] MAX VIOLATIONS REACHED: session_id=", session_id,
                    " BLOCKING DEVICE")
            local d_info = cjson.encode({
                token = token, ip = client_ip, user_agent = user_agent,
                channel = channel, violations = violations, last_violation = now,
            })
            red:sadd("blocked:devices:" .. token, session_id)
            red:setex("blocked:devices:" .. token .. ":info:" .. session_id, 86400, d_info)
            red:setex("blocked:devices:" .. token .. ":reason:" .. session_id, 86400,
                      "anti-zapping limit reached")
        end

        red:set_keepalive(10000, 50)
        ngx.status = 429
        ngx.header["Retry-After"]  = tostring(ban_duration)
        ngx.header["Content-Type"] = "application/vnd.apple.mpegurl"
        ngx.say("#EXTM3U\n#EXT-X-ERROR: Too many channel switches. Banned for " ..
                ban_duration .. "s.\n#EXT-X-ENDLIST")
        return ngx.exit(429)
    end
end

-- ============================================================================
-- === FAST ZAPPING / HOT SWITCH ===
-- ============================================================================
local key_session_channel = "session:channel:" .. session_id
local key_session_token_ip = "session:token_ip:" .. token .. ":" .. client_ip
local prev_channel = red:get(key_session_channel)

-- Миграция session_id при смене User-Agent
local prev_session_id = red:get(key_session_token_ip)
if prev_session_id and prev_session_id ~= ngx.null and prev_session_id ~= session_id then
    ngx.log(ngx.INFO, "[FAST_ZAP] session_id changed: ", prev_session_id, " → ", session_id)
    local old_channel = red:get("session:channel:" .. prev_session_id)
    if old_channel and old_channel ~= ngx.null then
        red:setex("session:channel:" .. session_id, 30, old_channel)
        ngx.log(ngx.INFO, "[FAST_ZAP] Migrated channel data: ", old_channel)

        local alloc_json = red:hget("channel_allocations", old_channel)
        if alloc_json and alloc_json ~= ngx.null then
            local ok_a, alloc = pcall(cjson.decode, alloc_json)
            if ok_a and alloc and alloc.owner_session_id == prev_session_id then
                alloc.owner_session_id = session_id
                red:hset("channel_allocations", old_channel, cjson.encode(alloc))
                ngx.log(ngx.INFO, "[FAST_ZAP] Updated owner_session_id in allocation")
            end
        end
    end
    red:del("session:channel:" .. prev_session_id)
    prev_channel = old_channel
end

red:setex(key_session_token_ip, 60, session_id)

-- Переключение канала
if prev_channel and prev_channel ~= ngx.null and prev_channel ~= channel then
    ngx.log(ngx.INFO, "[FAST_ZAP] Device switched: ", prev_channel, " → ", channel,
            " session=", session_id)

    local prev_alloc_key  = prev_channel
    local prev_alloc_json = red:hget("channel_allocations", prev_channel)

    -- Chain hot switch: reverse mapping
    if not (prev_alloc_json and prev_alloc_json ~= ngx.null) then
        local alloc_key = red:get("session:alloc_key:" .. session_id)
        if alloc_key and alloc_key ~= ngx.null then
            local existing_json = red:hget("channel_allocations", alloc_key)
            if existing_json and existing_json ~= ngx.null then
                local ok_e, existing = pcall(cjson.decode, existing_json)
                if ok_e and existing
                   and tostring(existing.current_channel) == tostring(prev_channel)
                   and existing.owner_session_id == session_id then
                    prev_alloc_json = existing_json
                    prev_alloc_key  = alloc_key
                    ngx.log(ngx.INFO, "[FAST_ZAP] Found allocation via reverse mapping: alloc_key=", alloc_key)
                end
            end
        end
    end

    if prev_alloc_json and prev_alloc_json ~= ngx.null then
        local ok_p, prev_alloc = pcall(cjson.decode, prev_alloc_json)
        if ok_p and prev_alloc then

            if prev_alloc.dying == true then
                ngx.log(ngx.INFO, "[FAST_ZAP] Previous allocation DYING for channel ",
                        prev_channel, " → allocating new slot")
                goto normal_allocation
            end

            local is_owner = (prev_alloc.owner_session_id == session_id)
            if not is_owner then
                ngx.log(ngx.INFO, "[FAST_ZAP] session ", session_id,
                        " is NOT owner of channel ", prev_channel, " — normal allocation")
                goto normal_allocation
            end

            ngx.log(ngx.INFO, "[FAST_ZAP] session ", session_id, " is OWNER, can do HOT SWITCH")

            if not prev_alloc.is_live then
                ngx.log(ngx.INFO, "[FAST_ZAP] Proxy NOT LIVE for channel ", prev_channel,
                        " — normal allocation")
                goto normal_allocation
            end

            if prev_alloc.is_archive then
                ngx.log(ngx.INFO, "[FAST_ZAP] Archive active on channel ", prev_channel,
                        " — normal allocation")
                goto normal_allocation
            end

            -- === Transfer ownership or HOT SWITCH ===
            -- ОДИН вызов find_other_sessions вместо ДВУХ red:keys
            local others = find_other_sessions(red, prev_channel, session_id)
            if others.found then
                ngx.log(ngx.INFO, "[FAST_ZAP] Other sessions exist on channel ", prev_channel,
                        ", transferring ownership")
                if others.new_owner then
                    prev_alloc.owner_session_id = others.new_owner
                    red:hset("channel_allocations", prev_alloc_key, cjson.encode(prev_alloc))
                    ngx.log(ngx.INFO, "[FAST_ZAP] Ownership transferred to ", others.new_owner)
                end
                goto normal_allocation
            end

            ngx.log(ngx.INFO, "[FAST_ZAP] No other sessions, proceeding with HOT SWITCH")

            -- Можем ли переиспользовать слот?
            if prev_alloc.provider ~= "direct_url" then
                local chan_info = get_channel_info(red, channel)
                local can_reuse = true

                if chan_info then
                    if chan_info.url then
                        can_reuse = false
                        ngx.log(ngx.INFO, "[FAST_ZAP_ABORT] Channel ", channel,
                                " has direct URL. Hot switch aborted.")
                    end
                    if chan_info.provider and chan_info.provider ~= prev_alloc.provider then
                        can_reuse = false
                        ngx.log(ngx.INFO, "[FAST_ZAP_ABORT] Channel ", channel,
                                " strictly requires provider '", chan_info.provider, "'.")
                    end
                    if prev_alloc.provider == "tvclub" then
                        if chan_info.admuspeh or chan_info.elbrus then
                            can_reuse = false
                            ngx.log(ngx.INFO, "[FAST_ZAP_ABORT] Channel ", channel,
                                    " belongs to higher priority provider.")
                        end
                    end
                    if prev_alloc.provider == "elbrus" and not chan_info.elbrus then
                        can_reuse = false
                        ngx.log(ngx.INFO, "[FAST_ZAP_ABORT] Channel ", channel,
                                " lacks elbrus path.")
                    end
                end

                if can_reuse then
                    ngx.log(ngx.INFO, "[FAST_ZAP_INIT] Attempting HOT SWITCH: Old_Ch=",
                            prev_channel, " -> New_Ch=", channel,
                            " | Retaining Provider=", prev_alloc.provider,
                            " Slot=", prev_alloc.slot)

                    local providers = get_providers(red)  -- Из Lua-кэша
                    local new_source_url = nil

                    for _, provider in ipairs(providers) do
                        if provider.name == prev_alloc.provider then
                            local items = provider.cfg.tokens or provider.cfg.bases or {}
                            local item  = items[prev_alloc.slot + 1]
                            local base_url = type(item) == "table" and item.url or item

                            if prev_alloc.provider == "tvclub" then
                                new_source_url = resolve_tvclub(channel, base_url)
                            elseif prev_alloc.provider == "admuspeh" then
                                local ch_id = chan_info and (chan_info.admuspeh or chan_info.id)
                                new_source_url = resolve_admuspeh(ch_id, base_url)
                            elseif prev_alloc.provider == "elbrus" then
                                local ch_path = chan_info and chan_info.elbrus
                                new_source_url = resolve_elbrus(ch_path, base_url, provider.cfg.origin)
                            else
                                new_source_url = urljoin(base_url, channel .. "/index.m3u8")
                            end
                            break
                        end
                    end

                    if new_source_url then
                        local target_lock_key = "lock:alloc:" .. channel
                        local lock_acquired = red:set(target_lock_key, "1", "EX", 3, "NX")

                        if lock_acquired ~= "OK" then
                            ngx.log(ngx.ERR, "[FAST_ZAP_RACE] Target channel ", channel,
                                    " is locked by another process! Aborting HOT SWITCH.")
                            goto normal_allocation
                        end

                        local target_alloc_json = red:hget("channel_allocations", channel)
                        if target_alloc_json and target_alloc_json ~= ngx.null then
                            local ok_t, target_alloc = pcall(cjson.decode, target_alloc_json)
                            if ok_t and target_alloc and target_alloc.cdn_ip then
                                if target_alloc.dying == true then
                                    ngx.log(ngx.INFO, "[FAST_ZAP] Target channel ", channel,
                                            " has DYING allocation, ignoring for HOT SWITCH")
                                else
                                    ngx.log(ngx.INFO, "[FAST_ZAP_ABORT] Target channel ", channel,
                                            " already active (Slot ", target_alloc.slot,
                                            "). Joining existing stream.")
                                    target_alloc.is_live = true
                                    if target_alloc.is_archive == nil then
                                        target_alloc.is_archive = false
                                    end
                                    red:hset("channel_allocations", channel, cjson.encode(target_alloc))
                                    red:setex(key_session_channel, 30, channel)
                                    red:del(target_lock_key)

                                    red:set_keepalive(10000, 50)
                                    ngx.header["Content-Type"] = "application/vnd.apple.mpegurl"
                                    ngx.say("#EXTM3U\n#EXT-X-VERSION:3\n#EXT-X-STREAM-INF:BANDWIDTH=20000000\n" ..
                                            "http://" .. target_alloc.cdn_ip .. ":8123/" .. channel ..
                                            "/playlist.m3u8" .. token_suffix)
                                    return ngx.exit(200)
                                end
                            end
                        end

                        -- Выполняем HOT SWITCH
                        local old_slot_memory  = prev_alloc.slot
                        local old_token_memory = prev_alloc.token

                        prev_alloc.source_url        = new_source_url
                        prev_alloc.current_channel   = channel
                        prev_alloc.is_live           = true
                        prev_alloc.is_archive        = false
                        prev_alloc.last_switch_at    = ngx.time()
                        prev_alloc.owner_session_id  = session_id
                        if not prev_alloc.root_id then prev_alloc.root_id = prev_alloc_key end
                        prev_alloc.switch_count = (prev_alloc.switch_count or 0) + 1

                        local move_alloc_script = [[
                            local existing = redis.call('HGET', 'channel_allocations', KEYS[2])
                            if existing then
                                local ok, alloc = pcall(cjson.decode, existing)
                                if not ok or not alloc or not alloc.dying then
                                    return 0
                                end
                            end
                            redis.call('HDEL', 'channel_allocations', KEYS[1])
                            redis.call('HSET', 'channel_allocations', KEYS[2], ARGV[1])
                            return 1
                        ]]
                        local move_result = red:eval(move_alloc_script, 2, prev_alloc_key,
                                                     channel, cjson.encode(prev_alloc))

                        if move_result == 0 then
                            ngx.log(ngx.ERR, "[FAST_ZAP_RACE] Redis EVAL failed for channel ",
                                    channel, " (target created during check). Aborting.")
                            red:del(target_lock_key)
                            goto normal_allocation
                        end

                        red:del(target_lock_key)
                        prev_alloc_key = channel
                        red:setex("session:alloc_key:" .. session_id, 300, channel)

                        ngx.log(ngx.INFO, "[FAST_ZAP_SUCCESS] Sending SWITCH | Old_Ch: ",
                                prev_channel, " -> New_Ch: ", channel,
                                " | Locked Slot: ", old_slot_memory)

                        if (ngx.time() - (prev_alloc.allocated_at or 0)) < 3 then
                            ngx.sleep(0.8)
                        end

                        red:publish("channel_control", cjson.encode({
                            action         = "SWITCH",
                            channel        = prev_channel,
                            new_channel    = channel,
                            new_source_url = new_source_url,
                            session_id     = session_id,
                            provider       = prev_alloc.provider,
                            slot           = old_slot_memory,
                            new_token      = old_token_memory,
                            allocated_at   = prev_alloc.allocated_at,
                        }))

                        red:setex(key_session_channel, 30, channel)
                        red:set_keepalive(10000, 50)
                        ngx.header["Content-Type"] = "application/vnd.apple.mpegurl"
                        ngx.say("#EXTM3U\n#EXT-X-VERSION:3\n#EXT-X-STREAM-INF:BANDWIDTH=20000000\n" ..
                                "http://" .. prev_alloc.cdn_ip .. ":8123/" .. channel ..
                                "/playlist.m3u8?token=" .. token)
                        return ngx.exit(200)
                    end
                end
            end
        end
    end
end

::normal_allocation::
red:setex(key_session_channel, 30, channel)

-- ============================================================================
-- === LIVE ALLOCATION ===
-- ============================================================================
local alloc_json       = red:hget("channel_allocations", channel)
local alloc_stored_key = channel
local allocation       = nil

-- Reverse lookup: аллокация может быть под другим ключом после hot switch
if not (alloc_json and alloc_json ~= ngx.null) then
    local redirect = red:get("session:alloc_key:" .. session_id)
    if redirect and redirect ~= ngx.null then
        local existing_json = red:hget("channel_allocations", redirect)
        if existing_json and existing_json ~= ngx.null then
            local ok_r, existing = pcall(cjson.decode, existing_json)
            if ok_r and existing
               and tostring(existing.current_channel) == tostring(channel)
               and existing.owner_session_id == session_id then
                alloc_json       = existing_json
                alloc_stored_key = redirect
                ngx.log(ngx.WARN, "[HOT_SWITCH_REUSE] Restored allocation via session key. Key=", redirect)
            end
        end
    end
end

if alloc_json and alloc_json ~= ngx.null then
    local ok_a, res_alloc = pcall(cjson.decode, alloc_json)
    if ok_a and res_alloc and res_alloc.cdn_ip then
        if res_alloc.dying == true then
            ngx.log(ngx.ERR, "[ALLOC_DYING] Allocation is DYING for channel=", channel,
                    " provider=", tostring(res_alloc.provider),
                    " slot=", tostring(res_alloc.slot),
                    " cdn=", tostring(res_alloc.cdn_ip),
                    " → allocating NEW slot")
        else
            local was_live_active = (res_alloc.is_live == true)
            local old_cdn         = res_alloc.cdn_ip
            allocation            = res_alloc
            allocation.is_live    = true
            if allocation.is_archive == nil then
                allocation.is_archive = false
            end

            if not was_live_active then
                local new_cdn = get_cdn_ip_for_slot(res_alloc.provider, res_alloc.slot, {}, red, old_cdn)
                if new_cdn then
                    allocation.cdn_ip = new_cdn
                    ngx.log(ngx.INFO, "[ALLOC_REUSE] CDN reassigned (was not live): old=",
                            old_cdn, " new=", new_cdn)
                else
                    ngx.log(ngx.WARN, "[ALLOC_REUSE] CDN reassignment failed, keeping old CDN=", old_cdn)
                end
            end

            ngx.log(ngx.INFO, "[ALLOC_REUSE] LIVE REUSE: channel=", channel,
                    " provider=", tostring(res_alloc.provider),
                    " slot=", tostring(res_alloc.slot),
                    " cdn=", tostring(allocation.cdn_ip),
                    " old_cdn=", old_cdn,
                    " was_live_active=", tostring(was_live_active))

            red:hset("channel_allocations", alloc_stored_key, cjson.encode(allocation))
            ngx.log(ngx.INFO, "[LIVE] Reusing existing slot: provider=", res_alloc.provider,
                    " slot=", res_alloc.slot, " CDN=", allocation.cdn_ip)

            if not was_live_active then
                ngx.log(ngx.INFO, "[LIVE] Signaling CDN for channel=", channel,
                        " (was not live, inherited from archive)")
                signal_cdn(allocation, "no_hash", channel, red)
            else
                ngx.log(ngx.INFO, "[LIVE] Skipping signal for channel=", channel,
                        " (proxy already live)")
            end
        end
    end
end

if not allocation then
    local lock_key = "lock:alloc:" .. channel
    local lock_acquired = red:set(lock_key, "1", "EX", 3, "NX")

    if lock_acquired == "OK" then
        -- Double-check: прямой ключ
        local double_check_json = red:hget("channel_allocations", channel)
        if double_check_json and double_check_json ~= ngx.null then
            local dc_ok, dc_alloc = pcall(cjson.decode, double_check_json)
            if dc_ok and dc_alloc and not dc_alloc.dying then
                ngx.log(ngx.INFO, "[ALLOC_DC] Double-check hit (direct): channel=", channel,
                        " provider=", tostring(dc_alloc.provider), " slot=", tostring(dc_alloc.slot))
                allocation = dc_alloc
                red:del(lock_key)
                goto signal_phase
            end
        end

        -- Double-check: reverse mapping (hot switch)
        local dc_redirect = red:get("session:alloc_key:" .. session_id)
        if dc_redirect and dc_redirect ~= ngx.null and dc_redirect ~= channel then
            local dc_hs_json = red:hget("channel_allocations", dc_redirect)
            if dc_hs_json and dc_hs_json ~= ngx.null then
                local dc_ok2, dc_hs_alloc = pcall(cjson.decode, dc_hs_json)
                if dc_ok2 and dc_hs_alloc
                   and not dc_hs_alloc.dying
                   and tostring(dc_hs_alloc.current_channel) == tostring(channel)
                   and dc_hs_alloc.owner_session_id == session_id then
                    ngx.log(ngx.INFO, "[ALLOC_DC] Double-check hit (hot-switch reverse): channel=",
                            channel, " stored_key=", dc_redirect)
                    allocation       = dc_hs_alloc
                    alloc_stored_key = dc_redirect
                    red:del(lock_key)
                    goto signal_phase
                end
            end
        end

        local prov, idx, cdn_ip, t_item, new_alloc = select_cdn_slot(red, channel, user_agent)
        if not prov then
            red:del(lock_key)
            red:set_keepalive(10000, 50)
            ngx.status = 503
            ngx.say("No slots available")
            return ngx.exit(503)
        end

        new_alloc.is_live          = true
        new_alloc.is_archive       = false
        new_alloc.current_channel  = channel
        new_alloc.owner_session_id = session_id
        new_alloc.root_id          = channel
        new_alloc.switch_count     = 0
        new_alloc.last_switch_at   = new_alloc.allocated_at
        allocation = new_alloc

        ngx.log(ngx.INFO, "[ALLOC_WRITE] LIVE SAVED: channel=", channel,
                " provider=", allocation.provider,
                " slot=", allocation.slot,
                " cdn=", allocation.cdn_ip)

        red:hset("channel_allocations", channel, cjson.encode(allocation))
        red:setex("session:alloc_key:" .. session_id, 300, channel)

        signal_cdn(allocation, "no_hash", channel, red)
        red:del(lock_key)
    else
        ngx.sleep(0.15)
        local retry_json = red:hget("channel_allocations", channel)
        if retry_json and retry_json ~= ngx.null then
            local ok_js, retry_alloc = pcall(cjson.decode, retry_json)
            if ok_js and retry_alloc and not retry_alloc.dying then
                allocation = retry_alloc
            end
        end

        if not allocation then
            red:set_keepalive(10000, 50)
            ngx.status = 503
            ngx.say("Server is busy allocating this channel")
            return ngx.exit(503)
        end
    end
end

::signal_phase::
red:set_keepalive(10000, 50)
ngx.header["Content-Type"] = "application/vnd.apple.mpegurl"
ngx.say("#EXTM3U\n#EXT-X-VERSION:3\n#EXT-X-STREAM-INF:BANDWIDTH=20000000\n" ..
        "http://" .. allocation.cdn_ip .. ":8123/" .. channel ..
        "/playlist.m3u8" .. token_suffix)