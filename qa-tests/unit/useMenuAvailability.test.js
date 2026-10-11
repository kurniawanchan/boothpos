import { describe, it, expect, vi, beforeEach, afterEach } from 'vitest';
import { defineComponent, h, nextTick } from 'vue';
import { mount } from '@vue/test-utils';
import { useMenuAvailability } from '../../resources/js/composables/useMenuAvailability';
import { pollAvailability } from '../../resources/js/api/menu';

vi.mock('../../resources/js/api/menu', () => ({ pollAvailability: vi.fn() }));

const SNAPSHOT = { etag: 'e1', menu_version: 4, sold_out: [{ product_id: 12, variant_ids: [1, 2], reason_code: 'out_of_stock', expires_at: '2026-10-11T23:59:00+07:00' }], discontinued_ids: [30] };

let api;
const hosts = [];
function mountHost(opts) {
  const Host = defineComponent({
    setup() {
      api = useMenuAvailability(opts);
      return () => h('div');
    },
  });
  const wrapper = mount(Host);
  hosts.push(wrapper);
  return wrapper;
}
const flush = async () => {
  for (let i = 0; i < 5; i += 1) await Promise.resolve();
  await nextTick();
};
function setHidden(v) {
  Object.defineProperty(document, 'hidden', { configurable: true, get: () => v });
  document.dispatchEvent(new Event('visibilitychange'));
}

// 043 (T044) — polling ketersediaan menu.
describe('useMenuAvailability (043)', () => {
  beforeEach(() => {
    vi.useFakeTimers();
    vi.clearAllMocks();
    pollAvailability.mockResolvedValue(SNAPSHOT);
    Object.defineProperty(document, 'hidden', { configurable: true, get: () => false });
  });
  afterEach(() => {
    // Tiap host dilepas supaya pewaktu polling tes sebelumnya tidak ikut berjalan di tes berikutnya.
    hosts.splice(0).forEach((w) => w.unmount());
    vi.useRealTimers();
    Object.defineProperty(document, 'hidden', { configurable: true, get: () => false });
  });

  it('fetches on mount and exposes the sold-out / discontinued ids and menu version', async () => {
    mountHost();
    await flush();
    expect(pollAvailability).toHaveBeenCalledTimes(1);
    expect(pollAvailability).toHaveBeenCalledWith(null);
    expect(api.soldOutProductIds.value).toEqual([12]);
    expect(api.soldOutVariantIds.value).toEqual([1, 2]);
    expect(api.discontinuedIds.value).toEqual([30]);
    expect(api.menuVersion.value).toBe(4);
  });

  it('marks cards by product_id, by variant_id, and discontinued items; leaves others available', async () => {
    mountHost();
    await flush();
    expect(api.isUnavailable({ product_id: 12 })).toBe(true);
    expect(api.isUnavailable({ variant_id: 2 })).toBe(true);
    expect(api.isUnavailable({ product_id: 30 })).toBe(true);
    expect(api.unavailableReason({ product_id: 30 })).toBe('discontinued');
    expect(api.unavailableReason({ product_id: 12 })).toBe('sold_out');
    expect(api.isUnavailable({ product_id: 99 })).toBe(false);
    expect(api.isUnavailable({ variant_id: 99 })).toBe(false);
    expect(api.isUnavailable(null)).toBe(false);
  });

  it('polls every 5 seconds sending the last etag as `since`', async () => {
    mountHost();
    await flush();
    pollAvailability.mockClear();
    await vi.advanceTimersByTimeAsync(4900);
    expect(pollAvailability).not.toHaveBeenCalled();
    await vi.advanceTimersByTimeAsync(200);
    expect(pollAvailability).toHaveBeenCalledTimes(1);
    expect(pollAvailability).toHaveBeenLastCalledWith('e1');
  });

  it('keeps the current state when the server answers 304 (notModified)', async () => {
    mountHost();
    await flush();
    pollAvailability.mockResolvedValue({ notModified: true });
    await vi.advanceTimersByTimeAsync(5100);
    expect(api.soldOutProductIds.value).toEqual([12]);
    expect(api.menuVersion.value).toBe(4);
  });

  it('applies a new snapshot (item no longer 86)', async () => {
    mountHost();
    await flush();
    pollAvailability.mockResolvedValue({ etag: 'e2', menu_version: 4, sold_out: [], discontinued_ids: [] });
    await vi.advanceTimersByTimeAsync(5100);
    expect(api.isUnavailable({ product_id: 12 })).toBe(false);
  });

  it('a failed poll keeps the last known state (nothing is re-offered by accident) and later asks for a full snapshot', async () => {
    mountHost();
    await flush();
    pollAvailability.mockRejectedValue(new Error('offline'));
    await vi.advanceTimersByTimeAsync(5100);
    expect(api.isUnavailable({ product_id: 12 })).toBe(true);

    pollAvailability.mockResolvedValue(SNAPSHOT);
    // jeda setelah galat membesar (10 dtk); tepat satu putaran berikutnya yang harus berupa permintaan penuh
    await vi.advanceTimersByTimeAsync(10200);
    expect(pollAvailability).toHaveBeenCalledTimes(3); // awal + yang gagal + satu pemulihan
    // setelah galat etag dibuang: permintaan penuh (since kosong)
    expect(pollAvailability).toHaveBeenLastCalledWith(null);
  });

  it('pauses while the document is hidden and resumes with an immediate full fetch when visible again', async () => {
    mountHost();
    await flush();
    pollAvailability.mockClear();
    setHidden(true);
    await vi.advanceTimersByTimeAsync(20000);
    expect(pollAvailability).not.toHaveBeenCalled();
    setHidden(false);
    await flush();
    expect(pollAvailability).toHaveBeenCalledTimes(1);
    expect(pollAvailability).toHaveBeenLastCalledWith(null);
  });

  it('stops polling when the component is unmounted', async () => {
    const wrapper = mountHost();
    await flush();
    wrapper.unmount();
    pollAvailability.mockClear();
    await vi.advanceTimersByTimeAsync(30000);
    expect(pollAvailability).not.toHaveBeenCalled();
  });
});
