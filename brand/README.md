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
| `preview.html` | Open in a browser to see all versions |

The wordmark is live text in Manrope. Figma has Manrope built in, so the SVGs import with the right font. Before sending a file to a printer, outline the text in Figma (select the text, then Flatten, Ctrl+E) so it does not depend on the font being installed.

## Still to do

- App icon and splash screen from the mark
- Sticker layout: logo next to the QR code at real size
- Brand guidelines page and the app's theme tokens (`mobile/src/theme/index.ts`)
