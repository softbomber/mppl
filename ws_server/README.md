# WebSocket Balance Server

Lightweight C++ WebSocket server that pushes deposit balance updates to connected dealers in real-time.

## Build

```bash
sudo apt-get install -y libmysqlclient-dev libssl-dev
cd ws_server
make
```

## Run

```bash
# Environment variables (all optional, defaults shown):
#   WS_PORT=9800   DB_HOST=127.0.0.1   DB_USER=root
#   DB_PASS=       DB_NAME=mpol         POLL_SEC=5

./ws_balance
```

## Deploy as systemd service

```bash
sudo cp ws_balance.service /etc/systemd/system/
sudo systemctl daemon-reload
sudo systemctl enable --now ws_balance
```

The service reads DB credentials from `/var/www/.env` (same file as the PHP app).

## Nginx proxy (wss://)

Add to your nginx server block:

```nginx
location /ws {
    proxy_pass http://127.0.0.1:9800;
    proxy_http_version 1.1;
    proxy_set_header Upgrade $http_upgrade;
    proxy_set_header Connection "upgrade";
    proxy_set_header Host $host;
    proxy_read_timeout 86400;
}
```

Then set in `.env`:
```
WS_URL=wss://mpol.co/ws
```

## How it works

1. Browser opens WebSocket to `wss://mpol.co/ws`
2. Client sends `{"dealer_id": 123}`
3. Server polls MySQL every 5 seconds for connected dealers
4. Pushes `{"s":"123.45","i":5}` only when balance or discount changes
5. If `WS_URL` is not set in `.env`, JS falls back to AJAX polling (30 sec)
