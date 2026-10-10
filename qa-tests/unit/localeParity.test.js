import { describe, it, expect } from 'vitest';
import { readFileSync } from 'node:fs';
import { resolve } from 'node:path';

// Baca teks MENTAH — JSON.parse diam-diam membuang kunci kembar (yang
// terakhir menang), padahal itu persis jebakan yang tercatat di CLAUDE.md
// (kunci kembar dalam satu seksi menimpa kunci sebelumnya tanpa error).
function loadRaw(lang) {
  return readFileSync(resolve(__dirname, `../../resources/js/locales/${lang}.json`), 'utf8');
}

// Pemindai JSON minimal: mendaftarkan setiap kunci per objek dan
// melaporkan kunci yang muncul dua kali dalam objek yang sama.
export function findDuplicateKeys(text) {
  const duplicates = [];
  const stack = []; // tiap elemen: { keys: Set, path: string, type: 'object'|'array' }
  let i = 0;
  let pendingKey = null;
  let expectKey = false;

  const readString = () => {
    let out = '';
    i += 1; // lewati tanda kutip pembuka
    while (i < text.length && text[i] !== '"') {
      if (text[i] === '\\') { out += text[i] + text[i + 1]; i += 2; } else { out += text[i]; i += 1; }
    }
    i += 1; // lewati tanda kutip penutup
    return out;
  };

  while (i < text.length) {
    const ch = text[i];
    if (ch === '{') {
      const parent = stack[stack.length - 1];
      const path = parent ? `${parent.path}${pendingKey !== null ? `${parent.path ? '.' : ''}${pendingKey}` : ''}` : '';
      stack.push({ keys: new Set(), path, type: 'object' });
      expectKey = true; pendingKey = null; i += 1;
    } else if (ch === '[') {
      stack.push({ keys: new Set(), path: stack.length ? stack[stack.length - 1].path : '', type: 'array' });
      expectKey = false; pendingKey = null; i += 1;
    } else if (ch === '}' || ch === ']') {
      stack.pop();
      expectKey = false; pendingKey = null; i += 1;
    } else if (ch === '"') {
      const str = readString();
      const top = stack[stack.length - 1];
      if (top && top.type === 'object' && expectKey) {
        if (top.keys.has(str)) duplicates.push(`${top.path ? `${top.path}.` : ''}${str}`);
        top.keys.add(str);
        pendingKey = str;
        expectKey = false;
      }
    } else if (ch === ',') {
      const top = stack[stack.length - 1];
      expectKey = Boolean(top && top.type === 'object');
      i += 1;
    } else {
      i += 1;
    }
  }
  return duplicates;
}

function flatten(obj, prefix = '') {
  return Object.entries(obj).flatMap(([k, v]) =>
    v && typeof v === 'object' && !Array.isArray(v) ? flatten(v, `${prefix}${k}.`) : [`${prefix}${k}`]);
}

describe('locale files', () => {
  it('detects a repeated key inside one object (scanner self-check)', () => {
    expect(findDuplicateKeys('{"a":{"x":1,"x":2},"b":1}')).toEqual(['a.x']);
    expect(findDuplicateKeys('{"a":{"x":1},"b":{"x":2}}')).toEqual([]);
  });

  it.each(['en', 'id'])('%s.json has no duplicated keys within any object', (lang) => {
    expect(findDuplicateKeys(loadRaw(lang))).toEqual([]);
  });

  it('en.json and id.json define exactly the same keys', () => {
    const en = new Set(flatten(JSON.parse(loadRaw('en'))));
    const id = new Set(flatten(JSON.parse(loadRaw('id'))));
    expect([...en].filter((k) => !id.has(k))).toEqual([]);
    expect([...id].filter((k) => !en.has(k))).toEqual([]);
  });
});
