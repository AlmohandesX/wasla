# WASLA launch checklist

Source: spec sections 8, 10 and 12, plus the open items recorded while building. Tick items off here as they are done. Not legal or tax advice: the lawyer and the accountant decide the items assigned to them.

Nothing below has been done by the build. The automated tests, the local API journey and the build checks have all passed (see "What is verified" at the end), but every item in this file is outside what the build could check.

## Owner

- [ ] **Final domain.** Choose it before the first real wave. The domain is printed on tags and is permanent (spec section 3). Set `APP_URL`, `TAG_BASE_URL` (`https://<domain>/t`), the mobile `EXPO_PUBLIC_API_URL` and `EXPO_PUBLIC_TAG_BASE_URL` to it.
- [ ] Deploy the backend following `docs/deployment.md` (TLS, `TRUSTED_PROXIES`, queue worker, cron, S3 or R2, backups). Confirm `ProductionGuard` passes and `php artisan schedule:list` shows the six jobs.
- [ ] **Back up `APP_KEY` separately from the database.** The billing national ID is encrypted with it.
- [ ] Keep access logging of query strings off or short-lived on Nginx and any load balancer (reset tokens are in URLs).
- [ ] Set the renewal price (admin settings, key `renewal_price_kurus`, in kuruş) and confirm the first admin account exists (`php artisan wasla:make-admin`).
- [ ] Name the **native Turkish speaker** and the **native English speaker** who will do the reviews below.
- [ ] Compare provider fees and open **merchant accounts** with iyzico and PayTR (or pick one); see "Payment providers".
- [ ] Apple Developer Program enrollment and a Google Play account for store submission; see "Apple".
- [ ] Decide `CHAT_CONSENT_REQUIRED` with the lawyer (adds a consent checkbox to visitor chat; visitors who decline get blacklist-only moderation).
- [ ] Final triage items the review will list (per-car contact cap, per-user moderation budget, OFL licence text for Figtree fonts, styled 404/429 pages, `/` still serves the Laravel welcome page) are decided after the final review, not here.
- [ ] Do the **Hand checks before launch** below, on a real phone and in a real browser.

## Native Turkish speaker

The Turkish starter set and blacklist were written without a native speaker. They are a starting point, not a validated benchmark.

- [ ] **Sign off the starter labelled set** `backend/tests/Fixtures/moderation/tr-sample.csv` (28 rows). Review every row, add real parking and neighbour messages, harassment with spelling tricks, and polite messages that look rough. This is the launch gate in spec section 12.
- [ ] Run `php artisan wasla:evaluate-moderation backend/tests/Fixtures/moderation/tr-sample.csv` against the seeded database with a real `OPENAI_API_KEY`, and tune `config/wasla.php` `moderation.threshold` or per-category `thresholds`. On the seeded blacklist **without** an OpenAI key the starter set gives 13 of 14 harassment rows blocked and 1 of 14 ordinary rows wrongly blocked, which shows the real gaps:
  - `sıkık` (bored) is blocked because `sikik` folds the same way (known false positive).
  - Letters separated by spaces (`o r o s p u`) are missed by the blacklist and depend on OpenAI; decide whether to handle them.
- [ ] Review `BlacklistSeeder` terms: substring stems and known false positives (`sikik`/`sıkık`, `niger`), and add missing slurs. Terms are edited in the admin panel afterwards.
- [ ] Review **all Turkish strings**: `backend/lang/tr/*` (including admin panel strings), the mobile Turkish strings, the visitor page, the emails, status presets, and the draft privacy notice wording. Check the characters İ ı Ş ş Ğ ğ Ç ç Ö ö Ü ü render correctly everywhere.
- [ ] Confirm the validation messages in `lang/tr/validation.php` read naturally; server field messages can still show English in places (for example some `errors.*` fields).

## Native English speaker

- [ ] Review the English strings (`backend/lang/en/*`, the mobile English strings) and the English side of the blacklist.

