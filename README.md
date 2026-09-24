# AzerothCore Registration Portal

A player registration website for an [AzerothCore](https://www.azerothcore.org) (WotLK 3.3.5a) server, packaged to run in Docker behind [Traefik](https://traefik.io) with HTTPS.

Players can create an account, change their password, see who's online and check the top players. Accounts are written straight into `acore_auth.account` using SRP6 (salt + verifier), the same way AzerothCore does it, so you don't need to enable SOAP.

This is a Docker-focused fork of [masterking32/WoWSimpleRegistration](https://github.com/masterking32/WoWSimpleRegistration). All the PHP and the templates come from that project.

![IceCrown template](screenshots/i1.jpg)

## Requirements

- **AzerothCore running in Docker** on the same machine. The portal joins AzerothCore's Docker network and connects to the `ac-database` container directly, so no MySQL port has to be exposed.
- **Traefik** running in Docker, with a `websecure` entrypoint and a `letsencrypt` certificate resolver.
- **A DNS record** pointing at the server, e.g. `register.example.com`.
- Docker with the Compose plugin.

## Setup

### 1. Clone the repo on your server

```bash
git clone https://github.com/buildthehomelab/wow-mod-azerothcore-portal.git
cd wow-mod-azerothcore-portal
```

### 2. Create your config files

```bash
cp .env.example .env
cp docker/create-db-user.sql.example docker/create-db-user.sql
```

Both files are gitignored and are not copied into the Docker image.

### 3. Pick a database password

Generate a password, e.g. with `openssl rand -base64 24`. Put it in two places:

- `DB_PASS=` in `.env`
- `CHANGE_ME` in `docker/create-db-user.sql`

### 4. Fill in `.env`

| Variable | What to set |
|---|---|
| `TRAEFIK_NETWORK` | The Docker network Traefik is on (`docker network ls`). |
| `SERVICE_NAME` | Subdomain for the site, e.g. `register`. |
| `DOMAIN` | Your domain, e.g. `example.com`. The site is served at `https://SERVICE_NAME.DOMAIN`. |
| `AC_NETWORK_NAME` | AzerothCore's network. Find it with `docker network ls \| grep ac-network`. It's usually `<azerothcore folder>_ac-network` (on TrueNAS SCALE, `ix-azerothcore_ac-network`). |
| `REALMLIST` | The address players put in `realmlist.wtf`: your server's public IP or hostname. Leave empty to use the address from [mod-realm-config](#portalkeeper-and-mod-realm-config). |
| `REALM_NAME` | Realm name shown on the site. Leave empty to use the name from mod-realm-config. |
| `SITE_TITLE` | Title shown in the browser tab and header. |
| `CONTACT_EMAIL` | Email address shown on the contact page. Leave empty to hide the contact page and its menu link. |
| `TEMPLATE` | `icecrown` (default), `light`, `advance`, `kaelthas`, `battleforazeroth`, `legion` or `legion-advance`. |
| `LANGUAGE` | Default site language: `english`, `persian`, `italian`, `chinese-simplified`, `chinese-traditional`, `swedish`, `french`, `german`, `spanish`, `korean`, `russian` or `portugues`. Players can switch language on the site unless `LANGUAGE_CHANGER=false`. |
| `LANGUAGE_CHANGER` | Set to `false` to hide the language changer, so everyone sees the site in `LANGUAGE`. |
| `PATCH_URL` | Optional. Download link for a client patch, shown in the "How to connect" section. |
| `DISABLE_TOP_PLAYERS` / `DISABLE_ONLINE_PLAYERS` / `DISABLE_CHANGEPASSWORD` | Set any of these to `true` to hide that page. |
| `REALM_ID` | The realm's ID in `acore_auth.realmlist`. Leave at `1` unless you run more than one realm. |
| `MULTIPLE_EMAIL_USE` | Set to `true` to let several accounts register with the same email address. |
| `CAPTCHA_TYPE` | `0` built-in image captcha (default), `1` hCaptcha, `2` reCAPTCHA v2, `3` Cloudflare Turnstile, `4` off. |
| `CAPTCHA_KEY` / `CAPTCHA_SECRET` | Site key and secret key from your captcha provider. Only needed for types 1–3. |
| `DB_USER` / `DB_PASS` | Leave `DB_USER` as `wow_register` unless you changed it in the SQL file. |
| `DB_PORT` | AzerothCore's MySQL port inside the Docker network. Leave at `3306` unless you changed it. |
| `REALM_CONFIG_DIR` | Optional. Host folder mod-realm-config writes `<realm_key>.realm.conf` to. See [below](#portalkeeper-and-mod-realm-config). |
| `DEBUG_MODE` | Set to `true` to show PHP errors while troubleshooting. Turn it back off afterwards. |

### 5. Create the database user

```bash
docker exec -i ac-database mysql -uroot -p < docker/create-db-user.sql
```

It prompts for your AzerothCore MySQL root password. The new `wow_register` user can only read and write `acore_auth.account` and read `acore_characters`. It can't touch anything else.

### 6. Start it

AzerothCore must be up first, because it creates the network the portal joins.

```bash
docker compose up -d --build
```

Open `https://SERVICE_NAME.DOMAIN` and register a test account, then log in with it in the game client.

## Portalkeeper and mod-realm-config

If your server runs [mod-realm-config](https://github.com/Hisha/mod-realm-config), the portal can host its `realm.conf` for the [Portalkeeper](https://github.com/Hisha/Portalkeeper) launcher. Players then get a "Play with Portalkeeper" section under **How to connect**. It has a Portalkeeper download link, a `realm.conf` download and the list of addons and patches the realm uses.

The module writes `<realm_key>.realm.conf` to a folder but doesn't serve it over the web. (The module's README calls it `realm.conf`, but the code names it after `realm_key`.) The portal mounts that folder read-only and serves it at `https://SERVICE_NAME.DOMAIN/realm/`.

These steps assume AzerothCore runs from the [azerothcore-wotlk](https://github.com/azerothcore/azerothcore-wotlk) repo's own `docker-compose.yml`, checked out at `~/azerothcore-wotlk`. Adjust the paths if yours is somewhere else.

1. **Add the module and rebuild AzerothCore.** The Docker build compiles everything in `modules/`:

   ```bash
   cd ~/azerothcore-wotlk/modules
   git clone https://github.com/Hisha/mod-realm-config.git
   ```

2. **Create the output folder.** Worldserver runs as UID 1000 (`DOCKER_USER_ID`), so it has to own the folder. If Docker creates the folder instead, root owns it and the module can't write to it.

   ```bash
   mkdir -p ~/azerothcore-wotlk/env/dist/realm-public
   sudo chown 1000:1000 ~/azerothcore-wotlk/env/dist/realm-public
   ```

3. **Mount it into worldserver.** AzerothCore says not to edit its `docker-compose.yml`, so create `~/azerothcore-wotlk/docker-compose.override.yml` (or add to yours):

   ```yaml
   services:
     ac-worldserver:
       volumes:
         - ./env/dist/realm-public:/azerothcore/env/dist/realm-public
   ```

4. **Configure the module.** Copy `modules/mod-realm-config/conf/mod_realm_config.conf.dist` to `env/dist/etc/modules/mod_realm_config.conf` and set:

   ```ini
   RealmConfig.OutputDirectory = "/azerothcore/env/dist/realm-public/"
   ```

5. **Rebuild and start AzerothCore:**

   ```bash
   cd ~/azerothcore-wotlk
   docker compose up -d --build
   ```

   The `ac-db-import` container should create the module's tables in `acore_world`. If `SHOW TABLES LIKE 'mod_realm_config%';` in `acore_world` comes back empty, import `modules/mod-realm-config/data/sql/db-world/base/mod_realm_config.sql` into `acore_world` yourself. It's safe to run twice.

6. **Fill in your realm's details** in the world database. `address` is what players connect to. `realm_key` names the file (lowercase letters, digits, `-` or `_`). The module starts it at `example`, which is why you get `example.realm.conf`. Use your own key, hostname and the portal's URL:

   ```sql
   UPDATE acore_world.mod_realm_config
   SET realm_key   = 'myrealm',
       name        = 'My Realm',
       address     = 'wow.example.com',
       auth_port   = 3724,
       world_port  = 8085,
       config_url  = 'https://register.example.com/realm/myrealm.realm.conf',
       website_url = 'https://register.example.com/'
   WHERE id = 1;
   ```

   The module notices the change within `RealmConfig.RefreshIntervalSeconds` (30s by default) and writes `myrealm.realm.conf`. There's no need to restart anything. If you changed the key, the module leaves the old `example.realm.conf` in place, so delete it.

7. **Point the portal at the folder** in this repo's `.env` (an absolute host path), then restart the portal:

   ```bash
   REALM_CONFIG_DIR=/home/you/azerothcore-wotlk/env/dist/realm-public
   ```

   ```bash
   docker compose up -d
   ```

   Open `https://SERVICE_NAME.DOMAIN/realm/myrealm.realm.conf`. It should show the file. The portal uses the newest `*.realm.conf` in the folder. To pin a specific one, set `REALM_KEY=myrealm` in `.env`.

   With this compose file, `AC_NETWORK_NAME` is `<folder name>_ac-network`, e.g. `azerothcore-wotlk_ac-network`. Check with `docker network ls`.

With `REALMLIST` and `REALM_NAME` left empty in `.env`, the site uses the realm's address and name from `realm.conf`, so you only have to set them in one place.

Everything in that folder is public, so only point it at a folder that holds public files. Other modules that write public files there, such as news or armory feeds, are served at `/realm/<file>` too. Directory listings and PHP are turned off for that path. The files need to be readable by other users (e.g. mode `644`) so the web server can read them.

## Updating

```bash
git pull
docker compose up -d --build
```

If you only changed `.env` (template, title, and so on), `docker compose up -d` is enough and no rebuild is needed.

## Troubleshooting

- **Blank page:** set `DEBUG_MODE=true` in `.env`, run `docker compose up -d`, reload the page and read the error. Then turn it off again.
- **Styling or images missing:** the site builds its links from `SERVICE_NAME` and `DOMAIN`. Make sure they match the URL you're visiting.
- **Database connection error:** check that `AC_NETWORK_NAME` is right, that the container is on it (`docker inspect wow-register`), and that `DB_PASS` matches the password in `create-db-user.sql`.
- **No Portalkeeper section / 404 on `/realm/<realm_key>.realm.conf`:** check that `REALM_CONFIG_DIR` is the host folder the module writes to, that `<realm_key>.realm.conf` exists there (and matches `REALM_KEY` if you set it) and is readable, and that you ran `docker compose up -d` after changing it. `docker exec wow-register ls -l /var/www/html/realm` shows what the container sees.
- **Logs:** `docker logs wow-register`

## What's different from upstream

- Docker image (PHP 8.3 + Apache) with Composer dependencies installed at build time.
- All config comes from environment variables ([docker/config.php](docker/config.php)) instead of an edited `config.php`.
- Served only through Traefik over HTTPS. No ports are published.
- Apache blocks direct access to `application/`, `docker/`, dotfiles and Markdown files ([docker/apache-security.conf](docker/apache-security.conf)).
- Locked to AzerothCore with SRP6, using a least-privilege database user instead of root.
- Uses the built-in image captcha by default, so there are no third-party captcha keys to set up. You can switch to hCaptcha, reCAPTCHA or Turnstile with `CAPTCHA_TYPE`.
- Can host [mod-realm-config](https://github.com/Hisha/mod-realm-config)'s `realm.conf` and show Portalkeeper setup steps.
- **Vote system is off,** because it alters `acore_auth.account` and creates new tables.
- **No email.** "Restore password" and two-factor auth are turned off because they need SMTP. Players can still change their password if they know the current one. If someone forgets theirs, reset it from the worldserver console (`account set password <user> <new> <new>`).

## Credits and license

Built on [WoWSimpleRegistration](https://github.com/masterking32/WoWSimpleRegistration) by [Amin.MasterkinG](https://masterking32.com) and its contributors and translators. See the upstream README for the full list.

Licensed under the GPL-3.0, the same as upstream. See [LICENSE](LICENSE).
