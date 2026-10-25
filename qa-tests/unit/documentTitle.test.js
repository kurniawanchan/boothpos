import { describe, it, expect } from 'vitest';
import { buildDocumentTitle } from '../../resources/js/utils/documentTitle';

// 072 — judul tab: "{toko} – {aplikasi}", dengan aturan kosong/sama di satu tempat.
describe('buildDocumentTitle', () => {
  it('menggabungkan nama toko dan nama aplikasi', () => {
    expect(buildDocumentTitle('Warkop WJM', 'BoothPOS')).toBe('Warkop WJM – BoothPOS');
  });

  it('nama toko kosong atau hanya spasi: hanya nama aplikasi', () => {
    expect(buildDocumentTitle('', 'BoothPOS')).toBe('BoothPOS');
    expect(buildDocumentTitle('   ', 'BoothPOS')).toBe('BoothPOS');
    expect(buildDocumentTitle(null, 'BoothPOS')).toBe('BoothPOS');
  });

  it('nama toko sama dengan nama aplikasi (abaikan huruf besar/kecil dan spasi tepi): sekali saja', () => {
    expect(buildDocumentTitle('Warkop WJM', 'Warkop WJM')).toBe('Warkop WJM');
    expect(buildDocumentTitle(' warkop wjm ', 'Warkop WJM')).toBe('Warkop WJM');
  });

  it('nama aplikasi kosong: memakai nama aplikasi bawaan', () => {
    expect(buildDocumentTitle('Warkop WJM', '')).toBe('Warkop WJM – BoothPOS');
    expect(buildDocumentTitle('', undefined)).toBe('BoothPOS');
  });
});
