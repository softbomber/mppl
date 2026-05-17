local cjson = require "cjson"
local redis = require "resty.redis"

-- === 1. КОНФИГУРАЦИЯ ===
local MAX_DEVICES = 2
local HEARTBEAT_TTL = 25
local REDIS_HOST = "45.9.73.98"
local REDIS_PASS = "qw34rfvgtU9snaWE"

local cdn_config_cache = nil
local last_config_check = 0

-- === HELPERS ===
local function connect_redis()
    local red = redis:new()
    red:set_timeout(1000)
    local ok, err = red:connect(REDIS_HOST, 6379) 
    if not ok then ngx.log(ngx.ERR, "Redis fail: ", err) ngx.exit(500) end
    red:auth(REDIS_PASS)
    return red
end

local function read_file(path)
    local f = io.open(path, "r")
    if not f then return nil end
    local content = f:read("*a")
    f:close()
    return content
end

local function is_time_allowed(schedule)
    if not schedule or schedule == ngx.null then return true end
    local now_str = os.date("%H:%M")
    local intervals = (schedule.start) and {schedule} or schedule
    for _, range in ipairs(intervals) do
        local s, e = range.start, range["end"]
        if s and e then
            if s <= e then if now_str >= s and now_str <= e then return true end
            else if now_str >= s or now_str <= e then return true end end
        end
    end
    return false
end

local function get_cdn_config()
    local now = ngx.time()
    if not cdn_config_cache or (now - last_config_check > 5) then
        local content = read_file("/etc/cdn_config.json")
        if content then
            local ok, data = pcall(cjson.decode, content)
            if ok then cdn_config_cache, last_config_check = data, now end
        end
    end
    return cdn_config_cache or {}
end

