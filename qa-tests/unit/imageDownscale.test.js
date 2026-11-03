import { describe, it, expect } from 'vitest';
import { MAX_UPLOAD_BYTES, prepareProofImage, scaledSize } from '../../resources/js/utils/imageDownscale';

const file = (name, type, size) => {
  const f = new File(['x'], name, { type });
  Object.defineProperty(f, 'size', { value: size });
  return f;
};

describe('scaledSize', () => {
  it('shrinks the longest side to the limit keeping the ratio, and never enlarges', () => {
    expect(scaledSize(4000, 3000)).toEqual({ width: 1600, height: 1200 });
    expect(scaledSize(3000, 4000)).toEqual({ width: 1200, height: 1600 });
    expect(scaledSize(800, 600)).toEqual({ width: 800, height: 600 });
  });
});

describe('prepareProofImage', () => {
  it('returns small JPEG and PNG files untouched', async () => {
    const jpg = file('a.jpg', 'image/jpeg', 200_000);
    const png = file('a.png', 'image/png', MAX_UPLOAD_BYTES);

    expect(await prepareProofImage(jpg)).toBe(jpg);
    expect(await prepareProofImage(png)).toBe(png);
  });

  it('rejects with "unsupported" when a big or HEIC photo cannot be converted', async () => {
    // jsdom tidak punya kanvas: jalur konversi gagal dan harus dilaporkan bersih, bukan melempar galat mentah.
    await expect(prepareProofImage(file('a.heic', 'image/heic', 3_000_000))).rejects.toThrow('unsupported');
    await expect(prepareProofImage(file('big.jpg', 'image/jpeg', MAX_UPLOAD_BYTES + 1))).rejects.toThrow('unsupported');
  });
});
