# WASLA deployment guide (Ubuntu VPS)

Written for a single Ubuntu 22.04 or 24.04 VPS. Actually deploying is out of scope for v1; this is the written guide the definition of done asks for. Nothing here has been run on a real server. Commands and variables named below were checked against the code (see the end of the file for anything that could not be confirmed).

The backend is Laravel 13 in `backend/`. The mobile app is Expo in `mobile/` and is not deployed to the server; it talks to the API.

## 1. Server prerequisites

- PHP 8.3 or newer (`composer.json` requires `^8.3`) with: `bcmath`, `ctype`, `curl`, `dom`, `fileinfo`, `gd`, `intl`, `mbstring`, `mysql` (pdo_mysql), `openssl`, `tokenizer`, `xml`, `zip`. `gd` is needed for QR and photo handling, `zip` for wave packages, `intl` for Filament.
- MySQL 8 (the suite passes on MySQL 8.4.13 and MariaDB 10.4). Create a database and a dedicated user; do not use root.
- Nginx, TLS from Let's Encrypt (certbot) or your provider, Composer 2, Supervisor, cron.
- Node is not needed on the server. Filament ships prebuilt assets.

## 2. Domain and TLS

The domain on printed tags is permanent. Decide it before the first real wave (see the launch checklist). `TAG_BASE_URL` (`https://<domain>/t`) is what the QR codes and NFC records contain; tokens survive a later backend change, the domain does not.

Nginx site (adjust paths and domain):

```nginx
server {
    listen 80;
    server_name wasla.example;
    return 301 https://$host$request_uri;
}

server {
    listen 443 ssl http2;
    server_name wasla.example;
    root /var/www/wasla/backend/public;
    index index.php;

    ssl_certificate     /etc/letsencrypt/live/wasla.example/fullchain.pem;
    ssl_certificate_key /etc/letsencrypt/live/wasla.example/privkey.pem;

    # Car photos are accepted up to 10 MB (StoreCarPhotoRequest), so allow a little more.
    client_max_body_size 12m;

    # Reset-password tokens travel in URLs. Do not log query strings, or rotate those logs within days.
    # The format below logs the path only ($uri, not $request_uri).
    log_format wasla '$remote_addr [$time_local] "$request_method $uri" $status $body_bytes_sent';
    access_log /var/log/nginx/wasla.access.log wasla;
    error_log  /var/log/nginx/wasla.error.log;

    location / {
        try_files $uri $uri/ /index.php?$query_string;
    }

    location ~ \.php$ {
        include snippets/fastcgi-php.conf;
        fastcgi_pass unix:/run/php/php8.3-fpm.sock;
    }

    location ~ /\.(?!well-known).* { deny all; }
}
```

Query strings carry password-reset tokens and email verification links. Keep access logging of query strings off (as above) or short-lived, and do the same on any load balancer or CDN in front.

## 3. Get the code and install dependencies

```bash
sudo mkdir -p /var/www/wasla && sudo chown deploy:www-data /var/www/wasla
git clone <repo> /var/www/wasla && cd /var/www/wasla/backend
```

Create `backend/.env` first (section 4), then:

```bash
composer install --no-dev --optimize-autoloader
```

Important: run `composer install` with the **full production `.env` already in place**. `ProductionGuard` runs in `AppServiceProvider::boot()`, so every `php artisan` command, including the ones composer's `post-autoload-dump` scripts run, refuses to start when the production configuration is incomplete. The same `post-autoload-dump` step also regenerates the Filament assets (`filament:upgrade`), so a composer run that fails on the guard also leaves the admin panel without its assets. If you must install before the `.env` is complete, use `composer install --no-dev --optimize-autoloader --no-scripts`, finish the `.env`, then run `composer dump-autoload --optimize` so the scripts run.

```bash
php artisan key:generate        # only on the very first install, then back up APP_KEY (section 9)
php artisan migrate --force
php artisan db:seed --class=BlacklistSeeder --force   # first install only: starter blacklist. Never run plain db:seed in production, it also creates test@example.com
php artisan config:cache
php artisan route:cache
php artisan view:cache
php artisan wasla:make-admin owner@example.com   # prompts for a password of at least 12 characters
```

Do not edit a migration after it has run in production; add a new one.

## 4. Environment variables

Copy `backend/.env.example` to `backend/.env` and set the following. `ProductionGuard` refuses to boot `APP_ENV=production` unless the guarded ones are set.