local function get_random_active_cdn(red)
    local config = get_cdn_config()
    local weighted_list = {}
    
    for ip, cfg in pairs(config) do
        if cfg.active ~= false and is_time_allowed(cfg.schedule) then 
            local updated = red:hget("cdn_stats:" .. ip, "updated")
            if updated and updated ~= ngx.null then
                local w = tonumber(cfg.weight) or 1
                if w < 1 then w = 1 end
                -- Добавляем IP в корзину 'weight' раз
                for i = 1, w do table.insert(weighted_list, ip) end
            else
                ngx.log(ngx.WARN, "[CDN] Random fallback skipped DEAD CDN ", ip)
            end
        end
    end
    
    -- Безопасный Fallback (если все мертвы)
    if #weighted_list == 0 then 
        for ip, cfg in pairs(config) do
            if cfg.active ~= false then 
                local w = tonumber(cfg.weight) or 1
                if w < 1 then w = 1 end
                for i = 1, w do table.insert(weighted_list, ip) end
            end
        end
        if #weighted_list == 0 then return nil end
    end
    
    math.randomseed(ngx.now() * 10000 + ngx.worker.pid())
    return weighted_list[math.random(1, #weighted_list)]
end

local function normalize_ip_list(val)
    if not val then return nil end
    local res = {}
    if type(val) == "table" then for _, v in ipairs(val) do table.insert(res, v) end
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

local function string_split(str, delim)
    local result = {}
    for match in (str .. delim):gmatch("(.-)" .. delim) do table.insert(result, match) end
    return result
end

-- === CORE IP SELECTION (ВЕС ПРИОРИТЕТНЕЕ НАГРУЗКИ) ===
-- === CORE IP SELECTION (ВЕС ПРИОРИТЕТНЕЕ НАГРУЗКИ) ===
local function get_cdn_ip(key_prefix, red, allow_raw, disallow_raw, is_sticky, slot_index)
    local config = get_cdn_config()
    local candidates = {}
    local allow_list = normalize_ip_list(allow_raw)
    local disallow_list = normalize_ip_list(disallow_raw)

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
        if is_valid then table.insert(candidates, { ip = ip, cfg = cfg }) end
    end

    if #candidates == 0 then 
        for ip, cfg in pairs(config) do
            if cfg.active ~= false and is_time_allowed(cfg.schedule) then 
                table.insert(candidates, { ip = ip, cfg = cfg }) 
            end
        end
        if #candidates == 0 then return nil end
    end

    local slot_cdn_key = key_prefix .. "_cdn"
    if is_sticky and slot_index then
        local cur_ip, err = red:get(slot_cdn_key)
        if cur_ip and cur_ip ~= ngx.null and config[cur_ip] then
            local lim = config[cur_ip]
            if lim.active ~= false and is_time_allowed(lim.schedule) then
                local load = math.max(tonumber(red:hget("cdn_stats:"..cur_ip, "tx")) or 0, tonumber(red:hget("cdn_stats:"..cur_ip, "rx")) or 0)
                -- Если прилипший сервер еще не забит, остаемся на нем
                if load < (tonumber(lim.limit_mbps) or 100) * 0.85 then return cur_ip end
            end
        end
    end

    local weighted_pool = {}
    local min_load = 999999
    local fallback_ip = nil

    -- Собираем "лотерейный барабан" из живых серверов
    for _, cand in ipairs(candidates) do
        local limit = tonumber(cand.cfg.limit_mbps) or 100
        
        -- Читаем статистику ОДНИМ запросом, включая проверку пульса (updated)!
        local stats, err = red:hmget("cdn_stats:"..cand.ip, "tx", "rx", "updated")
        
        -- ПРОВЕРКА: Если есть пульс (updated), значит сервер жив. 
        -- Убрано глупое условие (load ~= 0), теперь серверы забирают трафик сразу после старта!
        if stats and stats[3] and stats[3] ~= ngx.null then
            local tx = tonumber(stats[1]) or 0
            local rx = tonumber(stats[2]) or 0
            local load = math.max(tx, rx)
            
            -- Запоминаем наименее загруженный сервер на крайний случай (fallback)
            if load < min_load then
                min_load = load
                fallback_ip = cand.ip
            end

            -- Если сервер НЕ переполнен (есть запас хотя бы 10 Мбит/с до лимита)
            if load < (limit - 10) then
                local weight = tonumber(cand.cfg.weight) or 1
                if weight < 1 then weight = 1 end
                
                -- ВАЖНО: Кладем IP в барабан `weight` раз!
                for i = 1, weight do
                    table.insert(weighted_pool, cand.ip)
                end
            end
        else
            ngx.log(ngx.WARN, "[CDN] Skipping DEAD CDN ", cand.ip, " (No heartbeat in Redis)")
        end
    end

    local best_ip = nil

    if #weighted_pool > 0 then
        -- ВЕС ПРИОРИТЕТНЕЕ НАГРУЗКИ: Вытягиваем случайный "билет" из барабана.
        -- Сервер с весом 4 имеет 80% шанс получить канал, сервер с весом 1 - 20%.
        math.randomseed(ngx.now() * 10000 + ngx.worker.pid())
        best_ip = weighted_pool[math.random(1, #weighted_pool)]
    else
        -- Если ВСЕ серверы забиты под завязку (weighted_pool пуст)
        -- Отдаем тот сервер, на котором сейчас физически меньше всего мегабит
        best_ip = fallback_ip
        
        if not best_ip then
            -- Если вообще нет живых серверов (всё упало)
            math.randomseed(ngx.now() * 10000 + ngx.worker.pid())
            best_ip = candidates[math.random(1, #candidates)].ip
            ngx.log(ngx.ERR, "[CDN] CRITICAL: No alive CDNs found! Falling back to ", best_ip)
        end
    end

	-- В get_cdn_ip, последняя строка перед return:
	red:setex(slot_cdn_key, 3600, best_ip)  -- вместо red:set(slot_cdn_key, best_ip)
    return best_ip
end

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
        origin = "cdn.balelbrus.com"
    end
    channel_path = channel_path:gsub("^/+", ""):gsub("/+$", "")
    local url = string.format("http://%s/%s/index.m3u8?token=%s", origin, channel_path, token)
    ngx.log(ngx.INFO, "[ELBRUS] Resolved URL: ", url)
    return url
end
local function urljoin(base, path)
    local nb = normalize_base(base)
    return nb and nb .. "/" .. path:gsub("^/", "") or path
end
local function signal_cdn(alloc, key, channel, red)
    if not (alloc.cdn_ip and alloc.provider and alloc.slot and alloc.source_url) then return end
    red:publish("channel_starts", cjson.encode({
        cdn_ip = alloc.cdn_ip, key = key, token = alloc.token, channel = channel,
        provider = alloc.provider, slot = alloc.slot, source_url = alloc.source_url,
        user_agent = alloc.user_agent, bandwidth = alloc.bandwidth, quality = alloc.quality,
    sources = alloc.sources, referer = alloc.referer
    }))
end

-- === FAST ZAPPING: Check if other sessions exist on channel ===
local function has_other_sessions_on_channel(red, channel, exclude_session_id)
    local all_tokens = red:keys("online:users:*")
    for _, token_key in ipairs(all_tokens) do
        local meta_key = token_key .. ":meta"
        local all_meta = red:hgetall(meta_key)
        if all_meta and #all_meta > 0 then
            for i = 1, #all_meta, 2 do
                local sess_id = all_meta[i]
                local meta_json = all_meta[i+1]
                if sess_id ~= exclude_session_id and meta_json then
                    local m = cjson.decode(meta_json)
                    if m and m.channel == channel then
                        return true
                    end
                end
            end
        end
    end
    return false
end

-- === GET CDN IP FOR SLOT (with allowed_cdns support) ===
local function get_cdn_ip_for_slot(provider_name, slot_idx, allowed_cdns, red)
    -- Читаем cdn_config из Redis
    local cdn_config_json = red:get("config:cdn_json")
    if not cdn_config_json or cdn_config_json == ngx.null then
        ngx.log(ngx.ERR, "[CDN_SELECT] No cdn_config in Redis (key: config:cdn_json)")
        return nil
    end

    local cdn_config = cjson.decode(cdn_config_json)

    -- Собираем список активных CDN
    local all_cdns = {}
    for cdn_ip, cfg in pairs(cdn_config) do
        if cfg.active then
            table.insert(all_cdns, cdn_ip)
        end
    end

    if #all_cdns == 0 then
        ngx.log(ngx.ERR, "[CDN_SELECT] No active CDN in config")
        return nil
    end

    -- Фильтруем CDN по allowed_cdns
    local available_cdns = {}

    if #allowed_cdns == 0 then
        -- Нет ограничений - все активные CDN доступны
        available_cdns = all_cdns
    else
        -- Фильтруем: только те что в allowed_cdns И активны
        for _, cdn_ip in ipairs(all_cdns) do
            for _, allowed_ip in ipairs(allowed_cdns) do
                if cdn_ip == allowed_ip then
                    table.insert(available_cdns, cdn_ip)
                    break
                end
            end
        end
    end

    if #available_cdns == 0 then
        ngx.log(ngx.ERR, "[CDN_SELECT] No available CDN for provider=", provider_name,
               " slot=", slot_idx)
        return nil
    end

    -- Weighted random pool (как в оригинальном бэкапе)
    local weighted_pool = {}
    local fallback_ip = nil
    local min_load = 999999

    for _, cdn_ip in ipairs(available_cdns) do
        local cfg = cdn_config[cdn_ip] or {}
        local limit = tonumber(cfg.limit_mbps) or 100

        -- Проверяем heartbeat (пульс)
        local updated = red:hget("cdn_stats:" .. cdn_ip, "updated")
        if updated and updated ~= ngx.null then
            local tx = tonumber(red:hget("cdn_stats:" .. cdn_ip, "tx")) or 0
            local rx = tonumber(red:hget("cdn_stats:" .. cdn_ip, "rx")) or 0
            local load = math.max(tx, rx)

            -- Fallback: запоминаем наименее загруженный
            if load < min_load then
                min_load = load
                fallback_ip = cdn_ip
            end

            -- Если не переполнен — добавляем в weighted pool
            if load < (limit - 10) then
                local weight = tonumber(cfg.weight) or 1
                if weight < 1 then weight = 1 end
                for i = 1, weight do
                    table.insert(weighted_pool, cdn_ip)
                end
            end
        else
            ngx.log(ngx.WARN, "[CDN_SELECT] Skipping DEAD CDN ", cdn_ip, " (No heartbeat)")
        end
    end

    local selected_ip = nil

    if #weighted_pool > 0 then
        -- ВЕС ПРИОРИТЕТНЕЕ НАГРУЗКИ: random из weighted pool
        math.randomseed(ngx.now() * 10000 + ngx.worker.pid())
        selected_ip = weighted_pool[math.random(1, #weighted_pool)]
    else
        -- Все серверы забиты → fallback на min_load
        selected_ip = fallback_ip

        if not selected_ip then
            -- Критический fallback: первый доступный
            math.randomseed(ngx.now() * 10000 + ngx.worker.pid())
            selected_ip = available_cdns[math.random(1, #available_cdns)]
            ngx.log(ngx.ERR, "[CDN_SELECT] CRITICAL: No alive CDNs! Fallback to ", selected_ip)
        end
    end

    ngx.log(ngx.ERR, "[CDN_SELECT] provider=", provider_name, " slot=", slot_idx,
           " selected_cdn=", selected_ip, " (weighted_pool=", #weighted_pool,
           " available=", #available_cdns, "/", #all_cdns, ")")

    return selected_ip
end

-- === SELECT CDN SLOT ===
local function select_cdn_slot(red, channel, client_ua) -- ДОБАВЛЕН ПАРАМЕТР client_ua
    ngx.log(ngx.ERR, "[SLOT_ALLOC] select_cdn_slot called: channel=", channel,
            " caller=", debug.traceback("", 2))
    local chanmap_json = red:hgetall("config:chanmap")
    local chanmap = {}
    for i = 1, #chanmap_json, 2 do
        local info = cjson.decode(chanmap_json[i+1])
        if info then chanmap[chanmap_json[i]] = info end
    end
    local channel_info = chanmap[channel]

    -- УМНЫЙ USER-AGENT И REFERER:
    -- Если в chanmap прописан жесткий агент, берем его. Иначе берем агент клиента.
    local final_ua = (channel_info and channel_info.agent) or client_ua
    local final_referer = channel_info and channel_info.referer

    if channel_info and channel_info.url then
        local usage_key = "direct_url:usage"
        if red:llen(usage_key) == 0 then 
            red:rpush(usage_key, 1)
        else 
            red:lset(usage_key, 0, (tonumber(red:lindex(usage_key, 0)) or 0) + 1) 
        end

        local cdn_ip = get_cdn_ip("direct_url_" .. channel, red, channel_info.allow, channel_info.disallow, false, 0)
        local alloc = {
            provider = "direct_url", slot = 0, allocated_at = ngx.time(),
            cdn_ip = cdn_ip, source_url = channel_info.url,
            quality = channel_info.quality, bandwidth = channel_info.bandwidth, sources = {},
            user_agent = final_ua, referer = final_referer -- ДОБАВЛЕНО СЮДА
        }
        return "direct_url", 0, cdn_ip, nil, alloc 
    end
    
    local providers_json = red:hgetall("config:providers")
    local providers = {}
    for i = 1, #providers_json, 2 do
        local cfg = cjson.decode(providers_json[i+1])
        if cfg then table.insert(providers, { name = providers_json[i], allow = cfg.allow_channels or 1, priority = cfg.priority or 999, cfg = cfg }) end
    end
    table.sort(providers, function(a, b) return a.priority < b.priority end)
    local acquire_slot_script = "local key = KEYS[1] local idx = tonumber(ARGV[1]) local limit = tonumber(ARGV[2]) local current = tonumber(redis.call('LINDEX', key, idx) or '0') if current >= limit then return {0, 'slot_full'} end redis.call('LSET', key, idx, current + 1) return {1, 'ok'}"
    
    for _, provider in ipairs(providers) do
        local usage_key = provider.name .. ":usage"
        local len = red:llen(usage_key)
        if len > 0 then
            -- === NEW: Smart slot selection with cooldown ===
            local now = ngx.time()
            local last_used_key = provider.name .. ":last_used"

            -- Динамический TTL: для большого количества слотов - меньше TTL
            -- Формула: TTL = max(300, len * 10) секунд
            -- Примеры: 4 слота → 300s, 10 слотов → 300s, 36 слотов → 360s, 100 слотов → 1000s
            local ttl = math.max(300, len * 10)

            -- Собираем информацию о всех слотах
            local slot_scores = {}
            for i = 0, len - 1 do
                local current_usage = tonumber(red:lindex(usage_key, i)) or 0
                local last_used = tonumber(red:hget(last_used_key, tostring(i))) or 0
                local time_since_use = now - last_used

                -- Вычисляем "привлекательность" слота:
                -- - Чем больше времени прошло с последнего использования, тем лучше
                local score = time_since_use

                table.insert(slot_scores, {
                    idx = i,
                    usage = current_usage,
                    last_used = last_used,
                    time_since_use = time_since_use,
                    score = score
                })
            end

            -- Сортируем слоты по убыванию score (давно не использованные первыми)
            table.sort(slot_scores, function(a, b) return a.score > b.score end)

            -- Пробуем выделить слоты в порядке приоритета
local slot_allocated = false
            for _, slot_info in ipairs(slot_scores) do
                local idx = slot_info.idx

                -- =======================================================
                -- ШАГ 1: ПРОВЕРЯЕМ УСЛОВИЯ И ГЕНЕРИРУЕМ ССЫЛКУ (ДО БЛОКИРОВКИ!)
                -- =======================================================
                local items = provider.cfg.tokens or provider.cfg.bases or {}
                local item = items[idx + 1]

                local base_url
                local allowed_cdns = {}

                if type(item) == "table" then
                    base_url = item.url
                    allowed_cdns = item.allowed_cdns or {}
                else
                    base_url = item
                    allowed_cdns = {}
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

                -- Если провайдер физически не может отдать этот канал - пропускаем слот!
                -- В Redis при этом НИЧЕГО не пишется, слот остается свободным.
                if not source_url then
                    ngx.log(ngx.INFO, "[SLOT_SKIP] Provider ", provider.name, " cannot play channel ", channel)
                    goto continue_slot_search
                end

                -- =======================================================
                -- ШАГ 2: ССЫЛКА СГЕНЕРИРОВАЛАСЬ? БРОНИРУЕМ СЛОТ В REDIS!
                -- =======================================================
                local res = red:eval(acquire_slot_script, 1, usage_key, idx, provider.allow)
                
                if type(res) == "table" and res[1] == 1 then
                    slot_allocated = true
                    red:hset(last_used_key, tostring(idx), tostring(now))
                    red:expire(last_used_key, ttl)

                    -- =======================================================
                    -- ШАГ 3: ПОДБИРАЕМ СЕРВЕР (CDN)
                    -- =======================================================
                    local cdn_ip = get_cdn_ip_for_slot(provider.name, idx, allowed_cdns, red)

                    -- Единственный случай отката: слот мы заняли, но ВСЕ CDN серверы "легли"
                    if not cdn_ip then
                        ngx.log(ngx.ERR, "[SLOT_ALLOC] No available CDN for slot, rolling back")
                        red:eval("local key = KEYS[1] local idx = tonumber(ARGV[1]) local current = tonumber(redis.call('LINDEX', key, idx) or '0') if current > 0 then redis.call('LSET', key, idx, current - 1) end return 1", 1, usage_key, idx)
                        goto continue_slot_search
                    end

                    -- Успех! Формируем аллокацию и выходим.
                    local alloc = {
                        provider = provider.name,
                        slot = idx,
                        allocated_at = ngx.time(),
                        cdn_ip = cdn_ip,
                        source_url = source_url,
                        token = base_url,
                        user_agent = final_ua,
                        referer = final_referer
                    }
                    ngx.log(ngx.ERR, "[SLOT_ALLOC] ALLOCATED: channel=", channel, " provider=", provider.name, " slot=", idx)

                    return provider.name, idx, cdn_ip, base_url, alloc
                end
                
                ::continue_slot_search::
            end
            if not slot_allocated then
                ngx.log(ngx.WARN, "[SLOT_ALLOC] All slots full for provider=", provider.name,
                       " limit=", provider.allow, " — skipping to next provider")
            end
        end
    end

    return nil, nil, nil, nil, nil
end

-- ==========================================================
-- === MAIN EXECUTION ===
-- ==========================================================
local red = connect_redis()
local raw_token = ngx.var.arg_token
local token = raw_token
local utc = nil 
local lutc = nil

if raw_token then
    local q_pos = string.find(raw_token, "?", 1, true)
    if q_pos then
        token = string.sub(raw_token, 1, q_pos - 1)
        local tail = string.sub(raw_token, q_pos + 1)
        tail = ngx.unescape_uri(tail)
        for key, value in string.gmatch(tail, "([%w_]+)=([%d]+)") do
            if key == "utc" then
                utc = value
                ngx.log(ngx.ERR, "[ARCHIVE] Extracted utc=", utc, " from token tail")
            elseif key == "lutc" then
                lutc = value
                ngx.log(ngx.ERR, "[ARCHIVE] Extracted lutc=", lutc, " from token tail")
            end
        end
        ngx.log(ngx.ERR, "[ARCHIVE] Fixed token: ", token, " (was: ", raw_token, ")")
    end
end

if not utc then
    local full_uri = ngx.var.request_uri
    local uri_utc = string.match(full_uri, "[?&]utc=(%d+)")
    local uri_lutc = string.match(full_uri, "[?&]lutc=(%d+)")
    if uri_utc then
        utc = uri_utc
        ngx.log(ngx.ERR, "[ARCHIVE] Extracted utc=", utc, " from full URI")
    end
    if uri_lutc then
        lutc = uri_lutc
        ngx.log(ngx.ERR, "[ARCHIVE] Extracted lutc=", lutc, " from full URI")
    end
end

if not lutc then
    lutc = tostring(os.time() + 3600)
    ngx.log(ngx.ERR, "[ARCHIVE] lutc not found, using default: ", lutc)
end

ngx.log(ngx.ERR, "DEBUG: final_token=", token, " utc=", utc or "nil", " lutc=", lutc or "nil")
local token_suffix = token and "?token=" .. token or ""
if not token or token == "" then 
    red:set_keepalive(10000, 50) 
    ngx.status = 403 
    ngx.say("Token missing") 
    return ngx.exit(403) 
end

local channel = ngx.var.channel or "0"
if channel == "0" then
    local s, e = string.find(ngx.var.uri, "/(%d+)/")
    if s then
        channel = string.sub(ngx.var.uri, s+1, e-1)
    else
        channel = ngx.var.uri:match("/(%d+)%.m3u8") or "0"
    end
    if channel == "0" then
        local args = ngx.req.get_uri_args()
        if args.ch then
            channel = tostring(args.ch)
            ngx.log(ngx.ERR, "[ARCHIVE] Channel extracted from ch= parameter: ", channel)
        end
    end
end

-- ==========================================================
-- === ПРОВЕРКА ОТКЛЮЧЕННЫХ КАНАЛОВ (BLACKLIST) ===
-- ==========================================================
if channel ~= "0" then
    local disabled_json = red:hget("config:chanmap", "disabled")
    if disabled_json and disabled_json ~= ngx.null then
        -- chanmap хранит данные в JSON формате, поэтому декодируем строку
        local ok, disabled_data = pcall(cjson.decode, disabled_json)
        local disabled_str = ""
        
        -- Если значение это строка {"id": "100,200"} или просто "100,200"
        if ok and type(disabled_data) == "table" and disabled_data.id then
            disabled_str = tostring(disabled_data.id)
        elseif type(disabled_json) == "string" then
            -- Очищаем от лишних кавычек, если они есть
            disabled_str = disabled_json:gsub('"', '')
        end

        if disabled_str ~= "" then
            -- Разбиваем строку по запятой и проверяем совпадение
            for disabled_ch in disabled_str:gmatch("([^,]+)") do
                -- Убираем пробелы по краям
                local trim_ch = disabled_ch:match("^%s*(.-)%s*$")
                
                if trim_ch == channel then
                    ngx.log(ngx.WARN, "[ACCESS_DENIED] Channel ", channel, " is globally disabled in chanmap.json")
                    
                    red:set_keepalive(10000, 50)
                    ngx.status = 403
                    ngx.header["Content-Type"] = "application/vnd.apple.mpegurl"
                    -- Отдаем корректный пустой HLS плейлист с ошибкой, чтобы плеер сразу отменил попытки
                    ngx.say("#EXTM3U\n#EXT-X-ERROR: Channel is currently disabled by administrator.\n#EXT-X-ENDLIST")
                    return ngx.exit(403)
                end
            end
        end
    end
end
-- ==========================================================

local user_agent = ngx.req.get_headers()["User-Agent"] or "unknown"
local client_ip = ngx.var.remote_addr
local session_id = ngx.md5(token .. client_ip .. user_agent)
local now = ngx.time()

if utc and channel ~= "0" then
    local meta_json = red:hget("online:users:" .. token .. ":meta", session_id)
    if meta_json and meta_json ~= ngx.null then
        local meta = cjson.decode(meta_json)
        if meta and meta.channel and meta.channel ~= channel then
            local old_channel = meta.channel
            ngx.log(ngx.ERR, "[ARCHIVE] User switched from channel ", old_channel, " to ", channel)

            local archive_last_seen = red:get("archive_last_seen:" .. old_channel)
            local archive_active = (archive_last_seen ~= nil and archive_last_seen ~= ngx.null)

            if not archive_active then
                local has_live_session = false
                local all_tokens = red:keys("online:users:*")
                for _, token_key in ipairs(all_tokens) do
                    local meta_key = token_key .. ":meta"
                    local all_meta = red:hgetall(meta_key)
                    if all_meta and #all_meta > 0 then
                        for i = 1, #all_meta, 2 do
                            local meta_json = all_meta[i+1]
                            if meta_json then
                                local m = cjson.decode(meta_json)
                                if m and m.channel == old_channel then
                                    has_live_session = true
                                    ngx.log(ngx.ERR, "[ARCHIVE] LIVE session active on channel ", old_channel, ", keeping allocation")
                                    break
                                end
                            end
                        end
                    end
                    if has_live_session then break end
                end

                if not has_live_session then
                    ngx.log(ngx.ERR, "[ARCHIVE] No active sessions on channel ", old_channel, ", clearing allocation")
                    red:hdel("channel_allocations", old_channel)
                    red:hdel("archive_tvclub_url", old_channel)
                    ngx.log(ngx.ERR, "[ARCHIVE] Cleared archive_tvclub_url for channel=", old_channel)
                end
            else
                ngx.log(ngx.ERR, "[ARCHIVE] Archive still active on channel ", old_channel, ", keeping allocation")
            end
        end
    end
end

local legacy_key = "blocked:devices:" .. token
local lock_key = "ban:lock:" .. token .. ":" .. session_id
if red:sismember(legacy_key, session_id) == 1 then
    local locked_ch = red:get(lock_key)
    if locked_ch == ngx.null then
        red:setex(lock_key, 3600, channel)
        locked_ch = channel
    end
    if tostring(locked_ch) == tostring(channel) or locked_ch == "all" then
        local r_cdn = get_random_active_cdn(red) or "127.0.0.1"
        red:set_keepalive(10000, 50)
        ngx.header["Content-Type"] = "application/vnd.apple.mpegurl"
        ngx.say("#EXTM3U\n#EXT-X-VERSION:3\n#EXT-X-TARGETDURATION:10\n#EXTINF:10.0,\nhttp://" .. r_cdn .. ":8123/black.ts\n#EXT-X-ENDLIST")
        return ngx.exit(200)
    else
        red:srem(legacy_key, session_id)
        red:del(lock_key)
    end
end
local user_status = red:get("user:" .. token .. ":status")
local user_expire = red:get("user:" .. token .. ":expire")
local exp_time = tonumber(user_expire)

ngx.log(ngx.ERR, "[AUTH] user_status=", user_status, " expire=", exp_time, " now=", now)

if tostring(user_status) == "blocked" then
    ngx.log(ngx.ERR, "[AUTH] USER BLOCKED: token=", token)
    local r_cdn = get_random_active_cdn(red) or "127.0.0.1"
    red:set_keepalive(10000, 50)
    ngx.header["Content-Type"] = "application/vnd.apple.mpegurl"
    ngx.say("#EXTM3U\n#EXT-X-VERSION:3\n#EXT-X-TARGETDURATION:10\n#EXTINF:10.0,\nhttp://" .. r_cdn .. ":8123/ban/black.ts\n#EXT-X-ENDLIST")
    return ngx.exit(200)
end

if tostring(user_status) ~= "active" or not exp_time or exp_time < now then
    ngx.log(ngx.ERR, "[AUTH] USER EXPIRED/INACTIVE: status=", user_status, " expire=", exp_time)
    local r_cdn = get_random_active_cdn(red) or "127.0.0.1"
    red:set_keepalive(10000, 50)
    ngx.header["Content-Type"] = "application/vnd.apple.mpegurl"
    ngx.say("#EXTM3U\n#EXT-X-VERSION:3\n#EXTINF:10.0,\nhttp://" .. r_cdn .. ":8123/stub/splash.ts\n#EXT-X-ENDLIST")
    return ngx.exit(200)
end

ngx.log(ngx.ERR, "[AUTH] USER ACTIVE: token=", token)

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
            if (now - (tonumber(old_data.last_seen) or 0)) <= 60 then start_time = tonumber(old_data.start) or now end
        end
    end
    if not redis.call('ZSCORE', key_user, session_id) and active_count >= limit then
        -- IP сменился, но UA тот же — это то же устройство, заменяем старую сессию
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
    return 1
]]
local res = red:eval(limit_script, 4, "online:users:"..token, "stats:online:channel:"..channel, "stats:daily:"..os.date("%Y-%m-%d")..":channel:"..channel, "stats:channels_list",
    session_id, MAX_DEVICES, now, HEARTBEAT_TTL, "meta_placeholder", token, channel, user_agent, client_ip, ngx.var.server_addr or "balancer")
if res == 0 then
    local r_cdn = get_random_active_cdn(red) or "127.0.0.1"
    red:set_keepalive(10000, 50)
    ngx.header["Content-Type"] = "application/vnd.apple.mpegurl"
    ngx.say("#EXTM3U\n#EXT-X-VERSION:3\n#EXT-X-TARGETDURATION:10\n#EXTINF:10.0,\nhttp://" .. r_cdn .. ":8123/limit.ts\n#EXT-X-ENDLIST")
    return ngx.exit(200)
end

-- 5. ОБРАБОТКА АРХИВА (ПРИОРИТЕТ — ПЕРЕД ANTI-ZAPPING!)
if utc and (string.find(ngx.var.uri, "%.m3u8$")) then
    ngx.log(ngx.ERR, "[ARCHIVE] === START === channel=", channel, " utc=", utc, " lutc=", lutc or "nil")
    local target_cdn = nil
    local source_url = nil
    local final_alloc = nil
    local config = get_cdn_config()
    
    -- === ПРИОРИТЕТ 1: archive_history (архив на этом CDN) ===
    local cached_cdn = red:hget("archive_history", channel)
    if cached_cdn and cached_cdn ~= ngx.null then
        if config[cached_cdn] and config[cached_cdn].active ~= false then
            target_cdn = cached_cdn
            ngx.log(ngx.ERR, "[ARCHIVE] Using archive_history CDN=", target_cdn)
            
            -- === ПРОВЕРКА: Есть ли уже выделенный слот для архива? ===
            local existing_alloc_json = red:hget("channel_allocations", channel)
            if existing_alloc_json and existing_alloc_json ~= ngx.null then
                local existing_alloc = cjson.decode(existing_alloc_json)
                if existing_alloc and existing_alloc.provider then
                    
                    -- === ИСПРАВЛЕНИЕ 1: Изолируем Архивные слоты от LIVE ===
                    if existing_alloc.provider == "direct_url" then
                        ngx.log(ngx.ERR, "[ARCHIVE] LIVE used direct_url. Checking archive_tvclub_url...")
                        
                        local tvclub_alloc_json = red:hget("archive_tvclub_url", channel)
                        if tvclub_alloc_json and tvclub_alloc_json ~= ngx.null then
                            local tvclub_alloc = cjson.decode(tvclub_alloc_json)
                            if tvclub_alloc and tvclub_alloc.provider then
                                target_cdn = tvclub_alloc.cdn_ip
                                source_url = tvclub_alloc.source_url
                                ngx.log(ngx.ERR, "[ARCHIVE] Reusing existing archive tvclub slot: ", tvclub_alloc.slot)
                                goto archive_slot_found
                            end
                        end

                        ngx.log(ngx.ERR, "[ARCHIVE] Allocating NEW tvclub slot for archive...")
                        local prov, idx, cdn_ip, t_item, alloc = select_cdn_slot(red, channel, user_agent)
                        if prov and cdn_ip and alloc and alloc.source_url then
                            target_cdn = cdn_ip
                            source_url = alloc.source_url

                            local tvclub_alloc = {
                                provider = prov,
                                slot = idx,
                                cdn_ip = cdn_ip,
                                source_url = source_url,
                                token = alloc.token,
                                allocated_at = ngx.time()
                            }
                            
                            -- Пишем ТОЛЬКО в archive_tvclub_url. Сигнал на CDN НЕ шлём!
                            red:hset("archive_tvclub_url", channel, cjson.encode(tvclub_alloc))
                            ngx.log(ngx.ERR, "[ARCHIVE] Allocated tvclub slot for archive: provider=", prov, " slot=", idx, " CDN=", cdn_ip)
                            goto archive_slot_found
                        end
                    end
                    -- ========================================================

                    target_cdn = existing_alloc.cdn_ip
                    source_url = existing_alloc.source_url
                    ngx.log(ngx.ERR, "[ARCHIVE] Reusing existing slot: provider=", existing_alloc.provider, " slot=", existing_alloc.slot, " CDN=", target_cdn)
                    goto archive_slot_found
                end
            end
            
            local chanmap_json = red:hget("config:chanmap", channel)
            if chanmap_json and chanmap_json ~= ngx.null then
                local chan_info = cjson.decode(chanmap_json)
                if chan_info and chan_info.url then
                    source_url = chan_info.url
                    ngx.log(ngx.ERR, "[ARCHIVE] source_url from chanmap: ", source_url)
                    
                    if not existing_alloc_json or existing_alloc_json == ngx.null then
                        ngx.log(ngx.ERR, "[ARCHIVE] channel_allocations missing, allocating new slot...")

                        local prov, idx, cdn_ip, t_item, alloc = select_cdn_slot(red, channel, user_agent)
                        if prov and cdn_ip and alloc and alloc.source_url then
                            if cached_cdn and cached_cdn ~= cdn_ip then
                                ngx.log(ngx.ERR, "[ARCHIVE] Using archive_history CDN=", cached_cdn, " instead of slot CDN=", cdn_ip)
                                cdn_ip = cached_cdn
                            end

                            target_cdn = cdn_ip
                            source_url = alloc.source_url
                            final_alloc = alloc
                            local archive_alloc = {
                                provider = alloc.provider,
                                slot = alloc.slot,
                                cdn_ip = cdn_ip,
                                source_url = source_url,
                                token = alloc.token,
                                is_archive = true,
                                is_live = false,
                                allocated_at = ngx.time()
                            }
                            red:hset("channel_allocations", channel, cjson.encode(archive_alloc))
                            ngx.log(ngx.ERR, "[ARCHIVE] Allocated new slot: provider=", prov, " slot=", idx, " CDN=", cdn_ip)
                            goto archive_slot_found
                        end
                    end
                    
                    if chan_info.url:match("/iptv/") then
                        local tvclub_alloc_json = red:hget("archive_tvclub_url", channel)
                        if tvclub_alloc_json and tvclub_alloc_json ~= ngx.null then
                            local tvclub_alloc = cjson.decode(tvclub_alloc_json)
                            local archive_last_seen = red:get("archive_last_seen:" .. channel)
                            local archive_active = (archive_last_seen ~= nil and archive_last_seen ~= ngx.null)

                            if archive_active then
                                target_cdn = tvclub_alloc.cdn_ip
                                source_url = tvclub_alloc.source_url
                                ngx.log(ngx.ERR, "[ARCHIVE] Reusing existing tvclub URL for channel=", channel, " slot=", tvclub_alloc.slot)
                                goto archive_slot_found
                            else
                                local has_active_session = false
                                local all_tokens = red:keys("online:users:*")
                                for _, token_key in ipairs(all_tokens) do
                                    local meta_key = token_key .. ":meta"
                                    local all_meta = red:hgetall(meta_key)
                                    if all_meta and #all_meta > 0 then
                                        for i = 1, #all_meta, 2 do
                                            local meta_json = all_meta[i+1]
                                            if meta_json then
                                                local m = cjson.decode(meta_json)
                                                if m and m.channel == channel then
                                                    has_active_session = true
                                                    ngx.log(ngx.ERR, "[ARCHIVE] LIVE session active on channel ", channel, ", keeping tvclub URL")
                                                    break
                                                end
                                            end
                                        end
                                    end
                                    if has_active_session then break end
                                end

                                if not has_active_session then
                                    ngx.log(ngx.ERR, "[ARCHIVE] No active sessions on channel ", channel, ", clearing tvclub URL")
                                    red:hdel("archive_tvclub_url", channel)
                                else
                                    target_cdn = tvclub_alloc.cdn_ip
                                    source_url = tvclub_alloc.source_url
                                    ngx.log(ngx.ERR, "[ARCHIVE] Reusing tvclub URL (LIVE active) for channel=", channel)
                                    goto archive_slot_found
                                end
                            end
                        end

                        ngx.log(ngx.ERR, "[ARCHIVE] direct_url detected, allocating tvclub slot for archive...")
                        local prov, idx, cdn_ip, t_item, alloc = select_cdn_slot(red, channel, user_agent)
                        if prov and cdn_ip and alloc and alloc.source_url then
                            target_cdn = cdn_ip
                            source_url = alloc.source_url

                            local tvclub_alloc = {
                                provider = prov,
                                slot = idx,
                                cdn_ip = cdn_ip,
                                source_url = source_url,
                                token = alloc.token,
                                allocated_at = ngx.time()
                            }
                            red:hset("archive_tvclub_url", channel, cjson.encode(tvclub_alloc))
                            ngx.log(ngx.ERR, "[ARCHIVE] Allocated tvclub slot: provider=", prov, " slot=", idx, " CDN=", cdn_ip)
                            goto archive_slot_found
                        end
                    end
                    
                    local archive_alloc = {
                        provider = "direct_url",
                        slot = 0,
                        cdn_ip = target_cdn,
                        source_url = source_url,
                        token = "",
                        allocated_at = ngx.time()
                    }
                    red:hset("channel_allocations", channel, cjson.encode(archive_alloc))
                end
            end
            
            if not source_url then
                ngx.log(ngx.ERR, "[ARCHIVE] chanmap.url is nil, allocating slot for archive...")
                local prov, idx, cdn_ip, t_item, alloc = select_cdn_slot(red, channel, user_agent)
                if prov and cdn_ip and alloc and alloc.source_url then
                    target_cdn = cdn_ip
                    source_url = alloc.source_url
                    local archive_alloc = {
                        provider = prov,
                        slot = idx,
                        cdn_ip = cdn_ip,
                        source_url = source_url,
                        token = alloc.token,
                        allocated_at = ngx.time()
                    }
                    red:hset("channel_allocations", channel, cjson.encode(archive_alloc))
                    ngx.log(ngx.ERR, "[ARCHIVE] Allocated slot: provider=", prov, " CDN=", target_cdn, " slot=", idx)
                end
            end
            
            ::archive_slot_found::
        else
            red:hdel("archive_history", channel)
        end
    end
    
    local live_cdn = nil
    local live_source = nil
    local alloc_json = red:hget("channel_allocations", channel)
    if alloc_json and alloc_json ~= ngx.null then
        local alloc = cjson.decode(alloc_json)
        live_cdn = alloc.cdn_ip
        live_source = alloc.source_url
        ngx.log(ngx.ERR, "[ARCHIVE] LIVE exists on CDN=", live_cdn)
    end

    if not target_cdn and live_cdn then
        target_cdn = live_cdn
        source_url = live_source
        red:hset("archive_history", channel, target_cdn)
        red:expire("archive_history", 3600)
        ngx.log(ngx.ERR, "[ARCHIVE] Using LIVE CDN for archive: ", target_cdn)
    end
    
    if not target_cdn then
        ngx.log(ngx.ERR, "[DEBUG] === ENTERING PRIORITY 3: select_cdn_slot === channel=", channel)

        local archive_key = "archive_last_seen:" .. channel
        local last_seen_raw = red:get(archive_key)
        ngx.log(ngx.ERR, "[DEBUG] archive_last_seen RAW value: channel=", channel, " value=", last_seen_raw or "NIL")

        local last_seen = tonumber(last_seen_raw) or 0
        local now_time = ngx.time()

        ngx.log(ngx.ERR, "[DEBUG] archive_last_seen check: channel=", channel, 
                " last_seen_raw=", last_seen_raw or "nil",
                " last_seen=", last_seen,
                " now_time=", now_time,
                " diff=", last_seen > 0 and (now_time - last_seen) or "N/A")
                
        local should_free = false
        if last_seen_raw == nil then
            ngx.log(ngx.ERR, "[ARCHIVE] archive_last_seen key is NIL (expired), should_free=true for channel=", channel)
            should_free = true
        elseif last_seen > 0 and (now_time - last_seen) > 60 then
            ngx.log(ngx.ERR, "[ARCHIVE] archive_last_seen expired (", now_time - last_seen, " sec > 60 sec), should_free=true for channel=", channel)
            should_free = true
        else
            ngx.log(ngx.ERR, "[ARCHIVE] archive_last_seen still active (", last_seen_raw, " sec), should_free=false for channel=", channel)
        end
        
        if should_free then
            local has_active_session = false

            local alloc_json = red:hget("channel_allocations", channel)
            ngx.log(ngx.ERR, "[DEBUG] channel_allocations check: channel=", channel, 
                    " alloc_json=", alloc_json and "EXISTS" or "NIL")

            if alloc_json and alloc_json ~= ngx.null then
                has_active_session = true
                ngx.log(ngx.ERR, "[ARCHIVE] channel_allocations EXISTS - session active, keeping slot for channel=", channel)
            end

            if not has_active_session then
                ngx.log(ngx.ERR, "[DEBUG] online:users check: channel=", channel, " searching...")
                local all_tokens = red:keys("online:users:*")
                ngx.log(ngx.ERR, "[DEBUG] online:users tokens found: ", #all_tokens)

                for _, token_key in ipairs(all_tokens) do
                    local meta_key = token_key .. ":meta"
                    local all_meta = red:hgetall(meta_key)
                    if all_meta and #all_meta > 0 then
                        for i = 1, #all_meta, 2 do
                            local meta_json = all_meta[i+1]
                            if meta_json then
                                local meta = cjson.decode(meta_json)
                                if meta and meta.channel == channel then
                                    has_active_session = true
                                    ngx.log(ngx.ERR, "[ARCHIVE] Device on channel ", channel, " active (token=", token_key, "), keeping slot")
                                    break
                                end
                            end
                        end
                    end
                    if has_active_session then break end
                end
                if not has_active_session then
                    ngx.log(ngx.ERR, "[DEBUG] online:users check: NO devices on channel ", channel)
                end
            end
            
            -- === ИСПРАВЛЕНИЕ 2: Декрементируем оба слота перед очисткой Архива ===
            if not has_active_session then
                ngx.log(ngx.ERR, "[ARCHIVE] === FREEING SLOT === channel=", channel)

                red:hdel("archive_history", channel)
                red:del(archive_key)
                ngx.log(ngx.ERR, "[ARCHIVE] Cleared archive_history and archive_last_seen for channel=", channel)

                -- 1. Декремент LIVE
                if alloc_json and alloc_json ~= ngx.null then
                    local alloc = cjson.decode(alloc_json)
                    ngx.log(ngx.ERR, "[ARCHIVE] Decrementing usage: provider=", alloc.provider, " slot=", alloc.slot)

                    if alloc and alloc.provider ~= "direct_url" and alloc.slot then
                        local usage_key = alloc.provider .. ":usage"
                        local current_usage = tonumber(red:lindex(usage_key, alloc.slot)) or 0
                        ngx.log(ngx.ERR, "[ARCHIVE] Current usage: ", current_usage)

                        if current_usage > 0 then
                            local new_usage = current_usage - 1
                            red:lset(usage_key, alloc.slot, tostring(new_usage))
                            ngx.log(ngx.ERR, "[ARCHIVE] Usage decremented: provider=", alloc.provider, " slot=", alloc.slot, " usage=", new_usage)
                        else
                            ngx.log(ngx.ERR, "[ARCHIVE] WARNING: current_usage is 0, not decrementing!")
                        end
                    end
                end
                
                -- 2. Декремент Архива (archive_tvclub_url) - защита от утечек!
                local arc_tvclub_json = red:hget("archive_tvclub_url", channel)
                if arc_tvclub_json and arc_tvclub_json ~= ngx.null then
                    local arc_tvclub = cjson.decode(arc_tvclub_json)
                    if arc_tvclub and arc_tvclub.provider ~= "direct_url" and arc_tvclub.slot then
                        local arc_usage_key = arc_tvclub.provider .. ":usage"
                        local current_usage = tonumber(red:lindex(arc_usage_key, arc_tvclub.slot)) or 0
                        if current_usage > 0 then
                            local new_usage = current_usage - 1
                            red:lset(arc_usage_key, arc_tvclub.slot, tostring(new_usage))
                            ngx.log(ngx.ERR, "[ARCHIVE] Archive usage decremented: provider=", arc_tvclub.provider, " slot=", arc_tvclub.slot, " usage=", new_usage)
                        end
                    end
                end

                red:hdel("channel_allocations", channel)
                ngx.log(ngx.ERR, "[ARCHIVE] Cleared channel_allocations for channel=", channel)

                red:hdel("archive_tvclub_url", channel)
                ngx.log(ngx.ERR, "[ARCHIVE] Cleared archive_tvclub_url for channel=", channel)
                
                ngx.log(ngx.ERR, "[ARCHIVE] === SLOT FREED === for channel=", channel)
            else
                red:hdel("archive_history", channel)
                red:del(archive_key)
                ngx.log(ngx.ERR, "[ARCHIVE] Session freed but active on channel, keeping slot for channel=", channel)
            end
            -- =========================================================================
        end
        
        local prov, idx, cdn_ip, t_item, alloc = select_cdn_slot(red, channel, user_agent)
        if prov and cdn_ip and alloc and alloc.source_url then
            target_cdn = cdn_ip
            source_url = alloc.source_url
            final_alloc = alloc 
            red:hset("archive_history", channel, target_cdn)
            red:expire("archive_history", 3600) 
            red:setex(archive_key, 600, tostring(now_time))
            ngx.log(ngx.ERR, "[ARCHIVE] Allocated slot: provider=", prov, " CDN=", target_cdn, " slot=", idx)
        else
            ngx.log(ngx.ERR, "[ARCHIVE] Failed to allocate slot (limit reached?)")
        end
    end
    
    if not target_cdn then
        target_cdn = get_archive_cdn(red, channel, config, nil)
        ngx.log(ngx.ERR, "[ARCHIVE] Using consistent hash CDN=", target_cdn)
        
        local chanmap_json = red:hget("config:chanmap", channel)
        if chanmap_json and chanmap_json ~= ngx.null then
            local chan_info = cjson.decode(chanmap_json)
            if chan_info and chan_info.url then
                source_url = chan_info.url
                ngx.log(ngx.ERR, "[ARCHIVE] source_url from chanmap: ", source_url)
            end
        end

        if target_cdn then
            red:hset("archive_history", channel, target_cdn)
            red:expire("archive_history", 3600)
        end
    end
    
if target_cdn and source_url then
        local sep = string.find(source_url, "?") and "&" or "?"
        local final_p_url = source_url .. sep .. "utc=" .. utc .. (lutc and "&lutc=" .. lutc or "")

        local existing_alloc_json = red:hget("channel_allocations", channel)
        if existing_alloc_json and existing_alloc_json ~= ngx.null then
            local existing_alloc = cjson.decode(existing_alloc_json)
            if existing_alloc then
                existing_alloc.is_archive = true 
                if existing_alloc.is_live == nil then
                    existing_alloc.is_live = false 
                end
                -- ОБЯЗАТЕЛЬНО сохраняем изменения обратно в базу!
                red:hset("channel_allocations", channel, cjson.encode(existing_alloc))
                ngx.log(ngx.ERR, "[ARCHIVE] Updated allocation: is_archive=true (is_live unchanged)")
            end
        else
            -- ЗАЩИТА LIVE ОТ "ЗАРАЖЕНИЯ" ССЫЛКОЙ АРХИВА
            local live_source_url = source_url
            local live_provider = final_alloc and final_alloc.provider or "tvclub"
            local live_slot = final_alloc and final_alloc.slot or 0

            -- Если канал - это direct_url, находим чистую прямую ссылку из chanmap
            -- чтобы будущие LIVE-зрители не подцепили tvclub-токен, выделенный для Архива!
            local chanmap_json = red:hget("config:chanmap", channel)
            if chanmap_json and chanmap_json ~= ngx.null then
                local chan_info = cjson.decode(chanmap_json)
                if chan_info and chan_info.url then
                    live_source_url = chan_info.url
                    live_provider = "direct_url"
                    live_slot = 0
                end
            end

            local archive_alloc = {
                provider = live_provider,
                slot = live_slot,
                cdn_ip = target_cdn,
                source_url = live_source_url, -- Здесь теперь ЧИСТЫЙ direct_url
                token = final_alloc and final_alloc.token or "",
                is_archive = true,  
                is_live = false,    
                allocated_at = ngx.time()
            }
            red:hset("channel_allocations", channel, cjson.encode(archive_alloc))
            ngx.log(ngx.ERR, "[ARCHIVE] Created safe LIVE placeholder: provider=", archive_alloc.provider, " slot=", archive_alloc.slot)
        end

        local redirect_url = "http://" .. target_cdn .. ":8123/archive_proxy/index.m3u8?token=" .. token .. "&ch=" .. channel .. "&p_url=" .. ngx.escape_uri(final_p_url)
        ngx.log(ngx.ERR, "[ARCHIVE] 301 Redirect to: ", redirect_url)

        red:setex("archive_fallback:" .. token .. ":" .. channel, 300, target_cdn)
        red:set_keepalive(10000, 50)
        return ngx.redirect(redirect_url, 301)
    else
        ngx.log(ngx.ERR, "[ARCHIVE] FAILED: No target_cdn or source_url")
    end
end

local ZAP_BURST_MAX = 6            
local ZAP_BURST_WINDOW = 5         
local ZAP_MAX_SWITCHES = 10        
local ZAP_WINDOW = 60              
local ZAP_BAN_TIME = 60            
local ZAP_BAN_TIME_REPEAT = 120    
local ZAP_MAX_VIOLATIONS = 5       
local ZAP_VIOLATIONS_RESET = 60   
local key_zap_ban = "zap:ban:"..session_id
local key_violations = "zap:violations:"..session_id
local key_last_violation = "zap:last_violation:"..session_id
local key_device_info = "zap:device:"..session_id

local device_info = cjson.encode({
    token = token, ip = client_ip, user_agent = user_agent,
    channel = channel, last_seen = now
})
red:setex(key_device_info, 3600, device_info)

if red:exists(key_zap_ban) == 1 then
    local ban_time_left = red:ttl(key_zap_ban)
    ngx.log(ngx.WARN, "[ZAPPING] DEVICE BANNED: session_id=", session_id, " time_left=", ban_time_left, "s")
    red:set_keepalive(10000, 50)
    ngx.status = 429
    ngx.header["Retry-After"] = tostring(ban_time_left > 0 and ban_time_left or ZAP_BAN_TIME)
    ngx.say("#EXTM3U\n#EXT-X-ERROR: Zapping limit. Wait " .. (ban_time_left > 0 and ban_time_left or ZAP_BAN_TIME) .. "s.\n#EXT-X-ENDLIST")
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
        return {0, 0, 0} -- Канал не изменился
    end
    redis.call('SETEX', k_last, w_sust * 2, ch)
    local burst = redis.call('INCR', k_burst)
    if burst == 1 then redis.call('EXPIRE', k_burst, w_burst) end
    local sust = redis.call('INCR', k_sust)
    if sust == 1 then redis.call('EXPIRE', k_sust, w_sust) end
    return {1, burst, sust}
]]
local k_last = "zap:last:" .. session_id
local k_burst = "zap:burst:" .. session_id
local k_sust = "zap:sust:" .. session_id
local zap_res, err = red:eval(zap_script, 3, k_last, k_burst, k_sust, channel, ZAP_BURST_WINDOW, ZAP_WINDOW)
if zap_res and zap_res[1] == 1 then
    local burst_count = zap_res[2]
    local sust_count = zap_res[3]
    ngx.log(ngx.ERR, "DEBUG ZAPPING: session=", session_id, " burst=", burst_count, " sust=", sust_count, " limit=", ZAP_MAX_SWITCHES)

    ngx.log(ngx.INFO, "[ZAPPING] Switch. Burst: ", burst_count, "/", ZAP_BURST_MAX, " Sustained: ", sust_count, "/", ZAP_MAX_SWITCHES, " sess=", session_id)
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
            ngx.log(ngx.ERR, "[ZAPPING] MAX VIOLATIONS REACHED: session_id=", session_id, " BLOCKING DEVICE")
            local d_info = cjson.encode({
                token = token, ip = client_ip, user_agent = user_agent,
                channel = channel, violations = violations, last_violation = now
            })
            red:sadd("blocked:devices:" .. token, session_id)
            red:setex("blocked:devices:" .. token .. ":info:" .. session_id, 86400, d_info) 
            red:setex("blocked:devices:" .. token .. ":reason:" .. session_id, 86400, "anti-zapping limit reached")
        end

        red:set_keepalive(10000, 50)
        ngx.status = 429
        ngx.header["Retry-After"] = tostring(ban_duration)
        ngx.say("#EXTM3U\n#EXT-X-ERROR: Too many channel switches. Banned for " .. ban_duration .. "s.\n#EXT-X-ENDLIST")
        return ngx.exit(429)
    end
end

-- === FAST ZAPPING LOGIC ===
local key_session_channel = "session:channel:" .. session_id
local key_session_token_ip = "session:token_ip:" .. token .. ":" .. client_ip
local prev_channel = red:get(key_session_channel)

-- Проверяем смену session_id (User-Agent изменился)
local prev_session_id = red:get(key_session_token_ip)
if prev_session_id and prev_session_id ~= ngx.null and prev_session_id ~= session_id then
    ngx.log(ngx.ERR, "[FAST_ZAP] session_id changed: ", prev_session_id, " → ", session_id,
           " (token=", token, " ip=", client_ip, ")")

    -- Копируем данные из старого session_id
    local old_channel = red:get("session:channel:" .. prev_session_id)

    if old_channel and old_channel ~= ngx.null then
        red:setex("session:channel:" .. session_id, 30, old_channel)
        ngx.log(ngx.ERR, "[FAST_ZAP] Migrated channel data: ", old_channel)

        -- Обновляем owner_session_id в аллокации если это владелец
        local alloc_json = red:hget("channel_allocations", old_channel)
        if alloc_json and alloc_json ~= ngx.null then
            local alloc = cjson.decode(alloc_json)
            if alloc.owner_session_id == prev_session_id then
                alloc.owner_session_id = session_id
                red:hset("channel_allocations", old_channel, cjson.encode(alloc))
                ngx.log(ngx.ERR, "[FAST_ZAP] Updated owner_session_id in allocation")
            end
        end
    end

    -- Удаляем старые ключи
    red:del("session:channel:" .. prev_session_id)

    -- Обновляем prev_channel для дальнейшей логики
    prev_channel = old_channel
end

-- Сохраняем привязку token+ip → session_id
red:setex(key_session_token_ip, 60, session_id)

-- Проверяем: переключился ли пользователь на другой канал?
if prev_channel and prev_channel ~= ngx.null and prev_channel ~= channel then
    ngx.log(ngx.ERR, "[FAST_ZAP] Device switched: ", prev_channel, " → ", channel,
           " session=", session_id)

    local prev_alloc_key = prev_channel
    local prev_alloc_json = red:hget("channel_allocations", prev_channel)

    -- Chain hot switch (100→200→300): аллокация хранится под оригинальным ключом
    if not (prev_alloc_json and prev_alloc_json ~= ngx.null) then
        local alloc_key = red:get("session:alloc_key:" .. session_id)
        if alloc_key and alloc_key ~= ngx.null then
            local existing_json = red:hget("channel_allocations", alloc_key)
            if existing_json and existing_json ~= ngx.null then
                local existing = cjson.decode(existing_json)
                if existing and existing.current_channel == prev_channel and existing.owner_session_id == session_id then
                    prev_alloc_json = existing_json
                    prev_alloc_key = alloc_key
                    ngx.log(ngx.ERR, "[FAST_ZAP] Found allocation via reverse mapping: alloc_key=", alloc_key,
                           " current_channel=", prev_channel)
                end
            end
        end
    end

    if prev_alloc_json and prev_alloc_json ~= ngx.null then
        local prev_alloc = cjson.decode(prev_alloc_json)

        -- Проверяем: прокси на старом канале не умирает?
        if prev_alloc.dying == true then
            ngx.log(ngx.ERR, "[FAST_ZAP] Previous allocation is DYING for channel ", prev_channel,
                   " → skipping HOT SWITCH, allocating new slot")
            goto normal_allocation
        end

        -- Проверяем: это владелец прокси?
        local is_owner = (prev_alloc.owner_session_id == session_id)

        if not is_owner then
            ngx.log(ngx.ERR, "[FAST_ZAP] session ", session_id, " is NOT owner of channel ", prev_channel,
                   " (owner: ", prev_alloc.owner_session_id or "none", "), cannot SWITCH")
            -- Обычная аллокация для нового канала
            goto normal_allocation
        end

        ngx.log(ngx.ERR, "[FAST_ZAP] session ", session_id, " is OWNER, can do HOT SWITCH")

        -- ФИКС 1: Если прокси мёртв (is_live=false), hot switch невозможен
        -- Очистка аллокации и декремент слота — ответственность STOP handler в nginx.conf
        if not prev_alloc.is_live then
            ngx.log(ngx.ERR, "[FAST_ZAP] Proxy is NOT LIVE for channel ", prev_channel,
                   " (is_live=false), cannot HOT SWITCH — normal allocation for new channel")
            goto normal_allocation
        end

        -- ФИКС 2: Если идёт архивный просмотр (is_archive=true), hot switch запрещён
        if prev_alloc.is_archive then
            ngx.log(ngx.ERR, "[FAST_ZAP] Archive active on channel ", prev_channel,
                   " (is_archive=true), cannot HOT SWITCH — normal allocation for new channel")
            goto normal_allocation
        end

        -- Проверяем: есть ли другие сессии на старом канале?
        if has_other_sessions_on_channel(red, prev_channel, session_id) then
            ngx.log(ngx.ERR, "[FAST_ZAP] Other sessions exist on channel ", prev_channel,
                   ", transferring ownership")

            -- Находим первую другую сессию для передачи владения
            local all_tokens = red:keys("online:users:*")
            local new_owner = nil
            for _, token_key in ipairs(all_tokens) do
                local meta_key = token_key .. ":meta"
                local all_meta = red:hgetall(meta_key)
                if all_meta and #all_meta > 0 then
                    for i = 1, #all_meta, 2 do
                        local sess_id = all_meta[i]
                        local meta_json = all_meta[i+1]
                        if sess_id ~= session_id and meta_json then
                            local m = cjson.decode(meta_json)
                            if m and m.channel == prev_channel then
                                new_owner = sess_id
                                break
                            end
                        end
                    end
                end
                if new_owner then break end
            end

            if new_owner then
                prev_alloc.owner_session_id = new_owner
                red:hset("channel_allocations", prev_alloc_key, cjson.encode(prev_alloc))
                ngx.log(ngx.ERR, "[FAST_ZAP] Ownership transferred to ", new_owner)
            end

            -- Обычная аллокация для нового канала (без HOT SWITCH)
            goto normal_allocation
        end

        -- Нет других сессий → можем делать HOT SWITCH
        ngx.log(ngx.ERR, "[FAST_ZAP] No other sessions, proceeding with HOT SWITCH")

-- Проверяем: можем ли переиспользовать слот?
        if prev_alloc.provider ~= "direct_url" then
            -- Получаем информацию о новом канале
            local chanmap_json = red:hget("config:chanmap", channel)
            local can_reuse = true
            local chan_info = nil

            if chanmap_json and chanmap_json ~= ngx.null then
                chan_info = cjson.decode(chanmap_json)
                if chan_info then
                    -- 1. Если у канала есть своя прямая ссылка, слот провайдера не подходит
                    if chan_info.url then
                        can_reuse = false
                        ngx.log(ngx.ERR, "[FAST_ZAP_ABORT] Channel ", channel, " has direct URL. Hot switch aborted.")
                    end
                    
                    -- 2. Если в конфиге канала жестко прописан другой провайдер
                    if chan_info.provider and chan_info.provider ~= prev_alloc.provider then
                        can_reuse = false
                        ngx.log(ngx.ERR, "[FAST_ZAP_ABORT] Channel ", channel, " strictly requires provider '", chan_info.provider, "'. Hot switch from '", prev_alloc.provider, "' aborted.")
                    end

                    -- 3. КРИТИЧЕСКИЙ ФИКС: Защита от "всеядного" tvclub
                    -- Если мы сидим на слоте tvclub, но новый канал явно отмаркирован для admuspeh или elbrus
                    if prev_alloc.provider == "tvclub" then
                        if chan_info.admuspeh or chan_info.elbrus then
                            can_reuse = false
                            ngx.log(ngx.ERR, "[FAST_ZAP_ABORT] Channel ", channel, " belongs to higher priority provider (admuspeh/elbrus). Hot switch from tvclub aborted.")
                        end
                    end
                    
                    -- 4. Защита для elbrus (если канал не имеет пути elbrus, он не может быть сыгран)
                    if prev_alloc.provider == "elbrus" and not chan_info.elbrus then
                        can_reuse = false
                        ngx.log(ngx.ERR, "[FAST_ZAP_ABORT] Channel ", channel, " lacks elbrus path. Hot switch from elbrus aborted.")
                    end
                end
            end

            if can_reuse then
                ngx.log(ngx.ERR, "[FAST_ZAP_INIT] Attempting HOT SWITCH: Old_Ch=", prev_channel, " -> New_Ch=", channel, " | Retaining Provider=", prev_alloc.provider, " Slot=", prev_alloc.slot)

                local providers_json = red:hgetall("config:providers")
                local new_source_url = nil

                for i = 1, #providers_json, 2 do
                    if providers_json[i] == prev_alloc.provider then
                        local cfg = cjson.decode(providers_json[i+1])
                        if cfg then
                            local items = cfg.tokens or cfg.bases or {}
                            local item = items[prev_alloc.slot + 1]
                            local base_url = type(item) == "table" and item.url or item

                            -- Чистая генерация ссылок с использованием уже загруженного chan_info
                            if prev_alloc.provider == "tvclub" then
                                new_source_url = resolve_tvclub(channel, base_url)
                            elseif prev_alloc.provider == "admuspeh" then
                                local ch_id = chan_info and (chan_info.admuspeh or chan_info.id)
                                new_source_url = resolve_admuspeh(ch_id, base_url)
                            elseif prev_alloc.provider == "elbrus" then
                                local ch_path = chan_info and chan_info.elbrus
                                new_source_url = resolve_elbrus(ch_path, base_url, cfg.origin)
                            else
                                new_source_url = urljoin(base_url, channel .. "/index.m3u8")
                            end
                        end
                        break
                    end
                end

                if new_source_url then
                    -- === КРИТИЧЕСКОЕ ИСПРАВЛЕНИЕ: БЛОКИРОВКА ЦЕЛЕВОГО КАНАЛА ===
                    local target_lock_key = "lock:alloc:" .. channel
                    local lock_acquired, err = red:set(target_lock_key, "1", "EX", 3, "NX")
                    
                    -- ИСПРАВЛЕННАЯ ПРОВЕРКА ДЛЯ REDIS
                    if lock_acquired ~= "OK" then
                        ngx.log(ngx.ERR, "[FAST_ZAP_RACE] Target channel ", channel, " is locked by another process! Aborting HOT SWITCH to prevent slot mesh.")
                        goto normal_allocation
                    end

                    -- Проверяем: не выделен ли целевой канал другим клиентом УЖЕ
                    local target_alloc_json = red:hget("channel_allocations", channel)
                    if target_alloc_json and target_alloc_json ~= ngx.null then
                        local target_alloc = cjson.decode(target_alloc_json)
                        if target_alloc and target_alloc.cdn_ip then
                            -- Если целевая алокация dying — игнорируем её, выполняем HOT SWITCH
                            if target_alloc.dying == true then
                                ngx.log(ngx.ERR, "[FAST_ZAP] Target channel ", channel,
                                       " has DYING allocation, ignoring it for HOT SWITCH (no hdel)")
                            else
                                ngx.log(ngx.ERR, "[FAST_ZAP_ABORT] Target channel ", channel, " already active (Slot ", target_alloc.slot, "). Hot switch aborted, joining existing stream.")

                                target_alloc.is_live = true
                                if target_alloc.is_archive == nil then target_alloc.is_archive = false end
                                red:hset("channel_allocations", channel, cjson.encode(target_alloc))

                                red:setex(key_session_channel, 30, channel)
                                red:del(target_lock_key) -- Снимаем лок

                                red:set_keepalive(10000, 50)
                                ngx.header["Content-Type"] = "application/vnd.apple.mpegurl"
                                ngx.say("#EXTM3U\n#EXT-X-VERSION:3\n#EXT-X-STREAM-INF:BANDWIDTH=20000000\n" ..
                                        "http://" .. target_alloc.cdn_ip .. ":8123/" .. channel .. "/playlist.m3u8" .. token_suffix)
                                return ngx.exit(200)
                            end
                        end
                    end

                    -- Целевой канал свободен и заблокирован нами — ВЫПОЛНЯЕМ HOT SWITCH
                    local old_slot_memory = prev_alloc.slot
                    local old_token_memory = prev_alloc.token
                    
                    prev_alloc.source_url = new_source_url
                    prev_alloc.current_channel = channel
                    prev_alloc.is_live = true
                    prev_alloc.is_archive = false
                    prev_alloc.last_switch_at = ngx.time()
                    prev_alloc.owner_session_id = session_id

                    if not prev_alloc.root_id then prev_alloc.root_id = prev_alloc_key end
                    prev_alloc.switch_count = (prev_alloc.switch_count or 0) + 1

                    -- Атомарно переносим аллокацию (dying алокации перезаписываются)
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
                    local move_result = red:eval(move_alloc_script, 2, prev_alloc_key, channel, cjson.encode(prev_alloc))

                    if move_result == 0 then
                        ngx.log(ngx.ERR, "[FAST_ZAP_RACE] Redis EVAL failed for channel ", channel, " (target created during check). Aborting.")
                        red:del(target_lock_key)
                        goto normal_allocation
                    end

                    red:del(target_lock_key) -- Успешно перенесли, снимаем лок
                    prev_alloc_key = channel

                    -- Reverse mapping для поиска аллокации по session_id
                    red:setex("session:alloc_key:" .. session_id, 300, channel)

                    -- ВАЖНО: Явно логируем, что именно мы шлем в C++
                    ngx.log(ngx.ERR, "[FAST_ZAP_SUCCESS] Sending SWITCH | Old_Ch: ", prev_channel, " -> New_Ch: ", channel, " | Locked Slot: ", old_slot_memory)

-- Если с момента прошлого запуска прошло менее 3-х секунд (прокси может еще грузиться)
if (ngx.time() - (prev_alloc.allocated_at or 0)) < 3 then
    ngx.sleep(0.8) -- Ждем полсекунды, чтобы прокси точно подписался на канал
end		    
                    red:publish("channel_control", cjson.encode({
                        action = "SWITCH",
                        channel = prev_channel,
                        new_channel = channel,
                        new_source_url = new_source_url,
                        session_id = session_id,
                        provider = prev_alloc.provider,
                        slot = old_slot_memory,       -- Шлем строго СТАРЫЙ слот
                        new_token = old_token_memory  -- Шлем строго СТАРЫЙ токен
                    }))

                    red:setex(key_session_channel, 30, channel)

                    red:set_keepalive(10000, 50)
                    ngx.header["Content-Type"] = "application/vnd.apple.mpegurl"
                    ngx.say("#EXTM3U\n#EXT-X-VERSION:3\n#EXT-X-STREAM-INF:BANDWIDTH=20000000\n" ..
                            "http://" .. prev_alloc.cdn_ip .. ":8123/" .. channel .. "/playlist.m3u8?token=" .. token)
                    return ngx.exit(200)
                end
            end
        end
    end
end

::normal_allocation::
-- Обновляем текущий канал для session_id
red:setex(key_session_channel, 30, channel)

local function get_archive_cdn(red, channel, config, excluded_cdn)
    local cached_cdn = red:hget("archive_history", channel)
    if cached_cdn and cached_cdn ~= ngx.null then
        if config[cached_cdn] and config[cached_cdn].active ~= false then
            local updated = red:hget("cdn_stats:" .. cached_cdn, "updated")
            if updated and updated ~= ngx.null then
                return cached_cdn
            end
            ngx.log(ngx.WARN, "[ARCHIVE] Cached CDN ", cached_cdn, " is DEAD. Searching new...")
        end
    end
    
    local weighted_list = {}
    for ip, cfg in pairs(config) do
        if ip ~= excluded_cdn and cfg.active ~= false and is_time_allowed(cfg.schedule) then
            local updated = red:hget("cdn_stats:" .. ip, "updated")
            if updated and updated ~= ngx.null then
                local w = tonumber(cfg.weight) or 1
                if w < 1 then w = 1 end
                for i = 1, w do table.insert(weighted_list, ip) end
            end
        end
    end
    
    if #weighted_list == 0 then
        if excluded_cdn then return get_archive_cdn(red, channel, config, nil) end
        for ip, cfg in pairs(config) do
            if cfg.active ~= false then 
                local w = tonumber(cfg.weight) or 1
                if w < 1 then w = 1 end
                for i = 1, w do table.insert(weighted_list, ip) end
            end
        end
        if #weighted_list == 0 then return nil end
    end
    
    -- Сортировка обязательна для консистентного хэширования
    table.sort(weighted_list)
    local channel_hash = ngx.md5(tostring(channel))
    local hash_num = tonumber(channel_hash:sub(1, 8), 16)
    
    -- Выбираем сервер из массива с учетом весов
    local cdn_index = (hash_num % #weighted_list) + 1
    return weighted_list[cdn_index]
end

-- === ИСПРАВЛЕНИЕ 3: АЛЛОКАЦИЯ КАНАЛА (LIVE) ===
local alloc_json = red:hget("channel_allocations", channel)
local alloc_stored_key = channel
local allocation = nil

-- Reverse lookup: аллокация может быть под другим ключом после hot switch
if not (alloc_json and alloc_json ~= ngx.null) then
    local redirect = red:get("session:alloc_key:" .. session_id)
    if redirect and redirect ~= ngx.null then
        local existing_json = red:hget("channel_allocations", redirect)
        if existing_json and existing_json ~= ngx.null then
            local existing = cjson.decode(existing_json)
            if existing and existing.current_channel == channel and existing.owner_session_id == session_id then
                alloc_json = existing_json
                alloc_stored_key = redirect
                ngx.log(ngx.ERR, "[ALLOC] Found hot-switched allocation via reverse mapping: stored_key=", redirect)
            end
        end
    end
end

if alloc_json and alloc_json ~= ngx.null then
    local ok, res_alloc = pcall(cjson.decode, alloc_json)
    if ok and res_alloc.cdn_ip then
        -- Проверка: если прокси помечен как dying — не переиспользовать, выделяем новую алокацию
        if res_alloc.dying == true then
            ngx.log(ngx.ERR, "[ALLOC_DYING] Allocation is DYING for channel=", channel,
                    " provider=", tostring(res_alloc.provider),
                    " slot=", tostring(res_alloc.slot),
                    " cdn=", tostring(res_alloc.cdn_ip),
                    " dying_at=", tostring(res_alloc.dying_at),
                    " → allocating NEW slot")
        else
            local was_live_active = (res_alloc.is_live == true)
            allocation = res_alloc
            allocation.is_live = true  
            if allocation.is_archive == nil then
                allocation.is_archive = false  
            end
            
            ngx.log(ngx.ERR, "[ALLOC_REUSE] LIVE REUSE: channel=", channel,
                    " provider=", tostring(res_alloc.provider),
                    " slot=", tostring(res_alloc.slot),
                    " cdn=", tostring(res_alloc.cdn_ip),
                    " is_live=", tostring(allocation.is_live),
                    " is_archive=", tostring(allocation.is_archive),
                    " pid=", ngx.worker.pid())

            red:hset("channel_allocations", alloc_stored_key, cjson.encode(allocation))
            ngx.log(ngx.ERR, "[LIVE] Reusing existing slot: provider=", res_alloc.provider, " slot=", res_alloc.slot, " CDN=", res_alloc.cdn_ip)
            if not was_live_active then
                ngx.log(ngx.ERR, "[LIVE] Waking up sleeping proxy for channel=", channel, " (Inherited from Archive)")
                signal_cdn(allocation, "no_hash", channel, red)
            end
        end
    end
end

if not allocation then
    local lock_key = "lock:alloc:" .. channel
    local lock_acquired, err = red:set(lock_key, "1", "EX", 3, "NX")

    if lock_acquired == "OK" then
        local double_check_json = red:hget("channel_allocations", channel)
        if double_check_json and double_check_json ~= ngx.null then
            local dc_ok, dc_alloc = pcall(cjson.decode, double_check_json)
            if dc_ok and dc_alloc and not dc_alloc.dying then
                allocation = dc_alloc
                red:del(lock_key)
                goto signal_phase
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

        new_alloc.is_live = true
        new_alloc.is_archive = false
        new_alloc.current_channel = channel
        new_alloc.owner_session_id = session_id
        new_alloc.root_id = channel
        new_alloc.switch_count = 0
	new_alloc.last_switch_at = new_alloc.allocated_at
        allocation = new_alloc

        ngx.log(ngx.ERR, "[ALLOC_WRITE] LIVE SAVED: channel=", channel,
                " provider=", allocation.provider,
                " slot=", allocation.slot,
                " cdn=", allocation.cdn_ip,
                " is_live=", tostring(allocation.is_live),
                " is_archive=", tostring(allocation.is_archive),
                " pid=", ngx.worker.pid())

        red:hset("channel_allocations", channel, cjson.encode(allocation))

        -- Reverse mapping для поиска аллокации по session_id
        red:setex("session:alloc_key:" .. session_id, 300, channel)
        
        -- СИГНАЛ ОТПРАВЛЯЕТСЯ ТОЛЬКО ОДИН РАЗ, ПРИ НОВОМ ВЫДЕЛЕНИИ
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
        "http://" .. allocation.cdn_ip .. ":8123/" .. channel .. "/playlist.m3u8" .. token_suffix)
