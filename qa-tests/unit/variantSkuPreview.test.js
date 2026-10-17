import { describe, it, expect } from 'vitest';
import { previewSku } from '../../resources/js/utils/productSegment';

// Spec 044 (US1): pratinjau SKU pertama memakai nomor 1 tanpa nol di depan.
describe('variant SKU preview (044)', () => {
  it('shows the first variant number without leading zeros', () => {
    expect(previewSku('SPF', 'SH', 'S')).toBe('SPF-SH-S-1');
    expect(previewSku('SPF', 'SH', 'SPF')).toBe('SPF-SH-SPF-1');
  });
});
