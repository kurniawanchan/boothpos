import { describe, it, expect } from 'vitest';
import { splitSku, maxSequenceLength, compareVariantSku } from '../../resources/js/utils/productSegment';

// Spec 045 (US1/US2): kode tetap + nomor urut yang diketik.
describe('variant SKU split and sequence limit (045)', () => {
  it('splits a SKU into its fixed code and its typed number', () => {
    expect(splitSku('SPF-PI-S-7')).toEqual({ code: 'SPF-PI-S', number: '7' });
    expect(splitSku('SPF-PI-S-MUG')).toEqual({ code: 'SPF-PI-S', number: 'MUG' });
  });

  it('gives no typed number for a legacy SKU without a hyphen', () => {
    expect(splitSku('RYUKYSAK0001')).toEqual({ code: '', number: '' });
    expect(splitSku(null)).toEqual({ code: '', number: '' });
  });

  it('limits the number so the whole SKU stays within 20 characters (spec 046)', () => {
    expect(maxSequenceLength('SPF-PI-S')).toBe(11);
    expect(maxSequenceLength('SPF-PI-SPF')).toBe(9);
    expect(maxSequenceLength('AAAAAAAAAAAAAA')).toBe(5);
  });
});

// Spec 045 US4 (FR-012): urutan SKU di formulir — angka sebagai bilangan bulat.
describe('variant SKU order (045 US4)', () => {
  const sorted = (skus) => [...skus].sort(compareVariantSku);

  it('orders numbers as whole numbers, so 2 comes before 10 and 11 after 9', () => {
    expect(sorted(['ART-KA-S-10', 'ART-KA-S-2', 'ART-KA-S-1', 'ART-KA-S-11', 'ART-KA-S-9']))
      .toEqual(['ART-KA-S-1', 'ART-KA-S-2', 'ART-KA-S-9', 'ART-KA-S-10', 'ART-KA-S-11']);
  });

  it('keeps different codes apart, ordered by code first', () => {
    expect(sorted(['ART-KA-T-1', 'ART-KA-S-2'])).toEqual(['ART-KA-S-2', 'ART-KA-T-1']);
  });

  it('puts a legacy SKU without a number after the numbered SKUs of the same code', () => {
    expect(sorted(['RYUKYSAK0001', 'RYUKYSAK-1'])).toEqual(['RYUKYSAK-1', 'RYUKYSAK0001']);
  });
});
