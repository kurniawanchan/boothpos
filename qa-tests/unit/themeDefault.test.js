import { readFileSync } from 'node:fs';
import { resolve } from 'node:path';
import { describe, expect, it } from 'vitest';
import { DEFAULT_ACCENT_COLOR } from '../../resources/js/utils/theme';

/**
 * 041 — warna tema bawaan di JS harus sama dengan token `--color-brand` di app.css. Kalau berbeda, pemilih
 * warna tema menawarkan nilai awal yang bukan aksen produk, dan menyimpannya mengganti aksen tanpa disadari.
 */
describe('warna aksen bawaan', () => {
  it('DEFAULT_ACCENT_COLOR sama dengan --color-brand di app.css', () => {
    const css = readFileSync(resolve(__dirname, '../../resources/css/app.css'), 'utf8');
    const brand = css.match(/--color-brand:\s*(#[0-9a-fA-F]{6})\s*;/)?.[1];
    expect(brand).toBeTruthy();
    expect(DEFAULT_ACCENT_COLOR.toLowerCase()).toBe(brand.toLowerCase());
  });
});