| Variable | Production value |
| --- | --- |
| `APP_ENV` | `production` (must be one of `local`, `testing`, `production`) |
| `APP_DEBUG` | `false` |
| `APP_KEY` | generated once; see backups |
| `APP_URL` | `https://<domain>`, https required |
| `TAG_BASE_URL` | `https://<domain>/t`, https required |
| `APP_LOCALE`, `APP_FALLBACK_LOCALE` | `tr`, `en` |
| `DB_CONNECTION` | `mysql` |
| `DB_HOST`, `DB_PORT`, `DB_DATABASE`, `DB_USERNAME`, `DB_PASSWORD` | all required |
| `SESSION_DRIVER`, `CACHE_STORE`, `QUEUE_CONNECTION` | `database` (as in `.env.example`) |
| `DB_QUEUE_RETRY_AFTER` | seconds, must be **greater than the longest job timeout**. `GenerateWaveJob` has `$timeout = 3600`, so keep the default `3700` or higher. Lower values make a running wave job get picked up twice. |
| `OPENAI_API_KEY` | required (moderation) |
| `MODERATION_DAILY_CAP` | required, for example `5000`; past it messages pass on the blacklist alone and are flagged |
| `CHAT_CONSENT_REQUIRED` | `true` if the lawyer wants an explicit consent checkbox on visitor chat |
| `PAYMENT_GATEWAY` | `iyzico` or `paytr`; `fake` is refused |
| `IYZICO_API_KEY`, `IYZICO_SECRET_KEY` | live keys, if iyzico |
| `IYZICO_BASE_URL` | unset or `https://api.iyzipay.com`; the sandbox URL is refused |
| `PAYTR_MERCHANT_ID`, `PAYTR_MERCHANT_KEY`, `PAYTR_MERCHANT_SALT` | live values, if PayTR |
| `PAYTR_TEST_MODE` | `0`; `1` is refused |
| `CHECKOUT_LIFETIME_MINUTES` | whole number from 30 to 60 (see section 7) |
| `LEGAL_TEXT_VERSION`, `PRIVACY_NOTICE_VERSION` | bump when the lawyer's texts change |
| `MAIL_MAILER`, `MAIL_HOST`, `MAIL_PORT`, `MAIL_USERNAME`, `MAIL_PASSWORD`, `MAIL_FROM_ADDRESS`, `MAIL_FROM_NAME` | real SMTP; `log` and `array` are refused |
| `AWS_ACCESS_KEY_ID`, `AWS_SECRET_ACCESS_KEY`, `AWS_DEFAULT_REGION`, `AWS_BUCKET` | required (section 8) |
| `AWS_ENDPOINT`, `AWS_URL`, `AWS_USE_PATH_STYLE_ENDPOINT` | for Cloudflare R2 or other S3-compatible storage |
| `TRUSTED_PROXIES` | see section 6 |
| `CORS_ALLOWED_ORIGINS` | empty (the native app needs no CORS) |
| `PASSWORD_RESET_URL` | leave empty to use `APP_URL/reset-password` |
| `EXPO_ACCESS_TOKEN` | optional, for authenticated Expo push |

`.env` must never be committed. `BCRYPT_ROUNDS` should stay 12 (the dummy login hash in `config/wasla.php` uses cost 12).

### Confirm the production guard passes

After `.env` is complete:

```bash
php artisan config:clear
php artisan about --only=environment
php artisan route:list --path=api/v1/me
```

Any `php artisan` command boots the app, so these succeed only when the guard passes. On failure the output is `Unsafe configuration:` followed by one line per problem (for example `PAYMENT_GATEWAY must not be fake in production.`). Fix them all, then run `php artisan config:cache`. A cached config still carries the guarded values (`config/wasla.php` captures them), so `config:cache` does not hide a problem.

## 5. File permissions

```bash
sudo chown -R deploy:www-data /var/www/wasla/backend
sudo find /var/www/wasla/backend -type d -exec chmod 755 {} \;
sudo find /var/www/wasla/backend -type f -exec chmod 644 {} \;
sudo chmod -R ug+rwX /var/www/wasla/backend/storage /var/www/wasla/backend/bootstrap/cache
sudo chmod 640 /var/www/wasla/backend/.env
```

The web server and the queue worker must both be able to write `storage/` (wave packages go to `storage/app/private/waves`, small car photos to `storage/app/private/photos`, logs to `storage/logs`). Run PHP-FPM and the worker as users in the same group, or the same user. Nothing under `storage/app/private` is served by URL.

## 6. Trusted proxies behind TLS

