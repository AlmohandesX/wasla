# Beni Bul brand

"Beni bul" is Turkish for "find me". It replaces the working name WASLA for the Turkish market.

## Locked decisions (owner, 2026-11-09)

Do not change these without the owner's say-so.

- **Mark:** two lowercase b's lying on their side. The left b faces down with a long horizontal stem; the right b faces up and sits higher. A gap separates them, bridged by a thin link with a dot at each end (the connector).
- **Lockup:** "beni" top left, sitting on the left b's stem; "bul" bottom right, hanging under the right b's stem. Together they form a square.
- **Wordmark size:** 52 px in the 288 x 216 lockup (stem stroke 16 px). Fixed.
- **Font:** Manrope, weight 700. Full Turkish support (ç ğ ı İ ş ö ü), Google Fonts, SIL Open Font License.
- **Colors:** palette 3, navy + signal orange.

| Token | Hex | Use |
| --- | --- | --- |
| `navy` | `#14213D` | Letters, wordmark, main text, dark backgrounds |
| `signal` | `#FF6B2C` | The connector only in the logo; accents and calls to action in the app |
| `mist` | `#F4F5F7` | Light backgrounds, cards |
| `white` | `#FFFFFF` | Surfaces, logo on navy |

## Files

| File | Use |
| --- | --- |
| `logo/beni-bul-logo.svg` | Main logo on light backgrounds |
| `logo/beni-bul-logo-white.svg` | On navy or dark backgrounds (connector stays orange) |
| `logo/beni-bul-logo-mono.svg` | One-color print, engraving, fax-like contexts |
| `logo/beni-bul-mark.svg` | Symbol only, no words: app icon, favicon, small spaces |
| `logo/*-outlined.svg` | Same logos with the words as paths: no font needed. Send these to printers |
| `preview.html` | Open in a browser to see all versions |

The plain SVGs keep the wordmark as live Manrope text, which Figma imports with the right font. The `-outlined` files are made by `python brand/scripts/outline-logo.py` (needs fontTools) and need no font at all.

## In the app

- Tokens live in `mobile/src/theme/index.ts`. Navy is `primary` and `text`; orange is `accent`, used only as a fill behind navy content (the add-car button, unread badges, the loading spinner), because white or orange-on-white text fails contrast.
- Text uses the `font` faces (Manrope 400 to 800) instead of `fontWeight`, since Android picks a face by name.
- `Logo` (`mobile/src/components/Logo.tsx`) shows `assets/images/logo.png` or `logo-white.png`. Screens opt in with `<Screen logo>`.
- Icons, splash and in-app logo images are rendered by `node brand/scripts/render-icons.mjs`. Re-run it after any logo change.

## Visitor pages

`backend/public/css/visitor.css` and the visitor, plain and reset-password layouts: Manrope self-hosted as four subset woff2 files (about 16 KB each, Latin + Turkish, `backend/public/fonts/manrope-*.woff2`), the orange rule on top, and the outlined logo from `backend/public/img/`.

## Sticker

- 70 x 70 mm square, 4.5 mm rounded corners (approved 2026-11-09): navy band with the white logo, the QR code in the middle, "Okut, sahibine ulaş" under it. No serial on the face; the owner sees it in the app.
- Every wave package now has `stickers/<serial>.svg` next to `qr/<serial>.svg` and `tags.csv`. Each sticker is print-ready: 74 x 74 mm artboard with 2 mm bleed, all text outlined, and the die line on a separate `CutContour` layer (magenta, not printed).
- The template is `backend/resources/stickers/sticker-70.svg`, made by `brand/scripts/outline-logo.py`; `App\Services\Tags\StickerRenderer` places each QR in it. `brand/sticker/sample-70mm.svg` is a sample with a dummy link.
- Ask the printer: is the sticker stuck inside the glass and read from outside (then it is printed on the adhesive side, usually mirrored), and where does the NFC chip sit so it is not under the QR?

## Still to do

- Admin panel (Filament) colors and logo
- Native Turkish speaker to check the renamed strings ("Beni Bul'da", "Beni Bul'a") and the sticker line "Okut, sahibine ulaş"
- Brand guidelines page
