# Smart Home — v1 (local web app + ESP32 clock station)

A web app that runs on the user's laptop and controls ESP32 bedroom clocks over the home Wi-Fi. **No cloud.**
The clock shows time, temperature and humidity and rings wake-up alarms. It works on its own: the app is a
remote control, not a dependency.

```
Browser (React) ──HTTP/WS──▶ Laptop backend (Laravel) ──HTTP + WS over LAN──▶ ESP32 clock(s)
                  /api, Echo      │  finds clocks via mDNS, keeps their tokens,
                                  │  proxies every UI action, stores history
```

The browser talks **only** to the backend; it never calls a device directly (a browser can't browse mDNS or keep
device tokens safe). The v2 mobile app will call the same backend API.

## Repository layout

| Path | What |
| --- | --- |
| `backend/` | Laravel 13 app (PHP 8.3+, image runs 8.4): JSON API under `/api`, device listener, discovery, history |
| `frontend/` | React + Vite SPA (**not built yet**; see "Frontend" below for the contract it must follow) |
| `firmware/` | ESP32 firmware (**not started**; see "Device local API contract") |
| `tools/fake-clock/` | Zero-dependency Node simulator of a clock (HTTP API + WebSocket + pairing). Use it instead of hardware |
| `docker/` | `php/Dockerfile` (one image for app, worker, reverb), `php/supervisord.conf`, `nginx/default.conf` |
| `docker-compose.yml` | nginx, app (php-fpm), worker, reverb, db (PostgreSQL 17), frontend (opt-in profile) |
| `docker-compose.linux.yml` | Linux override: `network_mode: host` for the worker so mDNS works |

## Running it

No PHP on the host is required. Everything runs in Docker (Colima on this machine: `colima start`).

```bash
cp backend/.env.example backend/.env          # then set APP_KEY / REVERB_* secrets
docker compose up -d --build
docker compose exec app php artisan key:generate
docker compose exec app php artisan migrate
# Linux, for real mDNS discovery:
docker compose -f docker-compose.yml -f docker-compose.linux.yml up -d
```

- App + API: http://localhost:8080 (nginx; `/api`, `/sanctum`, `/up` → Laravel, everything else → `frontend/dist`)
- Reverb (browser WebSocket): ws://localhost:8081
- All ports bind to `127.0.0.1` only. Exposing the backend to the LAN (v2 phone) must be an explicit change.

**Fake clock** (no hardware):

```bash
docker run -d --name fake-clock --network smarthome_default -v "$PWD/tools/fake-clock:/app:ro" \
  node:22-alpine node /app/fake-clock.mjs --port 80 --id clock-f4k3
docker inspect -f '{{range .NetworkSettings.Networks}}{{.IPAddress}}{{end}}' fake-clock   # add this IP in the app
docker logs -f fake-clock     # the pairing code is printed here (the real clock shows it on its display)
```

It has no mDNS: add it by IP (`POST /api/discovery/probe`) or set `DISCOVERY_PROBE_SUBNET`. Its state lives in
memory, so a restarted fake clock is unpaired.

### Commands

Run PHP commands inside the container: `docker compose exec app php artisan …`. Without the stack, use a throwaway
container: `docker run --rm -v "$PWD/backend":/app -w /app composer:2 php artisan …`

