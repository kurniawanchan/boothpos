import { readFileSync } from 'node:fs';
import { resolve } from 'node:path';
import { describe, expect, it } from 'vitest';
import id from '../../resources/js/locales/id.json';
import en from '../../resources/js/locales/en.json';

/**
 * 041-back-office-ui-redesign (US1) — jejak halaman dan judul bawaan kerangka memakai
 * `route.meta.titleKey ?? nav.<nama rute>`. Rute tanpa kunci terjemahan yang valid akan menampilkan kunci
 * mentah ("nav.floor-tab") di topbar — dicegah di sini dengan membaca daftar rute sebagai teks (tanpa
 * mengimpor router asli, yang menarik semua view).
 */
const source = readFileSync(resolve(__dirname, '../../resources/js/router/index.js'), 'utf8');

// Setiap objek rute yang punya `name` dan berada di dalam kerangka (punya meta.menuKey atau tanpa meta publik).
const routes = [...source.matchAll(/\{\s*path:\s*'([^']*)'\s*,\s*name:\s*'([^']+)'([\s\S]*?)\n\s{6,8}\}/g)].map((m) => ({
  path: m[1],
  name: m[2],
  body: m[3],
}));

const PUBLIC = new Set(['login', 'activate']);
const lookup = (dict, key) => key.split('.').reduce((o, k) => (o && typeof o === 'object' ? o[k] : undefined), dict);

describe('judul rute untuk jejak halaman', () => {
  it('menemukan rute dari berkas router', () => {
    expect(routes.length).toBeGreaterThan(20);
  });

  it.each(routes.filter((r) => !PUBLIC.has(r.name)).map((r) => [r.name, r.body]))('rute "%s" punya judul di kedua bahasa', (name, body) => {
    const titleKey = body.match(/titleKey:\s*'([^']+)'/)?.[1] ?? `nav.${name}`;
    expect(typeof lookup(id, titleKey), `id: ${titleKey}`).toBe('string');
    expect(typeof lookup(en, titleKey), `en: ${titleKey}`).toBe('string');
  });
});
