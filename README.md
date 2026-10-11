# Bracket Validation with Docker + Nginx + PHP-FPM + Redis

## Run

```bash
docker compose up --build -d
```

## Test valid sequence

```bash
curl -i -X POST 'http://localhost/' --data-urlencode 'string=(()()())'
```

## Test invalid sequence

```bash
curl -i -X POST 'http://localhost/' --data-urlencode 'string=)(('
```

## Notes

- The main entry point is the NGINX load balancer on port 80.
- Requests are forwarded to backend NGINX instances, then to PHP-FPM workers.
- Session storage is configured in Redis.
- The service returns 200 for valid sequences and 400 for invalid ones.
