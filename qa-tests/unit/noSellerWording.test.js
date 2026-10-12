import { describe, it, expect } from 'vitest';
import en from '../../resources/js/locales/en.json';
import id from '../../resources/js/locales/id.json';

/**
 * 045 Stage 1 — penjual/seller/artis/artist adalah nama lama entitas yang kini
 * bernama outlet. Tes ini menjaga agar NILAI terjemahan tidak kembali memakai
 * istilah lama. Kunci tidak diperiksa (kunci sengaja tetap sama selama rename).
 *
 * Placeholder seperti {artist} dikecualikan: itu nama parameter interpolasi
 * yang dikirim komponen, bukan teks yang dilihat pengguna.
 *
 * ALLOW kosong sengaja. Tambahkan { lang, key, reason } hanya untuk nilai yang
 * memang membahas hal lain (mis. konsep "artist" historis), dengan alasan tertulis.
 */
const WORDING = /\b(seller|sellers|penjual|artis|artist|artists)\b/i;
const PLACEHOLDER = /\{[^{}]*\}/g;
const ALLOW = [];

function flatten(tree, prefix = '', out = {}) {
  for (const [k, v] of Object.entries(tree)) {
    const key = prefix + k;
    if (v && typeof v === 'object') flatten(v, key + '.', out);
    else out[key] = v;
  }
  return out;
}

describe('no seller/artist wording in locale values (045)', () => {
  for (const [lang, tree] of [['en', en], ['id', id]]) {
    it(`${lang}: no value uses seller/penjual/artist/artis wording`, () => {
      const offenders = Object.entries(flatten(tree))
        .filter(([key, value]) => {
          if (typeof value !== 'string') return false;
          if (ALLOW.some((a) => a.lang === lang && a.key === key)) return false;
          return WORDING.test(value.replace(PLACEHOLDER, ''));
        })
        .map(([key, value]) => `${lang}:${key} => ${value}`);

      expect(offenders, 'offending locale keys (value must say outlet)').toEqual([]);
    });
  }
});
