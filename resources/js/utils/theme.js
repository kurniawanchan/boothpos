/**
 * 006-purchase-order-and-ops (US6) — applies a custom accent color at
 * RUNTIME by setting the same CSS custom properties `@theme` already
 * declares in resources/css/app.css (`--color-brand`, `--color-brand-hover`,
 * `--color-brand-active`) on the document root. Every Tailwind utility
 * generated from those tokens (bg-brand, text-brand-active, ...) already
 * reads via var(...), so no component needs to change — see research.md
 * R1. This is the ONLY place outside app.css allowed to touch a raw hex
 * value (Constitution Principle III's single-token-sheet rule).
 */
function clamp(n) {
  return Math.max(0, Math.min(255, n));
}

function darken(hex, amount) {
  const num = parseInt(hex.slice(1), 16);
  const r = clamp(((num >> 16) & 0xff) - amount);
  const g = clamp(((num >> 8) & 0xff) - amount);
  const b = clamp((num & 0xff) - amount);
  return `#${((1 << 24) + (r << 16) + (g << 8) + b).toString(16).slice(1)}`;
}

export function applyThemeAccentColor(hex) {
  if (!hex || !/^#[0-9a-fA-F]{6}$/.test(hex)) return;
  const root = document.documentElement.style;
  root.setProperty('--color-brand', hex);
  root.setProperty('--color-brand-hover', darken(hex, 20));
  root.setProperty('--color-brand-active', darken(hex, 40));
}

/** Basic legibility guard — rejects a pick too close to white to read against this app's white backgrounds. */
export function isColorTooLight(hex) {
  if (!/^#[0-9a-fA-F]{6}$/.test(hex)) return false;
  const num = parseInt(hex.slice(1), 16);
  const r = (num >> 16) & 0xff;
  const g = (num >> 8) & 0xff;
  const b = num & 0xff;
  // Perceived luminance (ITU-R BT.601) — above ~235 is nearly white.
  const luminance = 0.299 * r + 0.587 * g + 0.114 * b;
  return luminance > 235;
}
