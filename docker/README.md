# Docker deployment (AzerothCore)

Run this on the **same machine as AzerothCore**. The container joins AzerothCore's
`ac-network` and talks to `ac-database` directly; no MySQL port needs to be exposed.

Accounts are written straight to `acore_auth.account` using SRP6 (salt + verifier),
exactly as AzerothCore expects, so SOAP is not required.

## 1. Copy this folder to the server

```bash
rsync -av --exclude .git ./ user@your-server:~/wow-register/
```

Create your local secret files from the templates (both are gitignored and excluded from the Docker image):

```bash
cp .env.example .env
cp docker/create-db-user.sql.example docker/create-db-user.sql
```

Pick a strong DB password and put it in both `DB_PASS` (`.env`) and `create-db-user.sql`.

## 2. Edit `.env` on the server

| Variable | Set to |
|---|---|
| `BASE_URL` | Public URL of the page, e.g. `http://your-server:8080` or `https://register.example.com`. Must be right or CSS/images won't load. |
| `REALMLIST` | Address players put in `realmlist.wtf` (server's public IP or hostname). |
| `AC_NETWORK_NAME` | Output of `docker network ls \| grep ac-network` (it's `<azerothcore folder>_ac-network`). |
| `TEMPLATE` | `icecrown`, `light`, `advance`, `kaelthas`, `battleforazeroth`, `legion`, `legion-advance`. |

## 3. Create the database user

```bash
docker exec -i ac-database mysql -uroot -p < docker/create-db-user.sql
```

Grants only `SELECT/INSERT/UPDATE` on `acore_auth.account` and read-only on `acore_characters`.

## 4. Start

```bash
docker compose up -d --build
```

## Notes

- Put it behind HTTPS (Caddy / nginx / Cloudflare) before sharing it — players send passwords through this form.
- "Forgot password" needs `SMTP_*` set in `.env`, and the app adds a `restore_key` column to
  `acore_auth.account` on first use, which requires `ALTER` on that table.
- The vote system is disabled because it alters `acore_auth.account` and creates tables.
- Change `TEMPLATE` etc. with `docker compose up -d` (no rebuild needed); upstream code changes need `--build`.