## Lawyer (Turkish law; KVKK)

- [ ] **KVKK:** privacy notice text (the app currently shows a draft label; `PRIVACY_NOTICE_VERSION` records which version owners accepted) and consent screens. Plates, phone numbers and chat are personal data.
- [ ] **Cross-border transfer:** chat text is sent to OpenAI (text only, no user or car identifiers). Decide the disclosure and whether explicit consent is needed (`CHAT_CONSENT_REQUIRED`).
- [ ] **Distance selling (mesafeli satış):** the Turkish pre-information form (ön bilgilendirme formu) and the distance-sales contract shown before payment, plus sales terms and delivery wording for a subscription on a physical tag. Set `LEGAL_TEXT_VERSION` to the approved version. Note the version stored on a payment is server config, not what the client displayed.
- [ ] **Right of withdrawal** for renewals, and refund rules (see Accountant).
- [ ] **Retention periods:**
  - Payment records and billing details: the scrub job clears billing details (including the national ID) from failed payments and from pending payments older than 7 days. Confirm 7 days is right, and the retention for paid payments' billing details. (A paid callback arriving more than 7 days late would find billing details already cleared.)
  - Chat data, moderation events and idle visitor sessions are purged after 90 days; moderation rows keep visitor text for up to 90 days even after the car is deleted. Confirm.
  - Backups: how long dumps (which hold the encrypted national ID) may be kept.
- [ ] **Account deletion and suspended owners:** suspended owners cannot delete themselves in the app (deleting must not wipe evidence of abuse). Support erases them with `php artisan wasla:erase-account <email> --admin=<admin email>`. Decide when that must happen (KVKK requests) and the policy.
- [ ] Audit log free-text reasons can name a person; confirm this is acceptable and how long audit rows are kept.
- [ ] Apple/Google review texts that mention data handling (app privacy labels), derived from the above.

## Accountant

- [ ] Confirm the **invoice process**: the accountant issues invoices by hand from the admin sales export (`;` delimiter, comma decimal, for Turkish Excel). Check the export opens correctly and has the fields needed, including the national ID (TCKN).
- [ ] Confirm the **national ID** is needed on official invoices, and the retention period (with the lawyer).
- [ ] Confirm **refund** handling: refunds are done by an admin by hand and shorten the subscription. Confirm the rules and any right of withdrawal.
- [ ] Confirm the VAT (KDV) treatment of the renewal price and how the price is entered (`renewal_price_kurus`).

## Payment providers

Run each provider live in its **sandbox** before any live keys; nothing has been run against a real sandbox. The fixtures in the tests are the builder's reading of the docs and every unknown fails closed (no subscription is extended; a genuine payment could stay pending).

iyzico (sandbox keys, `IYZICO_BASE_URL=https://sandbox-api.iyzipay.com`, public HTTPS `APP_URL`):

- [ ] Initialize succeeds and the answer's signature verifies.
- [ ] Pay with a test card: the callback arrives, the retrieve signature verifies, the subscription extends once.
- [ ] **Decline with a failing test card** and confirm the shape of the failed retrieve (`status`, `paymentStatus`, signature, null `paymentId`). If it answers `status=failure` or fails the signature, the payment stays pending and the owner sees "Invalid callback." as plain text, which needs adjusting.
- [ ] Confirm `price` and `paidPrice` in real answers normalize to what iyzico signs.
- [ ] Confirm `shippingAddress` on virtual items and `enabledInstallments: [1]` are accepted.
- [ ] **Enable the iyzico webhook** in the merchant panel, pointing at `https://<domain>/payments/callback/iyzico`. Without it, a browser closed before the callback leaves a paid payment pending with no retry. Verify the webhook token and a late retrieve.
- [ ] Fraud-review rows stay pending: confirm they are not extended and an admin can see them.

PayTR (`PAYTR_TEST_MODE=1` for the sandbox run):