If Nginx terminates TLS on the same machine and PHP sees `127.0.0.1`, or a load balancer sits in front, set `TRUSTED_PROXIES` to the proxy IPs or CIDRs (comma separated), or `*` only when the VPS is reachable solely through a trusted load balancer. Required: the app compares the browser `Origin` header with the scheme and host it sees (`RejectCrossSiteRequest`), and rate limits key on the client IP. Without `TRUSTED_PROXIES` the app sees `http` and the proxy IP, visitor chat posts are rejected as cross-site, and one proxy IP shares every rate limit. Make sure the proxy sends `X-Forwarded-For`, `X-Forwarded-Proto` and `X-Forwarded-Host` (for Nginx: `fastcgi_param HTTPS on;` or `proxy_set_header` lines when it is a reverse proxy).

Verify after deploy: open a tag page, send a chat message from the browser; it must not be refused as cross-site.

## 7. Queue worker, scheduler, payments settings

### Queue worker (required)

All mail (verification, password reset) and wave generation run on the queue, and so does push delivery. With no worker running, nobody receives mail and waves never finish. Supervisor program `/etc/supervisor/conf.d/wasla-worker.conf`:

```ini
[program:wasla-worker]
process_name=%(program_name)s_%(process_num)02d
command=php /var/www/wasla/backend/artisan queue:work --sleep=3 --tries=3 --timeout=3600 --max-time=3600
directory=/var/www/wasla/backend
user=deploy
numprocs=2
autostart=true
autorestart=true
stopasgroup=true
killasgroup=true
stopwaitsecs=3610
redirect_stderr=true
stdout_logfile=/var/log/supervisor/wasla-worker.log
```

`--timeout` must stay below `DB_QUEUE_RETRY_AFTER` (3700 by default). Then `sudo supervisorctl reread && sudo supervisorctl update && sudo supervisorctl start 'wasla-worker:*'`. After every deploy run `php artisan queue:restart`.

### Scheduler

```cron
* * * * * deploy cd /var/www/wasla/backend && php artisan schedule:run >> /dev/null 2>&1
```

Scheduled in `routes/console.php`, all on the Europe/Istanbul clock:

| Time | Command | What it does |
| --- | --- | --- |
| 09:00 | `reminders:send` | owner-set reminders |
| 09:00 | `reminders:expiry` | expiry push at 30, 7 and 1 days |
| 03:00 | `chats:purge` | deletes chat data, moderation events and idle visitor sessions older than 90 days |
| 03:00 | `wasla:scrub-billing` | clears billing details (including the encrypted national ID) from failed payments and from pending payments older than 7 days |
| 03:30 | `queue:prune-failed --hours=168` | prunes failed jobs after a week |
| 03:45 | `auth:clear-resets` | removes expired password reset tokens |

Check with `php artisan schedule:list`.

### Payments: checkout lifetime and callbacks

- `CHECKOUT_LIFETIME_MINUTES` is 30 to 60. 30 is iyzico's fixed checkout token life (1800 s); the same value is sent to PayTR as `timeout_limit`, and while a payment is open no second renewal starts for the tag. PayTR's `timeout_limit` must never exceed this value. Out-of-range values make production refuse to boot.
- Callback URLs, which must be reachable over HTTPS from the providers: `https://<domain>/payments/callback/iyzico` and `https://<domain>/payments/callback/paytr`. The browser return page is `https://<domain>/payments/return`.
- **iyzico: enable the webhook** in the merchant panel and point it at `/payments/callback/iyzico`. Without it, an owner who closes the browser before the callback leaves a paid payment pending, with no retry.
- PayTR: set the callback URL in the merchant panel. It answers a literal `OK`.
- Sandbox first (`IYZICO_BASE_URL=https://sandbox-api.iyzipay.com`, `PAYTR_TEST_MODE=1`, non-production environment), then live keys. The guard refuses sandbox settings in production.

## 8. File storage: S3 or Cloudflare R2

Car photos up to 1 MB are stored on the private local `photos` disk; larger ones go to the `s3` disk (`CarPhotoStore`). The guard therefore requires S3 settings. Create a **private** bucket (no public access, no public listing) and an access key limited to that bucket.

- AWS S3: set `AWS_ACCESS_KEY_ID`, `AWS_SECRET_ACCESS_KEY`, `AWS_DEFAULT_REGION`, `AWS_BUCKET`.
- Cloudflare R2: same four, with `AWS_DEFAULT_REGION=auto`, `AWS_ENDPOINT=https://<accountid>.r2.cloudflarestorage.com` and `AWS_USE_PATH_STYLE_ENDPOINT=true`. Leave `AWS_URL` empty (photos are streamed by the app, never linked).

Photos are served only through the authenticated `GET /api/v1/cars/{car}/photo`.

## 9. Backups

Back up daily, keep copies off the server, and test a restore.

