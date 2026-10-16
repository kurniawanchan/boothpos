import { describe, it, expect } from 'vitest';
import { previewSegment, deriveSegment } from '../../resources/js/utils/productSegment';

// Spec 042 (US1, US3): pratinjau segmen. Typed = tanpa pad; blank = turunan 3 huruf.
describe('product segment preview (042)', () => {
  it('shows a typed one-letter segment without padding', () => {
    expect(previewSegment('S', 'Conan')).toBe('S');
  });

  it('keeps a typed two or three letter segment as typed', () => {
    expect(previewSegment('SH', 'Conan')).toBe('SH');
    expect(previewSegment('SPF', 'Conan')).toBe('SPF');
  });

  it('uppercases and trims a typed segment and caps it at three letters', () => {
    expect(previewSegment(' spf ', 'x')).toBe('SPF');
    expect(previewSegment('abcd', 'x')).toBe('ABC');
  });

  it('derives three letters from the name when the segment is blank', () => {
    expect(previewSegment('', 'Conan')).toBe('CON');
    expect(previewSegment('   ', 'Conan')).toBe('CON');
    expect(previewSegment(null, 'Conan')).toBe('CON');
  });

  it('pads a derived segment with X when the name is short', () => {
    expect(previewSegment('', 'Ai')).toBe('AIX');
    expect(deriveSegment('')).toBe('XXX');
  });

  it('derives only letters from the name', () => {
    expect(previewSegment('', 'Sakura 2 (Edisi)')).toBe('SAK');
  });
});