- [ ] Set the callback URL in the merchant panel to `https://<domain>/payments/callback/paytr` (answer is a literal `OK`).
- [ ] Confirm `https://www.paytr.com/odeme/guvenli/{token}` opens as a **full page**. The docs only show it as an iframe source; if it does not open, we need our own page embedding the iframe.
- [ ] Pay and fail a test transaction. Confirm the inquiry's amount format, the empty `returns` shape (the code throws, and PayTR retries, on any `returns` value it does not recognise) and `err_no` 004 for a failed payment.
- [ ] Confirm the **`+90` phone format** in `user_phone` is accepted, and try an IPv6 buyer address.
- [ ] `timeout_limit` is sent from `CHECKOUT_LIFETIME_MINUTES` (30 to 60). Confirm PayTR honours it and that it never exceeds the checkout lifetime shown in the app.

Both providers, before go-live:

- [ ] Live keys set, `IYZICO_BASE_URL` unset or `https://api.iyzipay.com`, `PAYTR_TEST_MODE=0`, `PAYMENT_GATEWAY` set. The guard refuses to boot otherwise.
- [ ] One real low-value live payment, then an admin refund by hand, end to end.

## Apple

- [ ] Get a **written answer from Apple Developer Support** on whether the renewal (a hosted checkout for a physical sticker service) may stay outside in-app purchase, before iOS submission (spec section 8). If the answer is no, in-app purchase must be added; Android and iOS stay on hosted checkout until then.
- [ ] Apple Developer Program enrollment, bundle identifier, APNs key via EAS, app privacy answers (with the lawyer), the account deletion flow shown to the reviewer (it is in the app).
- [ ] Review notes giving the reviewer a test account and a test tag.

## Hand checks before launch

These are the phone and browser checks nobody could do during the build. They are merged and deduplicated from the Task 18 to 21 reports plus the admin panel. Do them on a real phone with the Expo app and in a desktop browser, in Turkish and again in English where noted. Tick each one off.