1. **Database dump** (`mysqldump --single-transaction wasla`). It contains payment billing details, including the **national ID number, which is stored encrypted** with Laravel's encrypter. Treat the dump as sensitive and encrypt it at rest.
2. **`APP_KEY`.** The encrypted columns (billing name, address, city, national ID on `payments`) can only be decrypted with the `APP_KEY` that wrote them. Store the key in a password manager or vault, separate from the database backups. Losing it makes those columns unrecoverable, and the accountant's sales export fails. Never rotate `APP_KEY` without a re-encryption plan.
3. **Wave packages** in `storage/app/private/waves` (CSV and QR images of printed tags). Regenerating is not possible for tokens already printed, so keep these copies. Also keep `.env` in the vault.
4. **Photos**: enable bucket versioning or replication if the loss matters; `storage/app/private/photos` holds the small ones.

The backup itself falls under KVKK; the lawyer decides its retention. Billing scrubbing (`wasla:scrub-billing`) acts on the live table only, so older dumps keep what was scrubbed later.

## 10. Updating

```bash
cd /var/www/wasla && git pull
cd backend
php artisan down
composer install --no-dev --optimize-autoloader     # full production .env must be present (section 3)
php artisan migrate --force
php artisan config:cache && php artisan route:cache && php artisan view:cache
php artisan queue:restart
php artisan up
```

Never edit a migration that has run in production. Take a database backup before `migrate`.

## 11. Admin accounts and support commands

- `php artisan wasla:make-admin <email>` creates an admin (prompts for a password of at least 12 characters) or promotes an existing user. The admin panel is at `/admin`.
- `php artisan wasla:erase-account <email> --admin=<admin email>` erases an owner account (this is the path for suspended owners, who cannot delete themselves in the app, and for KVKK requests). It writes an audit row.
- `php artisan wasla:evaluate-moderation <file.csv>` runs a labelled CSV of `text,expected` (`block` or `allow`) through the moderation pipeline and prints block rates and each wrong result. It writes no moderation events and spends no daily cap, but it does call OpenAI for rows the blacklist does not catch. Use it when tuning the blacklist or thresholds (`config/wasla.php`, `moderation.threshold`). The starter set is `backend/tests/Fixtures/moderation/tr-sample.csv`; it needs native-speaker sign-off.
- Wave generation, marking sold, sales export and tag replacement are in the admin panel. A wave is generated by a queued job, so the worker must run.

## 12. Mobile: EAS development build (push notifications)

Expo Go cannot receive remote push (SDK 53+ removed it on Android) and does not register the `wasla://` scheme. Verifying push, and the "open the app" link on the reset page, needs a development build. Production store builds follow the same flow with a different profile.

1. `cd mobile && npm install -g eas-cli && eas login`.
2. `npx expo install expo-dev-client` (it is not yet a dependency of the app; add it and commit the change).
3. `eas init` creates the EAS project and writes its `projectId` into `app.json` under `expo.extra.eas.projectId`. The app reads that value (`mobile/src/push/register.ts` takes `Constants.expoConfig.extra.eas.projectId`, falling back to `Constants.easConfig.projectId`) and registers for push only when it exists. Commit the `projectId` (it is not a secret).
4. `eas build:configure`, then add a `development` profile with `"developmentClient": true` and `"distribution": "internal"` in `eas.json`.
5. Android: `eas build --profile development --platform android`, install the APK on the phone. For push on Android you need an FCM server credential: create a Firebase project, add the Android app (package name from `app.json` or `eas.json`), and run `eas credentials` to upload the FCM V1 service account key.
6. iOS: needs the Apple Developer Program (see launch checklist), then `eas build --profile development --platform ios`; EAS manages the APNs key.
7. Start Metro with `npx expo start --dev-client`, scan the QR code in the development build.
8. In `mobile/.env` set `EXPO_PUBLIC_API_URL` (server origin, https for non-LAN hosts) and `EXPO_PUBLIC_TAG_BASE_URL` (equal to the backend `TAG_BASE_URL`).
9. On the server set `EXPO_ACCESS_TOKEN` if you enable enhanced push security in the Expo dashboard.
10. Verify: sign in, open the chats list, allow notifications; have a visitor send a message on a tag page; the phone must get a push and tapping it opens the thread.

Release builds only accept `https` API URLs; plain http is accepted only in a development build for localhost or LAN addresses.

## 13. Not confirmed

See the list at the end of the verification section of `docs/launch-checklist.md` and the "Unconfirmed in this guide" list below.

Unconfirmed in this guide:

- Nothing in sections 2 to 10 has run on a real Ubuntu server; Nginx, Supervisor, certbot and cron snippets are standard but untested here.
- `expo-dev-client` is not yet in `mobile/package.json`; `eas.json` and a `projectId` do not exist yet. Section 12 describes creating them.
- The exact Android package name and the FCM / APNs credentials are not configured in the repo.
