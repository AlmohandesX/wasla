# WASLA

WASLA is a QR and NFC sticker service for cars. A driver sticks a tag on the car; anyone who scans it lands on a plain page where they can leave a note or start an anonymous chat with the owner, without ever seeing the plate, the owner's name or a phone number. The owner manages the car in a mobile app (stealth mode, status messages, emergency contacts, reminders, chat), and each tag carries a one-year subscription that is renewed through a hosted card checkout (iyzico or PayTR). Messages are checked by a blacklist and OpenAI moderation. English is the default language, Turkish the second.

The design is in `docs/superpowers/specs/2026-11-05-wasla-design.md`.

## Repository layout

| Path | What it is |
| --- | --- |
| `backend/` | Laravel 13 (PHP 8.3+): JSON API for the app, the visitor pages (`/t/{token}`), payment callbacks, the Filament admin panel at `/admin`, queued jobs and scheduled commands |
| `mobile/` | Expo SDK 58 app (React Native, Expo Router, TypeScript) |
| `docs/deployment.md` | Ubuntu VPS deployment guide, including the EAS development build for push |
| `docs/launch-checklist.md` | Everything the owner, lawyer, accountant, native speakers, Apple and the payment providers must do before launch, plus the numbered hand checks |
| `docs/superpowers/` | Spec and implementation plan |

## Local setup on Windows

Prerequisites: PHP 8.3 at `C:\php83` (XAMPP's PHP 8.2 is too old and stays untouched), XAMPP MariaDB 10.4 on port 3306, Composer, Node 20+ and npm.

Backend (Git Bash; `php` on `PATH` is XAMPP's 8.2, so put 8.3 first):

```bash
export PATH=/c/php83:$PATH
cd backend
php /c/ProgramData/ComposerSetup/bin/composer.phar install
cp .env.example .env            # DB defaults match XAMPP MariaDB: 127.0.0.1:3306, user root, no password
php artisan key:generate
# create the databases `wasla` and `wasla_test` in phpMyAdmin or: C:\xampp\mysql\bin\mysql.exe -uroot -e "create database wasla; create database wasla_test"
php artisan migrate --seed      # seeds the starter blacklist and a test user
php artisan wasla:make-admin you@example.com    # admin for /admin (password of at least 12 characters)
```

`.env.example` uses `PAYMENT_GATEWAY=fake`, which shows a local fake checkout page and refuses to boot in production. Use `MAIL_MAILER=log` (the default) and read verification and reset links in `backend/storage/logs/laravel.log`.

## Running the backend and the app on a phone (Expo Go over LAN)

The phone and the PC must be on the same Wi-Fi. Use the PC's LAN IP (`ipconfig`), shown here as `192.168.1.10`; allow PHP through the Windows firewall for port 8000.

1. In `backend/.env` set `APP_URL=http://192.168.1.10:8000` and `TAG_BASE_URL=http://192.168.1.10:8000/t` (verify and reset links use `APP_URL`, so the phone must be able to reach it). Set a renewal price in the admin panel under Settings (`renewal_price_kurus`, in kuruş).
2. Start the web server, bound to the LAN:
   ```bash
   php artisan serve --host=0.0.0.0 --port=8000
   ```
3. In another terminal start the queue worker. It is required: verification and reset mail, push and wave generation all run on the queue.
   ```bash
   php artisan queue:work
   ```
4. In `mobile/`:
   ```bash
   npm install
   cp .env.example .env
   ```
   and set `EXPO_PUBLIC_API_URL=http://192.168.1.10:8000` and `EXPO_PUBLIC_TAG_BASE_URL=http://192.168.1.10:8000/t` (the same value as the backend `TAG_BASE_URL`). Plain `http` is accepted only for localhost and LAN addresses in a development build; a release build requires `https`.
5. `npx expo start`, then scan the QR code with an Expo Go version that supports SDK 58.

Limits of Expo Go: remote push notifications and the `wasla://` link need a development build (see `docs/deployment.md`, section 12). The password reset web page works in Expo Go.

To get a tag to scan: sign in to `/admin`, open Waves, generate a small wave (the worker must be running), and show a QR image from the downloaded package on a screen.

## Running the tests

Backend (from `backend/`, with the PHP 8.3 `PATH` above). The suite uses the `wasla_test` MariaDB database that `phpunit.xml` pins:

```bash
php artisan test
```

To run it on MySQL instead, override the connection on the command line, for example `DB_CONNECTION=mysql DB_PORT=3307 DB_DATABASE=wasla_test_my php artisan test` against a MySQL on that port (real environment variables win over `phpunit.xml`).

Mobile (from `mobile/`):

```bash
npx jest
npx tsc --noEmit
npx expo export --platform android     # confirms the bundle builds
```

Moderation tuning: `php artisan wasla:evaluate-moderation backend/tests/Fixtures/moderation/tr-sample.csv` prints block rates and every wrong result for a labelled CSV (`text,expected`). The Turkish starter set needs sign-off from a native speaker before launch.

## Documents

- [Design spec](docs/superpowers/specs/2026-11-05-wasla-design.md)
- [Deployment guide](docs/deployment.md)
- [Launch checklist and hand checks](docs/launch-checklist.md)
