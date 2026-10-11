import { readFileSync } from 'node:fs';
import { resolve } from 'node:path';
import { describe, expect, it } from 'vitest';

/**
 * 041-back-office-ui-redesign (SC-011) — pasangan warna teks/latar pada lembar token HARUS memenuhi
 * WCAG 2.1 AA (4,5:1 untuk teks biasa). Membaca resources/css/app.css apa adanya, jadi mengubah token
 * tanpa memeriksa kontras akan menggagalkan tes ini.
 */
const css = readFileSync(resolve(__dirname, '../../resources/css/app.css'), 'utf8');
const theme = css.slice(css.indexOf('@theme {'), css.indexOf('\n}\n', css.indexOf('@theme {')));

function token(name) {
  const m = theme.match(new RegExp(`--color-${name}:\\s*(#[0-9a-fA-F]{6})\\s*;`));
  if (!m) throw new Error(`token --color-${name} tidak ditemukan di app.css`);
  return m[1];
}

function luminance(hex) {
  const n = parseInt(hex.slice(1), 16);
  const lin = (c) => {
    const v = c / 255;
    return v <= 0.03928 ? v / 12.92 : ((v + 0.055) / 1.055) ** 2.4;
  };
  return 0.2126 * lin((n >> 16) & 255) + 0.7152 * lin((n >> 8) & 255) + 0.0722 * lin(n & 255);
}

function ratio(a, b) {
  const [hi, lo] = [luminance(a), luminance(b)].sort((x, y) => y - x);
  return (hi + 0.05) / (lo + 0.05);
}

const WHITE = '#ffffff';

// [teks, latar, keterangan] — hanya pasangan yang benar-benar dipakai sebagai teks.
const PAIRS = [
  [WHITE, 'brand', 'teks putih pada tombol aksen'],
  [WHITE, 'brand-hover', 'teks putih pada tombol aksen (hover)'],
  [WHITE, 'brand-active', 'teks putih pada tombol aksen (aktif)'],
  ['brand', 'surface-soft', 'tautan/aksen pada kartu putih'],
  ['ink', 'page', 'teks utama pada latar halaman'],
  ['ink', 'surface-soft', 'teks utama pada kartu'],
  ['ink', 'mint-50', 'teks utama pada bagian terpilih'],
  ['muted', 'surface-soft', 'teks redup pada kartu'],
  ['muted-4', 'page', 'teks redup tua pada latar halaman'],
  ['rail-text', 'rail', 'ikon/teks rel navigasi'],
  [WHITE, 'rail-active', 'teks item rel aktif'],
  ['success-text', 'success-bg', 'chip keberhasilan'],
  ['warn-text', 'warn-bg', 'chip peringatan'],
  ['danger-text', 'danger-bg', 'chip bahaya'],
  ['sky-text', 'sky-bg', 'chip info'],
  ['violet-text', 'violet-bg', 'chip violet'],
];

describe('kontras lembar token (WCAG 2.1 AA)', () => {
  it.each(PAIRS)('%s di atas %s ≥ 4,5:1 (%s)', (fg, bg) => {
    const f = fg.startsWith('#') ? fg : token(fg);
    const b = bg.startsWith('#') ? bg : token(bg);
    expect(ratio(f, b)).toBeGreaterThanOrEqual(4.5);
  });

  it('menyatakan semua radius sebagai 0 (desain bersudut siku)', () => {
    for (const name of ['xs', 'sm', 'md', 'lg', 'card', 'modal']) {
      expect(theme).toMatch(new RegExp(`--radius-${name}:\\s*0px;`));
    }
  });
});