Setup: `docs/deployment.md` for a deployed server, or the local setup in `README.md` (LAN IP in `EXPO_PUBLIC_API_URL`, `EXPO_PUBLIC_TAG_BASE_URL` equal to the backend `TAG_BASE_URL`, `php artisan serve --host=0.0.0.0`, `php artisan queue:work`, `APP_URL` set to the PC's LAN address so verify and reset links open on the phone, `PAYMENT_GATEWAY=fake`). A renewal price must exist (`renewal_price_kurus`) and an account needs at least one tag (admin panel, Waves, generate).

Sign-in and account (Tasks 18 and 21)

1. Confirm Expo Go opens the app on SDK 58 (or use a development build). Register; the privacy notice and its draft label are visible, and submitting without the checkbox is blocked with a message.
2. After registering you are held on the verify-email screen and cannot reach the home screen (try Android back). Tap "I've verified" before verifying: the "not verified yet" message appears. Try "Resend".
3. Open the verification link (in local setups it is in `backend/storage/logs/laravel.log`, produced by the queue worker) and tap "I've verified": you land on the cars list.
4. Sign out, sign in. Enter a wrong password five times: the next attempt shows the lockout message with minutes.
5. Forgot password: request a link, open it on the phone in the browser, set a new password on the page, and sign in with it. Reopen the same link: "invalid or expired". Check the page in English (phone language) too. Expo Go cannot open `wasla://`, so the web page is the reset path there; on a development build also try the page's "Open the app" link.
6. Switch language Turkish/English on login and in the account screen; check İ ı Ş ş Ğ ğ render correctly and the choice survives an app restart.
7. Offline: with the server or Wi-Fi off, sign in shows a network message and does not crash. Sign in, kill and reopen the app offline: the retry screen appears (with Retry and Sign out), not a forced sign-out.
8. Suspend the account in the admin panel while signed in, reopen the app: the login screen shows the suspended message, not a retry loop.
9. Accessibility by eye and with TalkBack/VoiceOver: touch targets, focus, error states, labels on inputs, the show/hide button, the stealth and pause switches, the camera and buttons, each chat message read with sender and time.

Cars, scanner, stealth (Task 19)

10. Generate a tag in the admin panel and show its QR on a screen. Tap "Etiket tara, araç ekle", allow the camera, scan it, enter model and plate: the car appears in the list.
11. Deny the camera permission on a fresh install: the explanation and the "Ayarları aç" button work.
12. Scan a non-Wasla QR code: the "not a Wasla tag" message, no claim, scanning resumes after a moment. Hold a valid QR steady: only one claim fires. After a refused claim (for example a tag owned by another account) the camera does not retry by itself and "Scan again" works.
13. Scan a second tag to add a second car; scan the first again: "already linked to your car".
14. Toggle stealth on the car card: it turns dark with a dashed border and "Stealth on"; open the tag's public page in a browser and confirm it changed to the neutral page. Toggle with the server stopped: the error shows and the card goes back to its old state ("Updating" label while pending). Do the same for "Pause new chats", and confirm the tag's chat page refuses new chats while paused.
15. Set a 1-minute status (also try a quick-message preset: it fills the field and stays editable); open the public page, wait a minute, confirm it reverts and the card hides it.
16. Car settings: edit model, plate and default message; type a blocked word and see the message beside the field; add an emergency contact (an invalid number first), see the "anyone who scans can see them" warning, delete it; upload a large photo from the gallery and confirm it shows on the car page; remove it.
17. Open the public tag page on a phone (Turkish): the note reads clearly at phone width, with no plate or owner name.

Chat and push (Task 20)

18. Open the chats list: the notification prompt appears there, not at launch; tap it and answer the OS prompt (on Expo Go for Android expect it to be hidden or do nothing, with no crash).
19. With the visitor page open on another device, send a message: within about 5 seconds it appears in the open thread and the chats list shows an unread badge.
20. Reply from the phone: it shows "sending", then settles, and appears once on the visitor page and once in the thread (no duplicate).
21. Send a message containing a blacklisted word: the shared refusal text shows and the text stays in the input.
22. Turn Wi-Fi off and send: a failed bubble with Retry and Remove; the text stays in the input. Turn Wi-Fi on and Retry.
23. Report a visitor message: confirmation, then it shows "Reported". Block the visitor: confirmation, the composer is replaced by the blocked notice, and the visitor can no longer write.
24. Pause new chats in car settings, return to a thread: the notice with a link to the setting works. Unlink the tag from the car and open its thread: the unlinked notice, no composer.
25. Background the app: polling stops (check the server log) and resumes on return.
26. Reminders: add an oil change date as 31.12.2026, a past date is refused, delete one; check both languages.
27. **Push (development build only):** sign in, receive a visitor-message push, tap it, the thread opens; sign out and confirm no more pushes to that phone. Also check the 30/7/1-day expiry reminder pushes (`php artisan reminders:expiry` with a tag expiring in 7 days).

Expiry, renewal, transfer, delete (Tasks 19 and 21)

28. Use an account with two linked cars; set one tag's `expires_at` in the past in the database. The cars list shows the expired notice on that card; it is not openable as a normal car and has no stealth switch. The other car works normally (status, chats, reminders).
29. Open the expired car: the hard stop "Abonelik Sona Erdi - Lütfen Yenileyin" with renew and release options. A stale deep link or chat for that car also lands there.
30. Tap Renew. Submit empty (errors beside fields), then a wrong ID (checksum error), a one-word name, then valid details (the test number 10000000146 is a valid TC Kimlik). The ID field is hidden text with a numeric keyboard and the explanation is visible.
31. The fake checkout page opens in the in-app browser (Android Custom Tab or iOS sheet). Tap Pay, close the browser: "Payment received" with the new expiry date about one year ahead (Istanbul), and the card returns to normal.
32. Repeat with Fail: "Payment not completed", and Try again returns to a form with an empty ID field. Repeat but close the browser without paying: the "still being confirmed" screen with Check again and no failure wording; then pay and tap Check again. Leave a checkout abandoned past its lifetime and confirm the "Start a new payment" offer.
33. Double-tap "Continue to payment": only one payment starts. Leave the renew screen and return: the ID field is empty.
34. Transfer: open "Etiketi devret", read the warning, confirm the Release button stays disabled until the box is ticked, release, then claim the same tag from a second account. The expiry date is kept. Release from the expired screen too.
35. Account screen (from the cars list and from the expired screen): change language, sign out, sign in again.
36. Delete account: a wrong password shows the error beside the field; trigger the throttle (more than 3 wrong tries in a minute) and confirm the retry-minutes message; the correct password returns to login with the "account deleted" message; signing in with the old account fails; pushes no longer arrive on the phone. A suspended account shows the suspended sign-out message instead (support erases those by command).

Admin panel in a desktop browser (Task 15; the build only confirmed that `/admin/login` and its 11 scripts and styles load)

37. Open `/admin/login`, check it renders properly with working styles, sign in with an admin account, and confirm the lockout after repeated wrong passwords. A non-admin account must not get in.
38. Waves: generate a wave (the worker must run), watch it become ready, download the package (signed link): the CSV of serials and URLs and the QR images open, URLs use the final domain. Mark sold by wave and by serial range; check the statistics (pending, sold, activated).
39. Tags: replace, revoke and force-release a tag; each is audited and the expiry moves exactly as the spec says (replacement never adds time).
40. Users: view and suspend/unsuspend a user. Payments: list, open one, see the national ID masked, download the sales export (audited) and open it in Excel. Fraud-review rows are visible and stay pending. Manually extend a subscription (an audit record is written).
41. Blacklist: add, deactivate and edit a term and confirm the effect on a visitor message. Moderation review: blocked and flagged events and reported messages appear, with actions working.
42. Everything in the panel is readable in Turkish and English with no raw translation keys.

## What is verified, and what is not

Verified by the build (Task 22, branch `build/v1`):

- Backend suite: 706 tests passed on MariaDB 10.4 and also on MySQL 8.4.13; `migrate:fresh --seed` ran on MySQL.
- Mobile: `npx jest` (136 tests), `npx tsc --noEmit`, `npx expo export --platform android` all clean.
- A scripted journey over the real HTTP API and pages (`php artisan serve` plus `queue:work` on a scratch database): wave generation by the queue worker, claim, the visitor page (no plate or owner name), chat both ways through the visitor endpoints and the owner API, blacklist refusal, stealth making the visitor page neutral and chat refused, expiry by editing the date, the 402 hard stop, renewal through the fake gateway to paid and a year added, a duplicate renewal refused (409), transfer to a second account with the expiry kept, account deletion, and `/admin/login` with its 11 scripts and styles returning 200.

Not verified (needs you):

- **Live provider sandboxes** for iyzico and PayTR, including the webhook (checklist "Payment providers").
- **Remote push** on a real device (needs the EAS development build, FCM and APNs credentials).
- **Real-device behaviour** of the Expo app: camera scanning, in-app browser checkout, deep links, offline handling, accessibility (hand checks 1 to 36).
- **The admin UI in a real browser**: nothing was clicked (hand checks 37 to 42). Only that the login page and its assets load was confirmed.
- **The Turkish copy**: the strings, the draft privacy notice, the blacklist and the starter labelled set all await the native speaker; OpenAI moderation was never run live, so thresholds are untuned.
- Nginx, Supervisor, cron, certbot and S3 or R2 on a real server (`docs/deployment.md` has never been run).
- Legal and tax texts (all of the lawyer and accountant items).
