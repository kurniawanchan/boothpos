import { describe, it, expect, vi } from 'vitest';
import { useDebouncedFn } from '../../resources/js/composables/useDebouncedFn';

// 049 — cancel() ditambahkan agar pindai barcode POS bisa membatalkan pencarian ber-debounce yang masih menunggu.
describe('useDebouncedFn', () => {
  it('runs only the last call after the delay', () => {
    vi.useFakeTimers();
    const fn = vi.fn();
    const debounced = useDebouncedFn(fn, 300);

    debounced('a'); debounced('b'); debounced('c');
    vi.advanceTimersByTime(299);
    expect(fn).not.toHaveBeenCalled();
    vi.advanceTimersByTime(1);

    expect(fn).toHaveBeenCalledTimes(1);
    expect(fn).toHaveBeenCalledWith('c');
    vi.useRealTimers();
  });

  it('cancel() drops a pending call and later calls still work', () => {
    vi.useFakeTimers();
    const fn = vi.fn();
    const debounced = useDebouncedFn(fn, 300);

    debounced('x');
    debounced.cancel();
    vi.advanceTimersByTime(1000);
    expect(fn).not.toHaveBeenCalled();

    debounced('y');
    vi.advanceTimersByTime(300);
    expect(fn).toHaveBeenCalledWith('y');
    vi.useRealTimers();
  });

  it('cancel() with nothing pending is harmless', () => {
    const debounced = useDebouncedFn(() => {}, 10);
    expect(() => debounced.cancel()).not.toThrow();
  });
});
