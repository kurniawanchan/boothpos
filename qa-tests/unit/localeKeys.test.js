import { describe, it, expect } from 'vitest';
import { readFileSync, readdirSync, statSync } from 'node:fs';
import { join } from 'node:path';

/**
 * 036 — setiap kunci terjemahan LITERAL yang dipanggil lewat t('a.b') harus
 * ada di en.json DAN id.json. Kunci yang hilang tidak melempar galat: vue-i18n
 * menampilkan jalur kuncinya (mis. MASTER_DATA.COL_TYPE di header kolom),
 * jadi cacatnya lolos dari semua tes fungsional. Kunci dinamis (template
 * string) sengaja diabaikan — tidak bisa diperiksa secara statis.
 */
const ROOT = join(__dirname, '../../resources/js');
const en = JSON.parse(readFileSync(join(ROOT, 'locales/en.json'), 'utf8'));
const id = JSON.parse(readFileSync(join(ROOT, 'locales/id.json'), 'utf8'));

function walk(dir, out = []) {
  for (const name of readdirSync(dir)) {
    const full = join(dir, name);
    if (statSync(full).isDirectory()) {
      if (name !== 'locales') walk(full, out);
    } else if (/\.(vue|js)$/.test(name)) {
      out.push(full);
    }
  }
  return out;
}

const has = (tree, key) => key.split('.').reduce((node, part) => (node && typeof node === 'object' && part in node ? node[part] : undefined), tree) !== undefined;

describe('literal translation keys (036)', () => {
  it('exist in both en.json and id.json', () => {
    const KEY = /\bt\(\s*['"]([a-z0-9_]+(?:\.[a-z0-9_]+)+)['"]/g;
    const missing = [];

    for (const file of walk(ROOT)) {
      const source = readFileSync(file, 'utf8');
      for (const [, key] of source.matchAll(KEY)) {
        if (!has(en, key)) missing.push(`${key} (en) in ${file.replace(ROOT, '')}`);
        if (!has(id, key)) missing.push(`${key} (id) in ${file.replace(ROOT, '')}`);
      }
    }

    expect([...new Set(missing)]).toEqual([]);
  });
});
