import { describe, it, expect, vi, beforeEach, afterEach } from 'vitest';
import { logoToFavicon } from '../../resources/js/utils/logoFavicon';

// 076 (US4) — logo → ikon tab. Image & canvas dipalsukan (jsdom tidak memuat
// gambar atau menggambar): yang diuji adalah kontrak hasil dan fit "contain".
function stubImage({ fires, width = 200, height = 100 }) {
  class FakeImage {
    set src(value) {
      this._src = value;
      this.width = width;
      this.height = height;
      queueMicrotask(() => (fires === 'load' ? this.onload?.() : this.onerror?.()));
    }
  }
  vi.stubGlobal('Image', FakeImage);
}

function stubCanvas({ tainted = false } = {}) {
  const drawImage = vi.fn();
  vi.spyOn(HTMLCanvasElement.prototype, 'getContext').mockReturnValue({ drawImage });
  vi.spyOn(HTMLCanvasElement.prototype, 'toDataURL').mockImplementation(() => {
    if (tainted) throw new DOMException('tainted', 'SecurityError');
    return 'data:image/png;base64,SQUARE';
  });
  return drawImage;
}

describe('logoToFavicon', () => {
  beforeEach(() => vi.restoreAllMocks());
  afterEach(() => vi.unstubAllGlobals());

  it('resolves null without a URL', async () => {
    await expect(logoToFavicon(null)).resolves.toBeNull();
    await expect(logoToFavicon('')).resolves.toBeNull();
  });

  it('draws the logo contained inside a 64×64 square and returns the PNG data URL', async () => {
    stubImage({ fires: 'load', width: 200, height: 100 });
    const drawImage = stubCanvas();

    await expect(logoToFavicon('/storage/logo.png')).resolves.toBe('data:image/png;base64,SQUARE');

    // 200×100 dalam 64×64 → 64×32, terpusat vertikal (offset 16): tidak tergencet.
    const [, x, y, w, h] = drawImage.mock.calls[0];
    expect([x, y, w, h]).toEqual([0, 16, 64, 32]);
  });

  it('falls back to the original URL when the canvas is tainted (cross-origin host)', async () => {
    stubImage({ fires: 'load' });
    stubCanvas({ tainted: true });

    await expect(logoToFavicon('http://10.0.0.5/storage/logo.png')).resolves.toBe('http://10.0.0.5/storage/logo.png');
  });

  it('resolves null when the image fails to load, so the caller uses the default icon', async () => {
    stubImage({ fires: 'error' });

    await expect(logoToFavicon('/storage/missing.png')).resolves.toBeNull();
  });
});
