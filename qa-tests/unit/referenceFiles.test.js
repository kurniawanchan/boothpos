import { describe, it, expect } from 'vitest';
import { triageReferenceFiles, REFERENCE_IMAGE_MAX_BYTES } from '../../resources/js/utils/referenceFiles';

const file = (name, type = 'image/png', size = 10) => ({ name, type, size });

// 055 (US2) — berkas valid tetap diterima meski ada berkas lain yang ditolak.
describe('triageReferenceFiles', () => {
  it('accepts valid images in selection order', () => {
    const files = [file('a.png'), file('b.jpg', 'image/jpeg'), file('c.webp', 'image/webp')];
    expect(triageReferenceFiles(files, 10)).toEqual({ accepted: files, refused: [] });
  });

  it('refuses wrong types and oversized files with a reason, keeping the valid ones', () => {
    const ok = file('ok.png');
    const result = triageReferenceFiles([file('dok.pdf', 'application/pdf'), ok, file('big.png', 'image/png', REFERENCE_IMAGE_MAX_BYTES + 1)], 10);

    expect(result.accepted).toEqual([ok]);
    expect(result.refused).toEqual([{ name: 'dok.pdf', reason: 'type' }, { name: 'big.png', reason: 'size' }]);
  });

  it('accepts a file exactly at the size limit', () => {
    expect(triageReferenceFiles([file('max.png', 'image/png', REFERENCE_IMAGE_MAX_BYTES)], 1).accepted).toHaveLength(1);
  });

  it('fills the remaining slots in order and refuses the rest for the limit', () => {
    const files = [file('1.png'), file('bad.gif', 'image/gif'), file('2.png'), file('3.png')];
    const result = triageReferenceFiles(files, 2);

    expect(result.accepted.map((f) => f.name)).toEqual(['1.png', '2.png']);
    expect(result.refused).toEqual([{ name: 'bad.gif', reason: 'type' }, { name: '3.png', reason: 'limit' }]);
  });

  it('with no slot left refuses every valid file for the limit (never negative slots)', () => {
    expect(triageReferenceFiles([file('a.png')], 0).refused).toEqual([{ name: 'a.png', reason: 'limit' }]);
    expect(triageReferenceFiles([file('a.png')], -3).accepted).toEqual([]);
  });
});
