// Renders the Beni Bul app icon, Android adaptive icon layers, splash image and favicon
// into mobile/assets/images, using headless Chrome or Edge and the Manrope font from
// mobile/node_modules. Run from the repo root: node brand/scripts/render-icons.mjs
import { execFileSync } from 'node:child_process';
import { existsSync, mkdtempSync, writeFileSync } from 'node:fs';
import { tmpdir } from 'node:os';
import { join, resolve } from 'node:path';
import { pathToFileURL } from 'node:url';

const root = resolve(import.meta.dirname, '..', '..');
const out = join(root, 'mobile', 'assets', 'images');
const font = join(root, 'mobile', 'node_modules', '@expo-google-fonts', 'manrope', '700Bold', 'Manrope_700Bold.ttf');
if (!existsSync(font)) throw new Error(`Manrope not found at ${font}. Run npm install in mobile/ first.`);

const browser = [
  'C:\\Program Files\\Google\\Chrome\\Application\\chrome.exe',
  'C:\\Program Files (x86)\\Microsoft\\Edge\\Application\\msedge.exe',
  'C:\\Program Files\\Microsoft\\Edge\\Application\\msedge.exe',
].find(existsSync);
if (!browser) throw new Error('Chrome or Edge is required.');

const NAVY = '#14213D';
const SIGNAL = '#FF6B2C';
const WHITE = '#FFFFFF';

// The locked lockup (brand/README.md). Content center is (140, 90).
function lockup({ ink, accent, words = true }) {
  return `
  <g fill="none" stroke="${ink}" stroke-width="16" stroke-linecap="round">
    <line x1="14" y1="120" x2="110" y2="120"/><circle cx="44" cy="150" r="30"/>
    <line x1="170" y1="60" x2="266" y2="60"/><circle cx="236" cy="30" r="30"/>
  </g>
  <line x1="127" y1="108" x2="153" y2="72" stroke="${accent}" stroke-width="5" stroke-linecap="round"/>
  <g fill="${accent}"><circle cx="127" cy="108" r="7"/><circle cx="153" cy="72" r="7"/></g>
  ${words ? `<g fill="${ink}" font-family="Manrope" font-weight="700" font-size="52">
    <text x="12" y="100">beni</text><text x="268" y="120" text-anchor="end">bul</text>
  </g>` : ''}`;
}

// Places the lockup so its content center sits at (cx, cy) at the given scale.
function placed(size, scale, cx, cy, opts, background) {
  const tx = cx - 140 * scale;
  const ty = cy - 90 * scale;
  return `<svg xmlns="http://www.w3.org/2000/svg" width="${size}" height="${size}" viewBox="0 0 ${size} ${size}">
    ${background ? `<rect width="${size}" height="${size}" fill="${background}"/>` : ''}
    <g transform="translate(${tx} ${ty}) scale(${scale})">${lockup(opts)}</g>
  </svg>`;
}

const assets = [
  // iOS / store icon: full bleed navy, logo at 86% of the width (approved in chat 2026-11-09).
  { file: 'icon.png', w: 1024, h: 1024, svg: placed(1024, 3.3, 512, 512, { ink: WHITE, accent: SIGNAL }, NAVY) },
  // Android adaptive layers. The launcher can crop to a circle of 66% of the canvas,
  // so the foreground logo is sized to fit inside it.
  { file: 'android-icon-background.png', w: 1024, h: 1024, svg: `<svg xmlns="http://www.w3.org/2000/svg" width="1024" height="1024"><rect width="1024" height="1024" fill="${NAVY}"/></svg>` },
  { file: 'android-icon-foreground.png', w: 1024, h: 1024, svg: placed(1024, 1.95, 512, 512, { ink: WHITE, accent: SIGNAL }) },
  { file: 'android-icon-monochrome.png', w: 1024, h: 1024, svg: placed(1024, 1.95, 512, 512, { ink: WHITE, accent: WHITE }) },
  // Splash: white logo on the navy splash background, transparent around it.
  { file: 'splash-icon.png', w: 1200, h: 900, svg: `<svg xmlns="http://www.w3.org/2000/svg" width="1200" height="900" viewBox="-4 -18 288 216">${lockup({ ink: WHITE, accent: SIGNAL })}</svg>` },
  // In-app logos at 3x of the 288 x 216 lockup, transparent.
  { file: 'logo.png', w: 864, h: 648, svg: `<svg xmlns="http://www.w3.org/2000/svg" width="864" height="648" viewBox="-4 -18 288 216">${lockup({ ink: NAVY, accent: SIGNAL })}</svg>` },
  { file: 'logo-white.png', w: 864, h: 648, svg: `<svg xmlns="http://www.w3.org/2000/svg" width="864" height="648" viewBox="-4 -18 288 216">${lockup({ ink: WHITE, accent: SIGNAL })}</svg>` },
  { file: 'favicon.png', w: 48, h: 48, svg: placed(48, 3.3 * 48 / 1024, 24, 24, { ink: WHITE, accent: SIGNAL }, NAVY) },
];

const work = mkdtempSync(join(tmpdir(), 'benibul-'));
for (const a of assets) {
  const html = `<!doctype html><html><head><style>
    @font-face { font-family: Manrope; font-weight: 700; src: url('${pathToFileURL(font).href}'); }
    html, body { margin: 0; background: transparent; overflow: hidden; }
    svg { display: block; width: ${a.w}px; height: ${a.h}px; }
  </style></head><body>${a.svg}</body></html>`;
  const page = join(work, a.file.replace('.png', '.html'));
  writeFileSync(page, html);
  execFileSync(browser, [
    '--headless=new', '--disable-gpu', '--hide-scrollbars', '--force-device-scale-factor=1',
    '--default-background-color=00000000', '--virtual-time-budget=5000',
    `--window-size=${a.w},${a.h}`, `--screenshot=${join(out, a.file)}`, pathToFileURL(page).href,
  ], { stdio: 'ignore' });
  console.log(`wrote ${a.file} (${a.w}x${a.h})`);
}
