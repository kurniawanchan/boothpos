import { describe, expect, it } from 'vitest';
import { formatCount, formatDateLong, formatIDR, formatTimeShort, pageRange } from '../../resources/js/utils/formatters';

describe('formatters', () => {
  it('memformat rupiah dengan titik pemisah ribuan seperti desain', () => {
    expect(formatIDR('18420500.00')).toBe('Rp 18.420.500');
    expect(formatIDR(37902)).toBe('Rp 37.902');
  });

  it('memformat tanggal panjang bahasa Indonesia', () => {
    const out = formatDateLong('2026-10-04T10:00:00');
    expect(out).toContain('Minggu');
    expect(out).toContain('4');
    expect(out).toContain('2026');
    expect(formatDateLong(null)).toBe('—');
    expect(formatDateLong('bukan tanggal')).toBe('—');
  });

  it('memformat jam dengan titik', () => {
    expect(formatTimeShort('2026-10-04T19:30:00')).toBe('19.30');
    expect(formatTimeShort('')).toBe('—');
  });

  it('memformat bilangan dengan titik ribuan', () => {
    expect(formatCount(1284)).toBe('1.284');
    expect(formatCount('x')).toBe('—');
  });
});

describe('pageRange', () => {
  it('menghitung rentang halaman penuh', () => {
    expect(pageRange({ current_page: 2, per_page: 25, total: 90 })).toEqual({ from: 26, to: 50, total: 90 });
  });
  it('memotong halaman terakhir pada total', () => {
    expect(pageRange({ current_page: 4, per_page: 25, total: 90 })).toEqual({ from: 76, to: 90, total: 90 });
  });
  it('tidak pernah menghasilkan "1–0" untuk data kosong', () => {
    expect(pageRange({ current_page: 1, per_page: 25, total: 0 })).toEqual({ from: 0, to: 0, total: 0 });
    expect(pageRange(undefined)).toEqual({ from: 0, to: 0, total: 0 });
  });
  it('menjepit halaman di luar jangkauan', () => {
    expect(pageRange({ current_page: 99, per_page: 25, total: 10 })).toEqual({ from: 10, to: 10, total: 10 });
  });
});
