// @vitest-environment node
import { describe, it, expect } from 'vitest';
import { readFileSync } from 'node:fs';
import { join, relative, resolve, sep } from 'node:path';
import { compile } from '@tailwindcss/node';
import { Scanner } from '@tailwindcss/oxide';

/**
 * BUG YANG DITEMUKAN & DIPERBAIKI — http://localhost:8010/ lama sekali dimuat
 * lalu tetap kosong walau API sukses. resources/css/app.css memakai
 * `@import 'tailwindcss'` TANPA sumber eksplisit, jadi Tailwind v4 memindai
 * SELURUH repo (android-pos/, specs/, docs/, tests/, CLAUDE.md …). Lewat
 * bind mount Docker satu pindaian ±38 dtk; di dev, request app.css menggantung
 * (>120 dtk, #app tidak pernah ter-mount) dan setiap perubahan berkas apa pun
 * di repo memicu "page reload". Kelas Tailwind hanya dipakai di resources/js,
 * jadi pemindaian dibatasi ke sana. Tes ini memakai compiler Tailwind sungguhan
 * agar sumber yang dipindai tidak bisa melebar lagi tanpa ketahuan.
 */
const PROJECT = resolve(__dirname, '../..');
const CSS_DIR = join(PROJECT, 'resources/css');
const ALLOWED = join(PROJECT, 'resources/js');

async function compiledSources() {
  const css = readFileSync(join(CSS_DIR, 'app.css'), 'utf8');
  const compiler = await compile(css, { base: CSS_DIR, onDependency: () => {} });
  return compiler;
}

const inside = (dir) => dir === ALLOWED || dir.startsWith(ALLOWED + sep);

describe('regresi: halaman :8010 lama dimuat lalu kosong karena Tailwind memindai seluruh repo (app.css)', () => {
  it('tidak memakai deteksi otomatis dari akar proyek', async () => {
    const { root } = await compiledSources();
    // root null = deteksi otomatis dari base → memindai seluruh repo.
    expect(root).not.toBeNull();
    expect(root).not.toBe('none');
    // source('../js') dikembalikan sebagai { base: <dir app.css>, pattern: '../js' }.
    expect(relative(PROJECT, resolve(root.base, root.pattern))).toBe(join('resources', 'js'));
  });

  it('setiap @source tambahan tetap di dalam resources/js', async () => {
    const { sources } = await compiledSources();
    for (const s of sources) {
      expect(inside(resolve(s.base)), `@source di luar resources/js: ${s.base}`).toBe(true);
    }
  });

  it('kelas token dari komponen tetap dihasilkan dari sumber terbatas itu', async () => {
    const compiler = await compiledSources();
    const scanner = new Scanner({
      sources: [{ base: compiler.root.base, pattern: compiler.root.pattern, negated: false }],
    });
    const out = compiler.build(scanner.scan());
    // bg-brand dipakai di banyak komponen (BaseButton dll.).
    expect(out).toContain('.bg-brand');
  });
});