| Command | Purpose |
| --- | --- |
| `php artisan test` | Full suite (SQLite in memory; `phpunit.xml`). Must stay green |
| `php artisan test --filter=PairingTest` | One test class or method |
| `vendor/bin/pint` | Code style (Laravel preset). Run before committing |
| `php artisan devices:discover [--subnet=192.168.1.0/24] [--watch]` | Scan the LAN; refreshes paired devices' IPs |
| `php artisan devices:listen` | Long-running device listener (runs under supervisord in `worker`) |
| `php artisan model:prune --model="App\Models\Reading"` | History retention (scheduled daily) |
| `php artisan alarms:sync [--all]` | Queue an alarm sync for online devices with pending changes (scheduled every minute) |
| `php artisan settings:sync` | Queue a settings push for online devices with pending settings (scheduled every minute) |
| `docker compose restart worker` | **Required after changing listener, job or command code** (long-running processes don't reload) |
| `docker compose logs -f worker` | Listener / queue / scheduler output |

## Backend architecture (`backend/`)

| Area | Where |
| --- | --- |
| Device HTTP client (3 s timeout, bearer token, `api_version` check) | `app/Devices/DeviceClient.php` |
| Device errors → JSON `{message, code}` | `app/Devices/Exceptions/*`, rendered in `bootstrap/app.php` |
| mDNS browse (pure PHP DNS codec, legacy-unicast query, no ext-sockets) | `app/Devices/Discovery/DnsMessage.php`, `MdnsBrowser.php` |
| Subnet probe fallback (`GET /api/info` across a /22–/32) | `app/Devices/Discovery/SubnetProber.php` |
| Discovered-device list (shared cache), DHCP IP refresh | `app/Devices/Discovery/DiscoveryService.php` |
| Live state cache (shared between web and worker) | `app/Devices/DeviceStateStore.php` |
| Listener: one WebSocket per device, poll fallback, reconnect backoff | `app/Devices/Listener/DeviceListener.php` (amphp/Revolt) |
| What to do with device messages (testable, no event loop) | `app/Devices/Listener/DeviceEventHandler.php` |
| Offline-first alarms: save → push now → background sync (`SyncAlarms` job, `alarms:sync`) | `app/Services/AlarmService.php`, `app/Jobs/SyncAlarms.php` |
| Offline-first settings/rename: same flow (`SyncDeviceSettings` job, `settings:sync`) | `app/Services/DeviceSettingsService.php`, `app/Jobs/SyncDeviceSettings.php` |
| Per-device push lock shared by both services (web + worker) | `app/Devices/DeviceLock.php` |
| History down-sampling (24 h → 5 min buckets, 7 d → 30 min) | `app/Services/ReadingHistory.php` |
| Broadcast events (`users.{id}` private channel) | `app/Events/*`, `routes/channels.php` |
| Routes | `routes/api.php` (API), `routes/console.php` (schedule) |
| Device types (`DeviceType` backed enum, cast on `Device::$type`) | `app/Enums/DeviceType.php` |
| Settings | `config/smarthome.php` (all tunables, env-driven) |

### Processes

- **app** (php-fpm): the HTTP API. Never does mDNS (Docker bridge blocks multicast); talks to devices over plain HTTP.
- **worker** (supervisord): `queue:work`, `devices:listen`, `schedule:work`. This is the container that needs LAN
  access, so it is the one that gets host networking on Linux.
- **reverb**: WebSocket server for the browser. Laravel publishes to it over HTTP (`REVERB_HOST/PORT`);
  the browser connects using `VITE_REVERB_*`.

### Data model

| Table | Notes |
| --- | --- |
| `users` | `username` **or** `email` (both nullable, unique; at least one required), bcrypt password |
| `devices` | PK = the device's own ID (string). `token` uses the `encrypted` cast and is `#[Hidden]`. `type` is a string column cast to `App\Enums\DeviceType` (a new type = a new enum case, no migration). `type` + `capabilities` (JSON) drive the UI generically. `name` + `settings` JSON `{tz, brightness, snooze_min}` = the user's desired settings (**DB first**, like alarms): `settings_sync_status` (`SyncStatus`) / `settings_sync_error` / `settings_synced_at`, `settings_revision` guards sync writes. The whole state is pushed on each sync |
| `readings` | One row per device per ≥60 s (written by the listener). Pruned after 30 days (`MassPrunable`) |
| `alarms` | The user's alarms (**the DB is the source of truth for user changes**). `id` is the API ID, `device_alarm_id` the clock's (null until it accepted the alarm). `sync_status` (`App\Enums\SyncStatus`): `synced` / `pending` (not on the clock yet) / `failed` (clock refused; `sync_error {code, message}`). Soft-deleted = delete not confirmed by the clock. `revision` is bumped on every user change; sync writes are conditional on it. Conflicts: pending rows win over the clock, synced rows follow the clock (picks up changes made on the clock itself) |

Online/offline is derived: `Device::isOnline()` = `last_seen_at` newer than `DEVICE_OFFLINE_AFTER` (45 s). The
listener updates `last_seen_at` every 5 s while a socket is open (ping/pong heartbeat every 10 s drops dead sockets)
and broadcasts transitions. Measured: offline reported 46 s after power loss (spec: within 60 s).

### Backend API (`/api`)

Auth (v1): Sanctum SPA cookie session only. Call `GET /sanctum/csrf-cookie` first and send `X-XSRF-TOKEN`; requests
must come from a `SANCTUM_STATEFUL_DOMAINS` origin. Unauthenticated → 401. Bearer-token login for the mobile app is
deferred to v2 (the `HasApiTokens` trait and the `personal_access_tokens` table are already in place).
For `curl` testing, send `Origin: http://localhost` and handle the XSRF cookie, or use feature tests.

| Method | Path | Notes |
| --- | --- | --- |
| GET | `/auth/status` | `{has_users, registration_open}`: sign-up vs sign-in screen on first launch (public) |
| POST | `/auth/register` | `{username? , email?, password, password_confirmation}`; signs the browser in |
| POST | `/auth/login` | `{login (email or username), password, remember?}`. 400 outside the web UI origin. 5 tries/min |
| POST | `/auth/logout` | Ends the session |
| GET | `/user` | Current user |
| GET | `/discovery` | Unpaired devices seen recently + `scanned_at`. `type` is the raw announced string, `supported` says whether it maps to a `DeviceType` |
| POST | `/discovery/scan` | `{subnet?}` → 202; queued on the worker; result via `.discovery.finished` broadcast |
| POST | `/discovery/probe` | `{host}`: manual add by IP |
| POST | `/pairing/start` | `{host}`: clock shows a 6-digit code for 120 s. 422 `unsupported_device_type` if `/api/info` reports an unknown `type` |
| POST | `/pairing/confirm` | `{host, code, name?}` → 201 device. The type is checked before the code is sent, so an unsupported device never gets a token. 422 `invalid_pairing_code` (+`attempts_left`), 429 `pairing_locked` (+`Retry-After`) |
| GET | `/devices` | The user's devices with `online`, `state`, `next_alarm` |
| GET/PATCH/DELETE | `/devices/{id}` | PATCH `{name}` (same flow as settings: 200 / 202 pending / 422 refused). DELETE unpairs the clock first; 503 if unreachable unless `?force=1` |
| GET | `/devices/{id}/state` | Live fetch from the device |
| PUT | `/devices/{id}/settings` | `{name?, tz? (IANA), brightness? 0–100, snooze_min? 1–30}`. 200 = the clock has it; **202 = saved, `settings_sync_status: pending`**, pushed when the clock is back; a refusal is rolled back (422 `device_rejected`) |
| GET | `/devices/{id}/readings?range=24h\|7d` | Bucketed `{points:[{ts, temperature_c, humidity_pct}]}` |
| GET/POST | `/devices/{id}/alarms` | GET reconciles with the clock when it is online; `meta.stale=true` = the clock could not be asked. POST `{time "HH:MM", days [1..7] (ISO, 1=Mon; [] = one-shot), enabled?, label?, snooze_min?, sound?, volume?}`; max 10. Each alarm has `sync_status`, `sync_error`, `synced_at` |
| PUT/DELETE | `/devices/{id}/alarms/{alarmId}` | Partial update / delete. Writes: 200/201/204 = the clock has it; **202 = saved, `pending`**, pushed when the clock is back; a clock refusal is rolled back and returned (422 `device_rejected`) |
| POST | `/broadcasting/auth` | Echo private-channel auth |

Other users' devices return **404** (not 403). Device failures return `{message, code}` with `code` one of
`device_unreachable` (503), `device_unauthorized` (502: stored token rejected), `device_rejected` (422),
`device_not_found` (404), `pairing_locked` (429, pairing endpoints only), `device_rate_limited` (429, any other endpoint), `unsupported_device_type` (422), `device_error` (502). Devices include `type` and `type_label`.

**Broadcasts** on `private-users.{userId}` (Echo: `Echo.private(`users.${id}`).listen('.device.state', …)`):
`.device.state {device_id, state}`, `.device.status {device_id, online, last_seen_at}`,
`.alarm.ringing {device_id, alarm_id}` (API alarm ID, null if unknown), `.alarms.updated {device_id, alarms}` (after a background sync), `.device.settings {device_id, name, settings, settings_sync_status, settings_sync_error, settings_synced_at}` (after a background settings push), `.discovery.finished {devices}`. Note the leading dot (custom `broadcastAs`).

## Device local API contract (ESP32, port 80)

All endpoints except `/api/info` and pairing require `Authorization: Bearer <device token>`. JSON bodies.
**Every response carries `api_version`** (the backend logs a warning on a major-version mismatch).

| Method | Path | Body / response |
| --- | --- | --- |
| GET | `/api/info` | `{id, type:"clock", name, fw, mac, paired, capabilities:["temperature","humidity","alarm","clock"]}` |
| POST | `/api/pair/start` | Shows the code; `{expires_in}`. Rate-limited |
| POST | `/api/pair/confirm` | `{code}` → `{token}`. Wrong code → 401 `{attempts_left}`; 5 wrong → 429 `{retry_after}` for 5 min |
| POST | `/api/unpair` | Forgets the token |
| GET | `/api/state` | `{time, tz, temperature_c, humidity_pct, wifi_rssi, uptime_s, next_alarm}` |
| GET | `/api/alarms` | `{alarms:[{id, time:"07:00", days:[1..5], enabled, label}]}` (a bare list is also accepted) |
| POST / PUT / DELETE | `/api/alarms[/{id}]` | Returns the alarm with `id` |
| PUT | `/api/settings` | `{name, tz, brightness, snooze_min}` |
| WS | `/ws?token=…` | Pushes `{type:"state", …state}` and `{type:"alarm_ringing", id}`. Must answer pings |

mDNS: hostname `clock-XXXX.local`, service `_smartclock._tcp` port 80, TXT `id`, `type=clock`, `fw`, `paired=0|1`.

Assumptions the backend makes that the spec leaves open (keep firmware and `tools/fake-clock` in line with these):
wrong pairing code = HTTP 400/401/403/422; lockout = 429 with optional `retry_after` seconds; `days` uses ISO
weekdays; `tz` is an IANA name (the firmware maps it to a POSIX TZ string); the token is sent in the WS query string.

## Decisions and deviations from the tech task

- **No Breeze.** Breeze isn't maintained for Laravel 12+/13; `AuthController` implements the same API flow
  (register / login / logout / me) on Sanctum SPA sessions. Token login is left for v2.
- **amphp instead of ReactPHP + Pawl** for `devices:listen`: `ratchet/pawl` requires `guzzlehttp/psr7 ^2`, which
  conflicts with Laravel 13 (psr7 3.x). `amphp/websocket-client` + `amphp/http-client` (Revolt event loop, fibers)
  do the same job.
- **mDNS in pure PHP** (`DnsMessage`): no Avahi/`dns-sd` dependency and no ext-sockets. Uses a "legacy unicast"
  query, so responders answer the querying socket directly.
- **Alarms are offline-first.** A change is saved in `alarms`, then pushed to the clock within the request. If the clock
  is unreachable it stays `pending` and `SyncAlarms` pushes it when the listener sees the clock come online (and
  `alarms:sync` every minute). Only transient errors (unreachable, 5xx, 408/429) are retried; a refusal during a
  request is rolled back and returned, in the background it marks the row `failed`. Web and worker push under a
  per-device cache lock. The device contract is unchanged.
- **Settings and rename are offline-first too** (`DeviceSettingsService`), with the same 200/202/422 semantics; the
  listener queues `SyncDeviceSettings` on every online transition. Refusals in the background mark the device's
  settings `failed` until the user changes them again.
- Login accepts a username or an email.

## Best practices for this stack

### PHP 8.3+ / Laravel 13

- Model fixed value sets as PHP backed enums in `app/Enums` with an Eloquent cast, stored in string columns (not DB `ENUM`s,
  which need a migration per new value and behave differently across SQLite/PostgreSQL/MySQL). Use `tryFrom()` on
  untrusted input (device responses) and fail with a typed exception; never let `from()` throw a raw `ValueError`.
- Use Laravel 13 idioms already in the codebase: Eloquent attributes (`#[Fillable]`, `#[Hidden]`, `#[Table]`),
  console attributes (`#[Signature]`, `#[Description]`), `casts()` methods, `bootstrap/app.php` for middleware,
  exceptions and routing (there is no `Kernel.php`).
- Constructor injection with `readonly` promoted properties; `final readonly` DTOs (see `DiscoveredDevice`).
- Validate in FormRequests (`app/Http/Requests`); return API Resources (`app/Http/Resources`), never raw models.
  Sensitive columns get `#[Hidden]` **and** never appear in a Resource.
- Authorize with policies (`DevicePolicy`, `can:manage,device` on the route group). Don't scope by hand in
  controllers.
- Keep controllers thin. Device I/O goes through `DeviceClient` only; business logic goes in `app/Services`.
- Throw a `DeviceException` subclass for device problems and let `bootstrap/app.php` render it. Don't catch and
  re-shape errors in controllers unless the outcome differs (e.g. `force` delete).
- `Model::shouldBeStrict()` is on outside production: eager-load relations, don't rely on lazy loading.
- Keep queries portable across SQLite (tests), PostgreSQL (docker) and MySQL. No vendor-specific SQL; aggregate in
  PHP when needed (see `ReadingHistory`).
- Every tunable goes in `config/smarthome.php` with an env default. Never call `env()` outside `config/`.
- Run `vendor/bin/pint` before committing.

### Long-running processes (listener, queue, scheduler)

- Code changes need `docker compose restart worker`.
- Never let one device take the process down: catch per device, log, back off (see `DeviceListener::watch`).
- Inside the event loop, use amphp clients (non-blocking). Laravel's HTTP client blocks the whole loop. Short DB
  writes and broadcasts are acceptable.
- Every network wait needs a bound: put a `TimeoutCancellation` on it. Per-request socket timeouts alone have
  hung for minutes against a vanished peer.
- Keep loop-free logic in a separate class so it can be unit tested (`DeviceEventHandler`).
- Throttle DB writes from hot paths (`last_seen_at` at most every 5 s, readings at most every 60 s per device).

### Realtime (Reverb + Echo)

- Use `ShouldBroadcastNow` for live device data (no queue latency). Only private channels, authorized in
  `routes/channels.php`.
- Use a short `broadcastAs()` name and a small `broadcastWith()` payload: send what changed, not the model.
- A broadcast failure must never break the action that caused it: dispatch with `App\Support\SafeBroadcast::dispatch()`.
- Events use the `BroadcastsToUser` trait (`users.{userId}` channel) instead of their own `broadcastOn()`.

### Testing

- Feature tests for every endpoint (`RefreshDatabase`, `actingAs`). Fake every device call with `Http::fake()`,
  covering the success, rejection (4xx), unreachable (`Http::failedConnection()`) and lockout (429) paths.
- Tests always run on in-memory SQLite with `APP_ENV=testing`, even inside the Docker stack. `phpunit.xml` forces
  this with `<env force="true">` plus `<server force="true">`, because Compose injects `.env` as real environment
  variables and Laravel reads `$_SERVER` first. `tests/TestCase.php` aborts if that ever breaks, since
  `RefreshDatabase` would otherwise wipe the dev PostgreSQL database. Keep both in place.
- SPA-session tests need `Origin`/`Referer: http://localhost` headers (see `AuthTest::$spa`).
- Use `Event::fake()` to assert broadcasts and `Queue::fake()` for jobs.
- Time-based logic: `$this->travel()` / `travelTo()`. Don't sleep.
- Note: inside `Http::pool()`, a `'*' => Http::failedConnection()` wildcard also swallows the specific fakes. Use
  `'*' => Http::response(null, 404)` there.
- End-to-end checks against `tools/fake-clock` for anything touching the listener or real sockets.

### Security

- Device tokens: encrypted at rest (`encrypted` cast, keyed by `APP_KEY`). **Rotating `APP_KEY` makes stored tokens
  unreadable**: every device would need re-pairing. Never log or return a token.
- Device addresses from users are validated by `App\Rules\LanHost` (private/link-local IPs or `*.local` only), so
  the proxy can't be pointed at the internet.
- Pairing needs physical presence (code on the display). The device enforces the 5 tries / 5 min lockout; the
  backend adds `throttle:pairing` (10/min/user) and throttles login (5/min per login + IP).
- Local traffic is plain HTTP on a trusted LAN in v1. Revisit TLS or signed requests before adding remote access.

### Frontend (React + Vite; to be built in `frontend/`)

- Stack: React, React Router, TanStack Query for every API call, Laravel Echo + `pusher-js` for Reverb, Recharts
  for history.
- Same origin: in dev, the Vite dev server proxies `/api` and `/sanctum` to `http://localhost:8080` (Echo auth is `/api/broadcasting/auth`).
  In prod, nginx serves `frontend/dist`. Use axios/fetch with `withCredentials` and `withXSRFToken`. Call
  `/sanctum/csrf-cookie` before login/register.
- Live data: Echo events update the TanStack Query cache (`queryClient.setQueryData(['devices'], …)`). Don't refetch
  on every push. Fall back to polling `GET /api/devices` if the socket is down.
- Render device cards from `type` + `capabilities`, not hard-coded per device type.
- Handle `code` values from API errors (offline device, invalid pairing code with `attempts_left`, `pairing_locked`
  with `retry_after`). Alarms stay editable while the clock is offline: show each alarm's `sync_status` and the device's `settings_sync_status` (pending =
  "waiting for the clock", failed = `sync_error.message`) and apply `.alarms.updated` / `.device.settings` to the cache.

### ESP32 firmware (to be built in `firmware/`)

- Keep the HTTP contract above exactly, including `api_version`. Change `tools/fake-clock` in the same commit as
  any contract change.
- Provisioning: SoftAP `ClockStation-XXXX` + captive portal (ESP-IDF `wifi_provisioning` or WiFiManager).
  Credentials, token **hash** and alarms go in NVS.
- Never block the main loop: async web server (ESPAsyncWebServer / esp_http_server), timers for sensors (10 s)
  and NTP (6 h).
- Alarms must ring from local state with no network. Wi-Fi reconnect uses backoff. Only a long button press drops
  back to setup mode.
- Token: 32 random bytes from `esp_fill_random`. Compare hashes in constant time.

## Before you finish a change

1. `php artisan test` passes and `vendor/bin/pint` is clean.
2. New endpoint → FormRequest + Resource + policy coverage + feature test + a row in the API table above.
3. Changed the device contract → update this file, `DeviceClient`, `tools/fake-clock`, and the tests' fakes.
4. Touched listener/queue code → `docker compose restart worker` and check against the fake clock.
