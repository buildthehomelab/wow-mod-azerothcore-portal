# AzerothCore Registration Portal

A player registration website for an [AzerothCore](https://www.azerothcore.org) (WotLK 3.3.5a) server, packaged to run in Docker behind [Traefik](https://traefik.io) with HTTPS.

It's a single page: server info, how to connect (with [Portalkeeper](#portalkeeper-and-mod-realm-config) setup if you use it), and **Register** / **Change Password** popups in the top menu. It can also show who's online and the top players. Registration only asks for a username and password, and you can close it with one setting so only people you create accounts for can play.

Accounts are written straight into `acore_auth.account` using SRP6 (salt + verifier), the same way AzerothCore does it, so you don't need to enable SOAP.

This is a trimmed-down, Docker-focused fork of [masterking32/WoWSimpleRegistration](https://github.com/masterking32/WoWSimpleRegistration). The PHP and the `advance` template come from that project. See [What's different from upstream](#whats-different-from-upstream).

![Portal](screenshots/a-lichking-min.jpg)

## Requirements

- **AzerothCore running in Docker** on the same machine. The portal joins AzerothCore's Docker network and connects to the `ac-database` container directly, so no MySQL port has to be exposed.
- **Traefik** running in Docker, with a `websecure` entrypoint and a `letsencrypt` certificate resolver.
- **A DNS record** pointing at the server, e.g. `register.example.com`.
- Docker with the Compose plugin.

## Installation

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
| `AC_NETWORK_NAME` | AzerothCore's network. Find it with `docker network ls \| grep ac-network`. It's usually `<azerothcore folder>_ac-network`. |
| `REALMLIST` | The address players put in `realmlist.wtf`: your server's public IP or hostname. Leave empty to use the address from [mod-realm-config](#portalkeeper-and-mod-realm-config). |
| `REALM_NAME` | Realm name shown on the site. Leave empty to use the name from mod-realm-config. |
| `SITE_TITLE` | Title shown in the browser tab and header. |
| `CONTACT_EMAIL` | Email address shown on the contact page. Leave empty to hide the contact page and its menu link. |
| `PATCH_URL` | Optional. Download link for a client patch, shown in the "How to connect" section. |
| `DISABLE_REGISTRATION` | Set to `true` to close sign-ups: Register disappears from the site and new accounts are refused. See [Managing accounts](#managing-accounts). |
| `RESERVED_USERNAME_PREFIXES` | Comma-separated name prefixes players can't register. Defaults to `RNDBOT`: mod-playerbots treats any account starting with its `AiPlayerbot.RandomBotAccountPrefix` as a bot account and deletes it on the next bot reset. Change it if you changed that prefix; `_` and `%` work as in SQL `LIKE`, the way playerbots matches them. Set it empty (`RESERVED_USERNAME_PREFIXES=`) to refuse no names. |
| `DISABLE_CHANGEPASSWORD` | Set to `true` to remove Change Password from the menu. |
| `DISABLE_ONLINE_PLAYERS` / `DISABLE_TOP_PLAYERS` | Set to `true` to hide the online players list / top players. With both set, the whole Server Status section and its menu link are hidden. |
| `REALM_ID` | The realm's ID in `acore_auth.realmlist`. Leave at `1` unless you run more than one realm. |
| `CAPTCHA_TYPE` | `0` built-in image captcha (default), `1` hCaptcha, `2` reCAPTCHA v2, `3` Cloudflare Turnstile, `4` off. |
| `CAPTCHA_KEY` / `CAPTCHA_SECRET` | Site key and secret key from your captcha provider. Only needed for types 1–3. |
| `DB_USER` / `DB_PASS` | Leave `DB_USER` as `wow_register` unless you changed it in the SQL file. |
| `DB_PORT` | AzerothCore's MySQL port inside the Docker network. Leave at `3306` unless you changed it. |
| `AC_MODULES_DIR` | Optional. AzerothCore's `modules/` folder on the host, so the [patch notes](#patch-notes-changelogphp) only show what the realm runs. |
| `WORLDSERVER_ADDRESS` | Where the world server listens inside the Docker network, default `ac-worldserver:8085`. The [patch notes](#patch-notes-changelogphp) use it to tell when the realm is down for a restart. |
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

Open `https://SERVICE_NAME.DOMAIN` and register a test account, then log in with it in the game client. If you want to stop strangers signing up, set `DISABLE_REGISTRATION=true` afterwards and run `docker compose up -d`.

## Portalkeeper and mod-realm-config

If your server runs [mod-realm-config](https://github.com/Hisha/mod-realm-config), the portal can host its `realm.conf` for the [Portalkeeper](https://github.com/Hisha/Portalkeeper) launcher. Players then get a "Play with Portalkeeper" section under **How to connect**. It has a Portalkeeper download link, the `realm.conf` download and where to put it.

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

Everything in that folder is public, so only point it at a folder that holds public files. Other modules that write public files there, such as the [armory](#armory-mod-realm-armory) or a news feed, are served at `/realm/<file>` too. Directory listings and PHP are turned off for that path. The files need to be readable by other users (e.g. mode `644`) so the web server can read them.

### Armory (mod-realm-armory)

[mod-realm-armory](https://github.com/Hisha/mod-realm-armory) publishes a character roster (`index.json`) and one profile per character (`characters/<guid>.json`) for Portalkeeper's Armory tab. Like mod-realm-config, it only writes files. Have it write into an `armory` folder inside the same `realm-public` folder, and the portal serves them at `https://SERVICE_NAME.DOMAIN/realm/armory/`. No portal changes or extra mounts are needed.

1. **Add the module** next to mod-realm-config and rebuild AzerothCore:

   ```bash
   cd ~/azerothcore-wotlk/modules
   git clone https://github.com/Hisha/mod-realm-armory.git
   ```

2. **Configure it.** Copy `modules/mod-realm-armory/conf/mod_realm_armory.conf.dist` to `env/dist/etc/modules/mod_realm_armory.conf` and set the output folder. The shipped file has someone else's path in it, so this line has to change:

   ```ini
   RealmArmory.OutputDirectory = "/azerothcore/env/dist/realm-public/armory"
   RealmArmory.IncludePlayerbots = 0
   ```

   `IncludePlayerbots = 0` keeps playerbots out of the roster. Leave the other settings at their defaults unless you need them.

3. **Rebuild and start AzerothCore** with `docker compose up -d --build`. The module publishes on startup and then every 15 minutes (`RealmArmory.UpdateIntervalMinutes`). To publish right away, type `realmarmory publish` in the worldserver console.

4. **Point Portalkeeper at it** through mod-realm-config, so it shows up in players' realm file:

   ```sql
   UPDATE acore_world.mod_realm_config
   SET armory_url = 'https://register.example.com/realm/armory/index.json'
   WHERE id = 1;
   ```

5. **Check it:** open `https://SERVICE_NAME.DOMAIN/realm/armory/index.json` in a browser. You should see the roster, and your `.realm.conf` should now have `ArmoryURL=` set under `[Services]`.

The module doesn't delete profile files for characters that are deleted or drop out of the roster, so `characters/<guid>.json` for those stays reachable until you remove it from `realm-public/armory/characters/`. Character names, gear and appearance are public by design. No account names, emails or IPs are included.

## Launcher login and client download

The portal can also back the Portalkeeper launcher (our fork, [wow-Portalkeeper](https://github.com/buildthehomelab/wow-Portalkeeper)) with three things:

- **Login:** players sign in to the launcher with their game account. The password is checked against the account's SRP6 verifier, and the launcher gets a token that lasts `LAUNCHER_TOKEN_DAYS` (30) from its last use. Only the token's SHA-256 is stored. Failed logins are throttled: 5 per account name and 20 per address every 15 minutes, counted together with failed checks on the website's Change Password form. Changing the password on the website logs the launcher out everywhere. Banned accounts are refused.
- **A private BitTorrent tracker** for the client download. It only tracks the torrents in `LAUNCHER_TORRENT_DIR`, and only gives peers to logged-in players. Players behind the same router get each other's home-network address first, so a copy in the next room beats the internet. Players on the server's own network are only handed to each other, unless you set `LAUNCHER_PUBLIC_ADDRESS` (see below).
- **A web seed** that serves the client files over HTTPS with byte ranges, so a download never stalls when no other player is sharing.
- **Patch torrents:** every patch MPQ in the realm folder (`/realm/`) gets its own torrent, so players share patches with each other too. The realm folder stays the source of truth. Upload a new `patch-X.MPQ` as usual: the portal builds a new torrent from it the first time someone asks, and stores it in the database per file size and modification time. The web seed is the patch's normal `/realm/` URL, and Portalkeeper still checks the SHA-256 from realm.conf.

It's off until you set `LAUNCHER_ENABLED=true`.

With it on, the home page's "How to connect" shows three steps instead of the manual realmlist checklist: register, download the launcher (`LAUNCHER_DOWNLOAD_URL`, default the fork's latest GitHub release), then INSTALL WOW and ENTER REALM.

| Endpoint | What it does |
| --- | --- |
| `POST api/launcher/login.php` | `{"username", "password"}` (JSON or form) → `{"token", "expires_at", "account": {"id", "name"}}` |
| `GET api/launcher/session.php` | Who the token belongs to. 401 when it has expired, 403 for banned accounts. |
| `POST api/launcher/logout.php` | Forgets the token. |
| `GET api/launcher/torrent.php` | Lists the torrents. `?name=client` returns `client.torrent` rewritten for this player. |
| `GET api/launcher/patch-torrent.php?file=patch-P.MPQ` | The torrent for one patch in the realm folder |
| `GET api/launcher/version.php` | The newest launcher release, `{"tag", "version"}`. No login needed. |
| `POST api/launcher/version.php` | The launcher's release workflow announces a new release: `{"tag", "time"}`, signed with `LAUNCHER_RELEASE_SECRET` |
| `api/launcher/combatlog.php` | Combat log uploads from players who opted in, see [Combat logs](#combat-logs) |
| `api/launcher/crashreport.php` | Crash reports players agreed to send, see [Crash reports](#crash-reports) |
| `GET api/launcher/live.php` | Players online, uptime and a pending restart, events on, rares up. No login needed. See [Live info and what's new](#live-info-and-whats-new) |
| `GET api/launcher/whatsnew.php?since=<time>` | The patch notes merged since then. No login needed. |
| `api/launcher/announce.php/<passkey>` | Tracker announce URL, inside the player's torrent |
| `api/launcher/seed.php/<passkey>/` | Web seed URL, inside the player's torrent |

Send the token as `Authorization: Bearer <token>` (except to `version.php`, `live.php` and `whatsnew.php`). Each player's torrent carries their own passkey in the announce and web seed URLs. Those sit outside the torrent's info dictionary, so every player still has the same info hash and joins the same swarm.

**Launcher updates:** Portalkeeper asks `version.php` every minute and updates itself when there's a newer release. The portal learns about a release in two ways:

- **The release announces itself.** Set `LAUNCHER_RELEASE_SECRET` in `.env` (for example `openssl rand -hex 32`) and add the same value to the launcher repo as the Actions secret `PORTAL_RELEASE_SECRET`. The release workflow then POSTs `{"tag": "v1.2.3", "time": <unix time>}` to `version.php` with `X-Signature: sha256=<HMAC-SHA256 of the body>`, and open launchers pick the release up within about a minute. The time has to be within 5 minutes of the portal's clock, so a recorded announcement can't be sent again later. Without the secret the endpoint answers 404.
- **The portal asks GitHub** for the latest release of `LAUNCHER_RELEASE_REPO` (default `buildthehomelab/wow-Portalkeeper`) once every `LAUNCHER_VERSION_CACHE_SECONDS`. The default is 3600 with a secret set, as a safety net for an announcement that didn't arrive, and 120 without one. It keeps the last answer when GitHub fails. When the API is out of requests (60 an hour per address without `GITHUB_TOKEN`), it reads the release from github.com's `/releases/latest` redirect instead.

The portal only names the release. The launcher still downloads the installer from GitHub and checks it there.

### Setting it up

1. **Database:** run the launcher lines of [docker/create-db-user.sql.example](docker/create-db-user.sql.example) as root. They create `acore_launcher` and let the portal user read `acore_auth.account_banned`. The portal creates its tables on first use.
2. **Files:** copy a clean 3.3.5a client to a folder on the host, for example `/srv/wow-launcher/client/Evermore/`. The folder's name becomes the torrent's name and the folder players get. Then build the torrent:

   ```bash
   python3 tools/make-client-torrent.py /srv/wow-launcher/client/Evermore /srv/wow-launcher/torrents/client.torrent \
       --realm-conf <REALM_CONFIG_DIR>/azeroth.realm.conf
   ```

   It leaves out what the launcher or the game writes to (`realmlist.wtf`, `WTF`, `Cache`, `Logs`, `Interface`), so players' copies keep matching and keep being shared. With `--realm-conf` it also leaves out the realm's own patches (every `[Patch.*]` `FileName`), which come from the realm folder through their own torrents. Everything else stays in, including a graphics client's own `patch-X.MPQ` files when you base the client on an HD repack. Run it again only when the client files themselves change. A new client torrent means everyone downloads the changed files again.
3. **`.env`:** set `LAUNCHER_ENABLED=true`, `LAUNCHER_TORRENT_DIR=/srv/wow-launcher/torrents` and `LAUNCHER_CLIENT_DIR=/srv/wow-launcher/client`, then run `docker compose up -d`.

Patch torrents need nothing extra: they use the realm folder the portal already serves. Override `LAUNCHER_PATCH_DIR` (default `/var/www/html/realm`) or `LAUNCHER_PATCH_URL` (default `/realm` on the seed host, see below, or on `BASE_URL`) only if the patches live somewhere else.

A patch's torrent is made the first time a launcher asks for it, and only from the file realm.conf names: the portal hashes the file, compares its SHA-256 with the `[Patch.*]` row whose `FileName` is that file, and keeps the torrent only when they match. A file changed in the last minute (still being copied in) or one that doesn't match yet gets a 503, and Portalkeeper downloads it the plain way meanwhile. So upload the new patch, update its realm row, and the torrent follows on its own; there's nothing to touch. The realm.conf files are looked for in `LAUNCHER_REALM_CONF_DIR` (default `/var/www/html/realm`) and the patch folder. A patch no realm.conf names gets its torrent unchecked.

Check it with `curl -X POST -d 'username=you&password=...' https://SERVICE_NAME.DOMAIN/api/launcher/login.php`.

The tracker reads the player's address from the last `X-Forwarded-For` entry, which Traefik adds. That entry is only trusted when the request comes from a private address (Traefik on the Docker network). If you put another proxy such as Cloudflare in front of Traefik, every player shows up with the proxy's address, peers get handed addresses nobody can connect to, and the login throttle counts all players as one address.

### Behind Cloudflare

**Through a cloudflared tunnel or the Cloudflare proxy:** set `LAUNCHER_CLIENT_IP_HEADER=CF-Connecting-IP` in `.env` and run `docker compose up -d`. Cloudflare puts the player's address in that header, so the tracker and the login throttle use it. It's only read on requests that came from a private address (Traefik), and only set it when every outside request reaches Traefik through Cloudflare, since Cloudflare overwrites the header but a client talking to Traefik directly could send its own. Requests without the header, such as players on your own network going straight to Traefik, fall back to `X-Forwarded-For`.

The web seeds then also go through Cloudflare. Check that Cloudflare's terms allow that much traffic on your plan; players sharing with each other takes most of it off the web seed once a few have the client.

**Sharing from the server's own network:** a launcher on the same network as the server talks to Traefik directly, so the tracker sees its private address and can't hand it to outside players. Set `LAUNCHER_PUBLIC_ADDRESS` to the network's public IP or a host name that resolves to it (your realmlist, e.g. `realm.example.com`; the container has to resolve it to the public address, not a local override). Outside players then get those launchers at that address. Their router has to forward the launcher's port to them: turn on UPnP (the launcher asks for it) or forward the port by hand. Players on that network who come in through Cloudflare (same public address) are treated as being on it.

**With an open port instead:** give the tracker and web seeds a host of their own that reaches Traefik directly:

1. Add a DNS-only record (grey cloud in Cloudflare), for example `seed.example.com`, pointing at the server's public address. Port 443 has to reach Traefik, and Let's Encrypt has to be able to issue a certificate for the host (port 80 too if your resolver uses the HTTP challenge).
2. Set `LAUNCHER_SEED_HOST=seed.example.com` in `.env` and run `docker compose up -d`.

Torrents handed out from then on announce to `https://seed.example.com/api/launcher/announce.php/<passkey>` and use the web seeds there (`seed.php` for the client, `/realm/` for patches), so the tracker sees players' real addresses and the big downloads skip the proxy. Login and the torrent files themselves stay on the main host. The seed host only answers the tracker, web seed and `/realm/` paths; the rest of the site isn't reachable through it. If your router doesn't loop public addresses back to the LAN, add a local DNS record for the seed host on your network too.

## Combat logs

Players can turn on **Share combat logs** in the launcher's settings (off by default). The launcher then switches combat logging on inside dungeons and raids and uploads `Logs/WoWCombatLog.txt` when the game closes. The portal keeps each log and digests it, which is what you want when you're tuning bots: what they died to, which casts nobody interrupted, which debuffs stayed on.

`combatlogs.php` (not in the menu) lists the uploads. Each one is cut into fights, and each fight shows:

- **Deaths:** who died, what killed them and the hits just before. A hunter's Feign Death is told apart from a death.
- **Damage players took**, per enemy ability: hits, players hit, total, biggest hit and deaths.
- **Enemy casts** that had a cast time, and how many were interrupted, by whom.
- **Debuffs on players:** how often each was applied, how often it was dispelled and how long that took.
- **Enemy buffs** (enrages and the like) and whether anyone removed them.
- **Crowd control broken**, by whom and with what.
- **Players:** damage, healing, overheal, damage taken, how much of the fight they were doing something and how long they took to start. Characters on bot accounts are marked.

The 3.3.5 combat log has no zone or encounter lines, so a fight is a stretch of damage between the group and hostile creatures with no pause longer than 15 seconds, named after its boss or the creature that took the most damage.

### Setting it up

1. **`.env`:** set `COMBATLOG_ENABLED=true` (the launcher API has to be on too) and `COMBATLOG_VIEW_KEY` to a long random value, for example `openssl rand -hex 32`. Run `docker compose up -d --build`.
2. **Optional, better boss names:** run the combat log lines of [docker/create-db-user.sql.example](docker/create-db-user.sql.example) as root, so the digest reads which creatures are bosses from the realm's database. Without them it guesses from the log (a creature that only ever shows up alone and soaks up far more damage than the rest), and the page says so.
3. Open `https://SERVICE_NAME.DOMAIN/combatlogs.php` and enter the key.

Logs live in the `portal-combatlogs` volume (or `COMBATLOG_HOST_DIR`) as the gzip file the launcher sent, plus a JSON digest. They're deleted after `COMBATLOG_KEEP_DAYS` (60; 0 keeps them). An upload can be at most `COMBATLOG_MAX_MB` (512) of gzip; a 4-hour 40-player raid is about 10 MB.

The digest is made in the background right after an upload, and on first view if that didn't happen. To redo them all, for instance after granting the database access:

```bash
docker exec -u www-data wow-register sh -c 'rm -f /srv/combatlogs/logs/*.digest.json && php application/launcher/combatlog_cli.php --all'
```

### Reading the logs from a script

Send the key as `X-Portal-Key`:

| Request | Answer |
| --- | --- |
| `GET api/combatlogs.php` | The uploads, newest first, each with a short summary |
| `GET api/combatlogs.php?log=<id>` | One upload's digest: every fight with the tables above |
| `GET api/combatlogs.php?log=<id>&raw=1` | The log itself, gzip |

```bash
curl -H "X-Portal-Key: $KEY" https://SERVICE_NAME.DOMAIN/api/combatlogs.php
```

### What the launcher sends

Every request carries the player's launcher token. `GET api/launcher/combatlog.php` says whether the realm collects logs (404 when it doesn't), which is what makes the launcher show the setting. An upload is `POST ?action=begin` with the gzip file's size and SHA-256, then `?action=part&upload=<id>&offset=<n>` with at most 4 MiB at a time, then `?action=finish`, which checks the size and checksum. Sending the same file again resumes it or answers that it's already here, so the launcher simply retries after a failure.

## Crash reports

When World of Warcraft crashes, the launcher finds the error report the game wrote (`Errors/*.txt`) and asks the player whether to send it. They can also tick **Send crash reports** in its settings to always send them. The `User:` and `Computer:` lines and the user folder in every path (`C:\Users\<name>`) are taken out first, by the launcher and again by the portal. Each report gets a one-line summary such as `ERROR #132 · ACCESS_VIOLATION at 0081D559`.

1. **`.env`:** set `CRASHREPORT_ENABLED=true` (the launcher API has to be on too). The reports open with `COMBATLOG_VIEW_KEY`, or set `CRASHREPORT_VIEW_KEY` for a key of their own. Run `docker compose up -d --build`.
2. Read them with the key as `X-Portal-Key`:

| Request | Answer |
| --- | --- |
| `GET api/crashreports.php` | The reports, newest first: account, when, summary, the files' names and sizes |
| `GET api/crashreports.php?report=<id>` | One report, with each error file's text |

Reports live in the `portal-crashreports` volume (or `CRASHREPORT_HOST_DIR`), one JSON file each, and are deleted after `CRASHREPORT_KEEP_DAYS` (90; 0 keeps them). An account can send 10 a day, each at most 4 files of 256 KB, and all reports together may take `CRASHREPORT_MAX_MB` (200). The launcher asks `GET api/launcher/crashreport.php` first and never offers to send when it answers 404.

## Live info and what's new

The launcher's home screen shows what is going on in the realm and what changed since the player last played. Both endpoints need only `LAUNCHER_ENABLED=true`, and each part simply stays off when its source isn't set up.

`GET api/launcher/live.php`, cached for `LIVE_CACHE_SECONDS` (30) for every launcher together:

- **Players online**, counted apart from bots (accounts matching `LIVE_BOT_ACCOUNTS`, default `RNDBOT%`).
- **Uptime and a pending restart**, from `.server info` over SOAP (`SOAP_URL`, `SOAP_USER`, `SOAP_PASSWORD`, as for the coin shop). A shutdown or restart counting down shows as "Restart in 9 min".
- **World events that are on**, from `.event activelist` over SOAP. With the `game_event` line of [docker/create-db-user.sql.example](docker/create-db-user.sql.example) granted, only holidays are listed. Add custom events with `LIVE_SHOW_EVENTS` and drop any with `LIVE_HIDE_EVENTS` (comma-separated ids).
- **Rares up and world bosses**, from the rare tracker (`RARE_TRACKER_URL`).

`GET api/launcher/whatsnew.php?since=<unix time or ISO 8601>` returns the [patch notes](#patch-notes-changelogphp) that are new since then, newest first, as plain text. A note counts from when it first showed in the patch notes (for a server module, once the realm runs it), not from its merge date; the first-seen times are kept next to the patch notes cache. It reads that cache only, never GitHub, so it answers 503 until `changelog.php` has been opened once after a deploy, and it only learns about new notes when `changelog.php` refreshes the cache.

## Coin shop

With [mod-boss-coins](https://github.com/buildthehomelab/wow-mod-boss-coins) on the worldserver, every account has a wallet of **Tarnished Undermine Reals**, and the launcher gets a **Shop** window: mounts, companions and character services (rename, new looks, race and faction change) for coins. The module keeps the wallet and the catalogue (its table `mod_boss_coins_shop`). The portal only asks it over SOAP, on behalf of the player logged in to the launcher.

### Setting it up

1. **Worldserver:** turn SOAP on and let it listen on the container network (in AzerothCore's docker setup, `AC_SOAP_ENABLED: "1"` and `AC_SOAP_IP: "0.0.0.0"` on the worldserver). Don't publish port 7878 to the outside: only the portal should reach it.
2. **A SOAP account:** SOAP takes an account of GM level 3. Make one just for the portal, on the worldserver console:

   ```
   account create portalsoap <a long random password>
   account set gmlevel portalsoap 3 -1
   ```

   Nobody plays on it. The shop's commands are console-only, so a game master can't run them in game, but this account can do anything the console can: keep its password as safe as the database's.
3. **`.env`:** set `SHOP_ENABLED=true` (the launcher API has to be on too), `SOAP_USER` and `SOAP_PASSWORD`. `SOAP_URL` defaults to `http://ac-worldserver:7878/`. Run `docker compose up -d --build`.

`GET api/launcher/shop.php` with a player's token then answers the wallet balance, the account's characters and the offers, with the characters that own each one already or can't use it. The launcher shows the Shop button when it answers 200. `POST` with `{"offer", "character"}` buys. A listing is reused for `SHOP_CACHE_SECONDS` (15), because the worldserver answers it on its main thread. A purchase clears it. An account can only have one purchase on its way at a time, and at most `SHOP_BUYS_PER_MINUTE` (6) a minute.

### Pictures of the offers

The launcher shows a picture of a mount or companion that you can spin with the mouse when the realm folder (what is served at `/realm/`) has a `shop/` folder with one picture strip per offer and a `shop/previews.json` naming them, by offer id:

```json
{
  "201": { "file": "201.webp", "frames": 36 },
  "101": { "file": "101.webp", "frames": 36 }
}
```

A strip is `frames` pictures of the same size side by side, left to right, one per angle as the model turns (WebP, PNG or JPEG; a transparent background looks best). [wow-mod-retail-creatures](https://github.com/buildthehomelab/wow-mod-retail-creatures)' `tools/turntable.py` draws them from a converted model. An offer without a picture keeps its drawn icon. The listing gives each picture's URL with the file's time and size on it, so a replaced picture gets a new URL and the launcher fetches it again (within the listing's `SHOP_CACHE_SECONDS`). The folder is the realm folder's `shop/` even when the patches live in a subfolder (`LAUNCHER_PATCH_DIR`); `SHOP_PREVIEW_DIR` and `SHOP_PREVIEW_URL` (default `BASE_URL/realm/shop`) move it.

When the game server is down the shop answers 503 and the launcher says so. When a purchase gets no answer (502), it may or may not have gone through. Every wallet change is in the characters table `mod_boss_coins_ledger`.

## Legacy

With mod-legacy on the worldserver, challenges done on any character of an account earn it **Legacy points**, and the launcher gets a **Legacy** page where each character spends up to 16 of them in three perk trees (Adventure, Resourcefulness, Professions). A hardcore character can only spend the points hardcore characters earned. The module keeps the challenges, the trees and every character's build; the portal only asks it over SOAP, like the coin shop.

Set it up like the [coin shop](#coin-shop) (steps 1 and 2: SOAP on the worldserver and a SOAP account), then set `LEGACY_ENABLED=true` in `.env` (the launcher API has to be on too) and run `docker compose up -d --build`.

`GET api/launcher/legacy.php` with a player's token answers what `.legacy list <account>` does: the points earned (all and hardcore), the account's characters with their points and builds, the trees and the challenges, plus `cachedAt`. The launcher shows the Legacy page when it answers 200. `POST` with `{"character": <guid>, "ranks": {"well_rested": 5, "diplomat": 3}}` saves that character's whole build (`{}` resets it) and answers the character as it is now and a message. The portal only lets numbers and perk ids (`[a-z_]`, at most 40 perks, ranks 1 to 9) into the console command; the worldserver checks the rest and answers 409 with a reason when it refuses (`points`, `locked`, `combat`, `no_character`, `invalid`). A listing is reused for `LEGACY_CACHE_SECONDS` (10) and cleared by a save. An account can have one save on its way at a time, and at most `LEGACY_SAVES_PER_MINUTE` (6) a minute (429). When the game server is down or Legacy is closed it answers 503; when a save gets no answer (502), it may or may not have been saved.

## Character order

The launcher's **Characters** page lets players sort their characters for the character select screen, and pushes an order set in game. The order is the core's own `characters`.`order` column: the worldserver sends the character list `ORDER BY COALESCE(order, guid)` and never writes the column, so the portal can write it while the player is online (the new order shows the next time the list is asked for, e.g. back at character select). The Evermore core also has a `favourite` column (0 or 1), the star its character select screen shows beside a character, which the portal keeps the same way. No module or SOAP is needed, only one more grant for the portal's database user, on those columns (the core's characters update adds `favourite`, so grant it after that update has run; on a core without it, grant `order` alone, since the grant fails on a missing column):

```sql
GRANT UPDATE (`order`, `favourite`) ON acore_characters.characters TO 'wow_register'@'%';
FLUSH PRIVILEGES;
```

(Use your database user's name and your characters database. It's in [docker/create-db-user.sql.example](docker/create-db-user.sql.example), which is safe to run again.) Then set `CHARACTER_ORDER_ENABLED=true` in `.env` (the launcher API has to be on too) and run `docker compose up -d`.

`GET api/launcher/characters.php` with a player's token answers the account's characters that aren't deleted, in the order the character select screen shows them (`ORDER BY COALESCE(order, guid), guid`: the guid breaks ties, e.g. a restored character that kept its old place, so the list comes back the same every time):

```json
{"characters": [{"guid": 12, "name": "Nari", "race": 4, "class": 11, "gender": 1, "level": 60,
                 "lastPlayed": 1792900000, "online": false, "order": 0, "favourite": true}]}
```

`lastPlayed` is the last logout (unix time); `order` is null until an order is saved; `favourite` is false on a core without the column. `POST` with `{"order": [12, 7, 31]}` (or form fields `order[]=12&order[]=7`) saves a new order from the top: characters left out follow the listed ones in their current order, and every character of the account gets its place (0, 1, 2, ...) in one transaction. Add `"favourites": [12, 7]` (form fields `favourites[]=12`, or an empty `favourites=` for none) to set the account's favourites in the same transaction: exactly those characters are favourites afterwards. Without it the favourites stay as they are. On a core without the column they're ignored, and without the grant on `favourite` the order is still saved without them (the portal log says so). It answers 200 with the new order, like `GET`; 400 when the body isn't a list of guids; 409 `{"error": "duplicate"}` when a guid is in a list twice and `{"error": "not_yours"}` when one isn't a character of the account; 429 while the account's last save is still going through, or after `CHARACTER_ORDER_SAVES_PER_MINUTE` (10) saves in a minute (with `Retry-After: 60`). An order that's already saved answers 200 without writing and doesn't count. Once the order is committed the answer is 200, even if reading it back fails (then the body is the order just written). Without the grant a save answers 503 "Character ordering isn't set up on this realm yet." and the portal log names the missing grant. The per-account save lock and the times of its recent saves live in `CHARACTER_ORDER_CACHE_DIR`.

## Rare map (mod-rare-tracker)

`rares.php` is a live map of every open-world rare that's up right now. It has continent and zone
maps, a searchable list per zone, and respawn countdowns for dead rares. The data comes from
[mod-rare-tracker](https://github.com/buildthehomelab/wow-mod-rare-tracker), which the worldserver
serves from memory on the AzerothCore Docker network. `api/rares.php` passes it through to the
browser, so the worldserver needs no published port.

1. **Add the module** and rebuild AzerothCore (see its README). Its endpoint is
   `http://ac-worldserver:8095/rares.json` by default.
2. **Point the portal at it** in `.env`, then `docker compose up -d`:

   ```ini
   RARE_TRACKER_URL=http://ac-worldserver:8095/rares.json
   ```

   A **Rare Map** link appears in the top menu. Leave `RARE_TRACKER_URL` empty to hide the page.
3. **Add the map images** (optional but recommended). Build them from your WoW client with the
   module's `tools/build_worldmaps.py`, and copy the resulting `worldmap` folder into
   `REALM_CONFIG_DIR`, so they're served at `/realm/worldmap/`. Set `WORLDMAP_URL` to serve them
   from somewhere else. Without them, rares are listed and placed on a plain grid.

The page polls every 30 seconds while it's open. The worldserver only rebuilds the list while
someone is looking.

## Patch notes (changelog.php)

`changelog.php` is a patch-notes page in the style of Blizzard's: one post per day, grouped into
Classes (in class colours), Professions, Auction House, Dungeons and so on, with New Features and
Bug Fixes sections and a category filter. It's built from **merged pull requests** in every repo
of a GitHub user or organization, so nothing has to be written twice.

1. **Turn it on** in `.env`, then `docker compose up -d`:

   ```ini
   CHANGELOG_ORG=buildthehomelab
   CHANGELOG_TIMEZONE=America/New_York
   ```

   A **Patch Notes** link appears in the top menu. Only repos whose names match
   `CHANGELOG_REPOS` (default `^(wow-|mod-)`) are read; archived repos are skipped.

2. **Only show what the realm runs** (recommended). Set `AC_MODULES_DIR` to AzerothCore's
   `modules/` folder on the host, then `docker compose up -d`:

   ```ini
   AC_MODULES_DIR=/home/you/azerothcore-wotlk/modules
   ```

   It's mounted read-only, and the portal reads each module's checked-out commit from its `.git`
   folder. A server module's
   changes then appear once the server is on a commit that includes them, and modules the server
   doesn't run don't appear at all. Addons, the launcher and this website (`CHANGELOG_UNGATED`)
   show as soon as they're merged. Without `AC_MODULES_DIR`, everything shows as soon as it's
   merged. To read the versions from somewhere else, set `CHANGELOG_MANIFEST` to a `modules.tsv`
   file (folder, url, branch, commit) or `owner/repo:path/modules.tsv` on GitHub.

   The versions are read again only when the world server restarts, not whenever the folder
   changes: pulling the modules before a build doesn't put their changes on the page yet. They
   appear the moment the server goes down for the restart that brings them, when players waiting to
   log back in can read what's coming, and the page rebuilds right then instead of at the end of
   `CHANGELOG_CACHE_SECONDS`. For that, grant the `uptime` line of
   [docker/create-db-user.sql.example](docker/create-db-user.sql.example) (the realm's start time)
   and leave `WORLDSERVER_ADDRESS` at `ac-worldserver:8085`, or set it to wherever the world server
   listens inside the Docker network (empty or `off` never probes; a probe that has never seen the
   realm up is ignored and logged, so a wrong address can't look like an outage). Without the grant,
   the folder is read once per cache period, as before. Any restart counts, including one without a
   new build, so pull the modules right before you build.

### Where the text comes from

For each merged PR, the first of these that exists:

1. **[application/changelog-notes.json](application/changelog-notes.json)**, hand-written notes
   keyed `repo#number`. `{"hide": true}` drops a PR. Repo launches can be written here too, under
   `launches`.
2. **A `## Patch Notes` section in the PR description.** Its bullets are used as they are, and a
   `>` quote becomes a "Developers' notes" box. Write `None` to keep a PR off the page.

   ```markdown
   ## Patch Notes
   - **Travel Form** can now be used indoors.
   - Fixed an issue where **Travel Form** dropped below 40% speed indoors.
   > We want druids to keep their travel form in caves and cities.
   ```

3. **The PR title.** Titles that look like version bumps, docs, build or compatibility fixes
   are skipped.

Each repo is also announced once as a **New Feature**, dated when the repo was created. The card
comes from `launches` in `changelog-notes.json`, or else from a `## Patch Notes` section in the
repo's README. The heading carries the feature's name, and an optional `Category:` line picks the
section (and class):

```markdown
## Patch Notes: Bear Fishing
Category: Classes / Druid
- Druids can now fish in Bear Form: wade into a river and swipe salmon out of the water.
> We wanted druids to have a reason to visit the rivers of Azeroth.
```

Repos with neither aren't announced. READMEs are only fetched again after a push to the repo.

Notes take their category from the repo name (see `CL_REPO_SECTIONS` in
[application/include/changelog.php](application/include/changelog.php)). A `Category:` line in a
`## Patch Notes` section, or `section` and `sub` in `changelog-notes.json`, override it. A note is listed under Bug Fixes when the PR title starts with
"Fix", every bullet starts with "Fixed", or `fix` is set.

GitHub's answers are cached for `CHANGELOG_CACHE_SECONDS` (10 minutes), and the old copy is kept
if GitHub is down. The cache lives in the `portal-cache` volume, so it survives a redeploy. The very
first load looks up every module's commit and every README, and can take half a minute; after that,
those answers are remembered.

Set `GITHUB_TOKEN` if you have more than a few dozen modules. Without one, GitHub allows 60 requests
an hour for your whole network, and the first build needs about one per module and README. The page
then says the patch notes couldn't be loaded until it has got through them all: each attempt keeps
what it looked up, waits 5 minutes after a failure, and carries on when GitHub allows more, so it
takes a few hours. With a token the first load just works.

## Managing accounts

With `DISABLE_REGISTRATION=true`, create accounts yourself from the worldserver console:

```bash
docker attach ac-worldserver
```

Then type the command and detach with **Ctrl+P, Ctrl+Q** (Ctrl+C stops the server):

| To | Command |
|---|---|
| Create an account | `account create <username> <password>` |
| Reset a forgotten password | `account set password <username> <new> <new>` |

Players can change their own password on the site if they know the current one. There's no "forgot password" email because the portal doesn't send email.

## Updating

```bash
git pull
docker compose up -d --build
```

If you only changed `.env` (title, contact email, closing registration and so on), `docker compose up -d` is enough and no rebuild is needed.

## Troubleshooting

- **Blank page:** set `DEBUG_MODE=true` in `.env`, run `docker compose up -d`, reload the page and read the error. Then turn it off again.
- **Styling or images missing:** the site builds its links from `SERVICE_NAME` and `DOMAIN`. Make sure they match the URL you're visiting.
- **Database connection error:** check that `AC_NETWORK_NAME` is right, that the container is on it (`docker inspect wow-register`), and that `DB_PASS` matches the password in `create-db-user.sql`.
- **No Portalkeeper section / 404 on `/realm/<realm_key>.realm.conf`:** check that `REALM_CONFIG_DIR` is the host folder the module writes to, that `<realm_key>.realm.conf` exists there (and matches `REALM_KEY` if you set it) and is readable, and that you ran `docker compose up -d` after changing it. `docker exec wow-register ls -l /var/www/html/realm` shows what the container sees.
- **Logs:** `docker logs wow-register`

## What's different from upstream

- Docker image (PHP 8.3 + Apache) with Composer dependencies installed at build time.
- **Dark theme** inspired by the World of Warcraft site: warm dark backgrounds, parchment serif headings, bronze trim and teal buttons. It's one stylesheet, [template/advance/assets/css/forever.css](template/advance/assets/css/forever.css), loaded after the template's own CSS, so it's easy to tweak or remove.
- **One template, English only.** Only `advance` is included (upstream's other six templates and 11 translations were removed). Register and Change Password are popups in the top menu. The placeholder FAQ, rules, footer, contact details and "Edit on …" hints are gone, and the server info lists this server's actual rates and features ([template/advance/tpl/server-info.php](template/advance/tpl/server-info.php)).
- **Registration can be closed** with `DISABLE_REGISTRATION`, which hides the form and makes the server refuse sign-ups.
- **Register and Change Password are throttled.** Change Password shares the launcher's failed-login counter (5 per account name and 20 per address every 15 minutes) and answers "Wrong account name or password" for both a missing account and a wrong password. Each address gets 10 sign-up attempts an hour. New passwords need 8 to 16 characters. IPv6 addresses count by their /64. The counters live in the launcher database (`LAUNCHER_DB_NAME`), which is needed even with the launcher off: the forms refuse while it can't be reached, so run the `acore_launcher` lines of [docker/create-db-user.sql.example](docker/create-db-user.sql.example) on existing installs.
- All config comes from environment variables ([docker/config.php](docker/config.php)) instead of an edited `config.php`.
- Served only through Traefik over HTTPS. No ports are published.
- Apache blocks direct access to `application/`, `docker/`, dotfiles and Markdown files ([docker/apache-security.conf](docker/apache-security.conf)).
- Locked to AzerothCore with SRP6, using a least-privilege database user instead of root.
- Uses the built-in image captcha by default, so there are no third-party captcha keys to set up. You can switch to hCaptcha, reCAPTCHA or Turnstile with `CAPTCHA_TYPE`.
- Can host [mod-realm-config](https://github.com/Hisha/mod-realm-config)'s `realm.conf` and [mod-realm-armory](https://github.com/Hisha/mod-realm-armory)'s JSON for Portalkeeper, and shows Portalkeeper setup steps.
- **Launcher API** ([api/launcher/](api/launcher/)): game-account login for Portalkeeper, plus a private tracker and web seed for the client download.
- **Coin shop** ([api/launcher/shop.php](api/launcher/shop.php)): the launcher's Shop window, spending mod-boss-coins' wallet over SOAP.
- **Legacy** ([api/launcher/legacy.php](api/launcher/legacy.php)): the launcher's Legacy page, spending mod-legacy's challenge points on perks over SOAP.
- **Character order** ([api/launcher/characters.php](api/launcher/characters.php)): the launcher's Characters page, saving the character select order in the core's `order` column.
- **Rare map** ([rares.php](rares.php)): live open-world rares from mod-rare-tracker on the game's own maps.
- **Patch notes** ([changelog.php](changelog.php)): Blizzard-style patch notes built from merged pull requests on GitHub.
- **Vote system is off,** because it alters `acore_auth.account` and creates new tables.
- **No email.** Registering only asks for a username and password, and accounts get an empty email. "Restore password" and two-factor auth are turned off because they need SMTP. See [Managing accounts](#managing-accounts) for resetting passwords.

## Credits

Author: [buildthehomelab](https://github.com/buildthehomelab)

Built on [WoWSimpleRegistration](https://github.com/masterking32/WoWSimpleRegistration) by [Amin.MasterkinG](https://masterking32.com) and its contributors and translators. See the upstream README for the full list.

## License

Licensed under the GPL-3.0, the same as upstream. See [LICENSE](LICENSE).
