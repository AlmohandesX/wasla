# WASLA: Smart QR/NFC Car Sticker Platform

Design document, 2026-11-05. Status: awaiting approval.

"Owner's decision" means the project owner stated it. "Builder's call" means it was chosen during design and can be overruled. "WASLA" as the product name comes from the project folder and is an assumption.

## 1. Intent

A car owner in Turkey buys a sticker with a QR code and NFC tag. A stranger who needs to reach the owner, for example because the car is blocking them, scans it and sees a message, emergency contacts and an anonymous chat. The owner is reachable without exposing their phone number through chat, and can go dark at any moment. Revenue comes from sticker sales and a yearly renewal.

**The moment that matters most:** a stranger, probably in a hurry and with no app, standing next to a car. The public page must load fast and make the next step obvious.

### Goals

- Mobile app for car owners, public scan page for visitors, web admin panel, and the Laravel API behind them.
- Production-grade security as specified in section 7, with real checks and no placeholder logic.

### Non-goals for v1 (builder's call)

- Attachments or images in chat. Chat is text only.
- Automatic recurring card charges. Renewal is a one-time yearly payment the owner starts.
- Selling stickers through the app, reseller accounts, or automated invoicing and e-Arşiv integration.
- Live deployment and app store submission, which are a separate later phase (owner's decision).
- Phone/SMS verification. Accounts use email and password.
- Native in-app NFC reading. NFC tags hold the same URL as the QR code, so the phone opens the public page on its own. Tags are written by the print supplier.

### Anti-goals: failure even if it technically works

- A visitor can learn the owner's real phone number through chat.
- A guessed or enumerated URL reveals someone's car.
- Unlinking and re-linking a tag grants a free new year.
- Harassment reaches an owner with no way to block it.

## 2. Stack

| Part | Choice | Source |
|---|---|---|
| Backend | Laravel 13 on PHP 8.3 or newer, installed locally by the owner, separate from XAMPP's PHP 8.2 | Owner's decision |
| Production host | VPS, so long-running queue workers are available | Owner's decision |
| Database | MySQL in production, XAMPP MariaDB 10.4 locally. Standard migrations and Eloquent only | Owner's decision |
| Mobile | React Native with Expo, core components only, white and royal blue | Owner's decision |
| Mobile testing | Expo Go on a physical phone, EAS cloud builds later for push | Owner's decision |
| Moderation | OpenAI Moderation API plus local blacklist | Owner's decision |
| Payments | Modular service with iyzico and PayTR drivers | Owner's decision |
| Files | Local up to 1 MB, S3/Cloudflare R2 above 1 MB | Owner's decision |
| Admin panel | Filament, in the same Laravel app. Needs `extension=intl` enabled in `php.ini` | Builder's call |
| Visitor page | Server-rendered Blade with minimal JavaScript, for speed | Builder's call |
| Mobile auth | Laravel Sanctum tokens, stored in `expo-secure-store` | Builder's call |
| Layout | `backend/` and `mobile/` folders inside `WASLA/` | Builder's call |

The original brief said Laravel 11, but its security support ended on 2026-03-12, so the owner moved the project to Laravel 13, which is supported until Q1 2028.

## 3. Core rules

### Tags and waves

- A tag has a printed serial for support (example `W001-000123`) and a random, non-sequential token of at least 95 bits. The printed URL is `https://<domain>/t/<token>`.
- **The domain is permanent once printed.** It must be final before the first real wave. Tokens must also survive any later backend change.
- Admins generate a wave by size, as a queued job. Limit of 50,000 per wave (builder's call). Output is a downloadable package: a CSV of serials and URLs for NFC writing, plus QR images for print.
- Wave statistics: **pending** (generated, not sold), **sold** (marked sold by an admin by wave or serial range), **activated** (claimed at least once).
- A tag not yet marked sold can still be claimed, and is marked sold at that moment, so a shop's missed paperwork never blocks a customer (builder's call).

### Subscription

The subscription belongs to the **tag**, not the user (builder's call). This is what enforces the anti-goal on free years.

- The first claim sets `first_activated_at` and `expires_at` one year later. Neither is ever reset by unlinking or claiming.
- Claiming uses a database transaction with a row lock, so two people cannot claim one tag.
- Renewal extends from the later of the current expiry and the payment time.
- Expiry reminders by push at 30, 7 and 1 days before (builder's call).
- **After expiry, for the owner:** a hard-stop "Subscription Expired - Please Renew" screen for that car only. Other paid cars keep working. The renewal flow, unlinking, account settings and account deletion stay reachable.
- **After expiry, for visitors:** a neutral "this tag is not active" page with no owner data and no chat.

### Transfer

The owner unlinks a tag after a confirmation step, including when it has expired. The status, contacts, reminders and chat tied to that car are detached from the tag. The tag becomes claimable and keeps its existing expiry date. The next person to scan and claim gets it, so the app tells the owner to hand it over directly.

### Cars, lost stickers and released tags (builder's calls)

- A car and an active tag are one to one. A car record belongs to its owner and survives without a tag, so it can be linked to another tag later.
- **Damaged or lost sticker:** an admin replaces the tag. The old tag is permanently revoked and shows the neutral page, and its exact expiry date moves to the new tag. This is the only way an expiry moves between tags, is audited, and never adds time. A revoked tag can never be claimed.
- **Previous owner unreachable:** an admin can force-release a tag after checking evidence, such as proof the car changed hands. The released tag keeps its expiry date, so the new owner renews if it has lapsed.
- **Account deletion:** the owner's tags become claimable with their expiry dates kept. Personal data, cars and chats are erased. Payment records are kept as financial records, with the personal link removed where the law allows.
- **Suspended user:** their tags show the neutral page and they cannot log in. Their expiry dates keep running.

## 4. Car owner app

Screens: login, register, forgot password, my cars, add car by scanning, car detail and settings, status picker, reminders, chats list, chat thread, renewal, expired hard stop, account settings with delete account.

- **Linking:** the in-app camera scanner (`expo-camera`) reads the QR code. This works in Expo Go.
- **Stealth mode:** one-tap toggle on the car card, applied immediately. While on, the visitor sees the same neutral page as an inactive tag, with no contacts and no chat. An inactive tag and a stealthed tag must be indistinguishable.
- **Car settings:** model, plate, default message, emergency contacts. Plate and owner name are never shown publicly (builder's call).
- **Dynamic status:** presets plus custom text up to 140 characters, with a duration. Expiry is evaluated at read time, so reverting to the default message needs no scheduled job. Custom text passes moderation.
- **Reminders:** oil change and license renewal dates per car. Sent through the Expo push service by a daily scheduled job in `Europe/Istanbul`. Remote push cannot be tested in Expo Go since SDK 53, so full push testing waits for a development build.
- **Chat:** thread list, reply, block visitor, report. Polling every few seconds while open, and a push notification for new messages.
- **Language:** Turkish by default, plus English, all strings translatable from day one (builder's call). Turkish copy needs review by a native speaker.

## 5. Visitor page

Public, no login, `noindex`, works on any phone browser.

- **Shows:** the current status or default message as the largest thing on the page, emergency contacts, and a "Message the owner" button.
- **Emergency contacts:** the brief requires them on this page, which means those numbers are public by nature. To limit scraping, each one is shown by label and revealed by a tap, with rate limiting. The app tells owners that these numbers are visible to anyone who scans (builder's call).
- **States:** unclaimed tag (explains how to get the app and claim it), active, and neutral (inactive or stealthed).

## 6. Proxy chat and moderation

- A visitor gets an anonymous session through a random, HttpOnly cookie, with no account. Replies are seen only by returning on the same browser while the session lasts. Polling only, no WebSocket server, to keep hosting simple (builder's call).
- Text only, 500 characters. Owners can block a visitor session, and either side can report. Apple requires blocking and reporting for user-generated content.
- Limits: 10 messages per 10 minutes per session and 3 new threads per hour per IP, configurable. Limits stay loose enough for shared mobile network IPs.
- **Against a blocked harasser (builder's calls):** a block covers the session and applies a temporary cooldown for that IP on that tag only. Owners also get a per-tag "pause new chats" switch, with stealth mode as the last resort. No device fingerprinting, which would add privacy obligations.
- **Phone numbers:** the first anti-goal means the system never exposes an owner's number by itself. An owner may choose to type one, and it is not filtered (builder's call).
- **Visitor notice:** the chat screen says before the first message that text is checked by an automated service outside Turkey. There is no consent step, to keep the page fast. A configuration switch can add a consent checkbox, with blacklist-only moderation for anyone who declines, if the lawyer requires it (builder's call).
- Messages are deleted after 90 days (builder's call).

**Moderation pipeline, on the server, for both chat directions and public status text:**

1. Normalize text with correct Turkish casing (`I/ı`, `İ/i`) and common letter substitutions, then check the local blacklist. A hit blocks the message and OpenAI is not called.
2. Call OpenAI `omni-moderation-latest` server-side. Block if any category passes its configured threshold.
3. If OpenAI fails, times out or the daily call cap is reached, the message passes on the blacklist result alone and is flagged for admin review (builder's call). Reasoning: someone may be trying to report a blocked car or an emergency, so an outage must not silence them.

Admins manage the blacklist and review blocked and flagged messages. The endpoint is reported as free, but rate limits apply and vary by account, so calls are capped daily anyway.

**Must be tested before launch:** Turkish accuracy is unverified. OpenAI publishes no language list that could be confirmed, and there are reports of weaker results outside English. The blacklist therefore carries real weight, and thresholds need tuning against a set of Turkish harassment and normal messages.

## 7. Security checklist

Each item needs an automated test where testable.

| Area | Requirement |
|---|---|
| Secrets | No third-party keys in mobile code. All payment and moderation calls go through Laravel |
| Login | Lock for 15 minutes after 5 failures, tracked by account and by IP, on owner and admin login |
| Environment | Boot fails in production if required variables are missing, `APP_DEBUG` is true, or `APP_ENV` is wrong |
| Authorization | Laravel policies on every owner resource. Admin routes require an admin role. Tests prove user A cannot reach user B's cars, chats or payments |
| Input | Form Requests on every write. Output escaped by default. Eloquent or bound queries only |
| Rate limits | Named limits on every API route, tighter on login, claim, chat and contact reveal |
| Spend caps | Daily call cap for moderation, plus any cap offered by provider dashboards |
| Uploads | Only the owner's private car photo in v1. JPEG, PNG or WebP, checked by content, 10 MB limit. Files above 1 MB go to object storage, others to local storage |
| Network | HTTPS forced, HSTS, CSP and security headers, CSRF on web routes, secure HttpOnly SameSite cookies, CORS limited to known origins |

## 8. Payments

A `PaymentGateway` interface with drivers per provider. Selection by configuration, so adding or switching one touches no business logic.

- **Flow:** the app asks the backend to start a renewal, the backend creates a hosted checkout with the provider, the owner pays on the provider's page, and the provider's signed callback extends the subscription.
- **Rules:** the callback is the only thing that can extend a subscription. It verifies the signature, re-checks the status with the provider, and is idempotent. The app's return is never trusted. No card data touches our servers.
- **Build order:** iyzico first, then PayTR (builder's call). A fake driver exists for local development and refuses to boot in production.
- **Billing form (owner's decision):** renewal collects the buyer details the provider requires and passes them on, storing only what the law requires. Each provider's exact required fields are confirmed against its current docs during integration. These may include a national ID number, so they fall under the KVKK notice.
- **Invoices (owner's decision):** the owner's accountant issues them by hand from an admin export of sales.
- **Refunds (owner's decision):** handled by an admin by hand, and a refund shortens the subscription. The accountant or lawyer confirms the rules, including any right of withdrawal.
- **Records:** a `payments` table with provider, reference, amount, currency and status. Admins set the renewal price in TRY.
- **Needs from the owner:** live keys require a merchant account with each provider. Fees need comparing.

**App Store risk:** Apple's rules send physical goods and services consumed outside the app away from in-app purchase, which fits a physical sticker service, but a reviewer could read the renewal as a digital subscription. No Turkey-specific rule could be confirmed. Android and iOS are both kept on hosted checkout for v1. Get a written answer from Apple Developer Support before iOS submission, because a different answer means adding in-app purchase.

## 9. Admin panel

Admin login with lockout. Manage users (view, suspend), tags (replace, revoke, force-release, all audited), subscriptions (view, manually extend with an audit record), waves (generate, download, mark sold, statistics), payments, blacklist, and moderation review. Admin accounts are created by a command, not through registration.

## 10. Legal and data

Not legal advice. A Turkish lawyer should review these before launch.

- **KVKK:** plates, phone numbers and chat are personal data. The app needs a privacy notice and consent screens.
- **Cross-border transfer:** sending chat text to OpenAI moves data out of Turkey. It needs disclosure and possibly explicit consent. Only the text is sent, no user or car identifiers.
- **Distance selling:** paid renewal needs the legally required pre-information and contract text.
- **Account deletion:** in the app, as Apple requires and KVKK expects.

## 11. Look and feel

White and royal blue (`#4169E1` as the base, checked for contrast and darkened where text needs it), as the owner decided.

The `ui-ux-pro-max` recommendation was motion-heavy, dark slate with red, and a futuristic font. That contradicts the owner's colors and the moment in section 1, so only its accessibility and interaction checklist is kept: 44 px touch targets, 4.5:1 contrast, visible loading and error states, SVG icons, and respect for reduced motion.

**The idea, following `frontend-design`:** the visitor page reads like a note left on a windshield. One large message in plain words fills the top of the screen, with a single clear action beneath it, and nothing decorative. The app stays quiet and practical around that, with stealth mode as its one strong control: when on, the car card visibly changes so the owner never has to wonder. The font must fully support Turkish; the exact choice is left to the builder.

## 12. Definition of done

Done means fully working and tested locally, with a written guide for deploying to a VPS (owner's decision). The launch gate below belongs to the later go-live phase.

- Automated tests cover section 7, claiming races, subscription rules including the free-year anti-goal, the payment callback, and moderation fallback.
- Migrations run cleanly on local MariaDB 10.4 and on MySQL.
- All essential screens run in Expo Go on a physical phone, except remote push, which is verified on a development build.
- The visitor page is checked at phone width, in Turkish.
- **Launch gate:** the builder supplies a starter Turkish blacklist and labelled test set and tunes the thresholds. A native Turkish speaker named by the owner signs off on them before launch.

## 13. Left to the builder

File and class structure, schema details, exact rate limit numbers not listed above, polling intervals, QR image format, the queue and cache drivers, and test tooling.
