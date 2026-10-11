import { describe, it, expect, vi, beforeEach, afterEach } from 'vitest';
import { effectScope } from 'vue';
import { useAutoRefresh } from '../../resources/js/composables/useAutoRefresh';

// document.hidden hanya-baca di jsdom — ditimpa lewat defineProperty (pola yang sama dengan usePolling.test.js).
let hidden = false;
function setHidden(value) {
  hidden = value;
  document.dispatchEvent(new Event('visibilitychange'));
}

beforeEach(() => {
  hidden = false;
  Object.defineProperty(document, 'hidden', { configurable: true, get: () => hidden });
  vi.useFakeTimers();
});
afterEach(() => {
  vi.useRealTimers();
});

describe('useAutoRefresh (042)', () => {
  it('calls fn every interval while running, and reports running', async () => {
    const fn = vi.fn().mockResolvedValue();
    const r = useAutoRefresh(fn, 30_000);
    expect(r.running.value).toBe(false);
    r.start();
    expect(r.running.value).toBe(true);
    expect(fn).not.toHaveBeenCalled(); // pemanggil memuat sendiri saat mount; refresh pertama menunggu satu interval

    await vi.advanceTimersByTimeAsync(30_000);
    expect(fn).toHaveBeenCalledTimes(1);
    await vi.advanceTimersByTimeAsync(30_000);
    expect(fn).toHaveBeenCalledTimes(2);
    r.stop();
    expect(r.running.value).toBe(false);
  });

  it('pauses while the tab is hidden and refreshes immediately when it is visible again', async () => {
    const fn = vi.fn().mockResolvedValue();
    const r = useAutoRefresh(fn, 30_000);
    r.start();

    setHidden(true);
    expect(r.running.value).toBe(false);
    await vi.advanceTimersByTimeAsync(120_000);
    expect(fn).not.toHaveBeenCalled();

    setHidden(false);
    await vi.advanceTimersByTimeAsync(0);
    expect(fn).toHaveBeenCalledTimes(1); // segera, bukan menunggu interval
    expect(r.running.value).toBe(true);
    await vi.advanceTimersByTimeAsync(30_000);
    expect(fn).toHaveBeenCalledTimes(2);
    r.stop();
  });

  it('does not start a timer when begun while hidden, and arms when shown', async () => {
    hidden = true;
    const fn = vi.fn().mockResolvedValue();
    const r = useAutoRefresh(fn, 1000);
    r.start();
    expect(r.running.value).toBe(false);
    setHidden(false);
    await vi.advanceTimersByTimeAsync(0);
    expect(fn).toHaveBeenCalledTimes(1);
    r.stop();
  });

  it('never stacks calls while one is still in flight', async () => {
    let release;
    const fn = vi.fn(() => new Promise((res) => { release = res; }));
    const r = useAutoRefresh(fn, 1000);
    r.start();
    await vi.advanceTimersByTimeAsync(3500);
    expect(fn).toHaveBeenCalledTimes(1);
    release();
    await vi.advanceTimersByTimeAsync(1000);
    expect(fn).toHaveBeenCalledTimes(2);
    r.stop();
  });

  it('keeps ticking after fn rejects', async () => {
    const fn = vi.fn().mockRejectedValueOnce(new Error('down')).mockResolvedValue();
    const r = useAutoRefresh(fn, 1000);
    r.start();
    await vi.advanceTimersByTimeAsync(2100);
    expect(fn).toHaveBeenCalledTimes(2);
    r.stop();
  });

  it('cleans up its timer and listener when the scope is disposed', async () => {
    const fn = vi.fn().mockResolvedValue();
    const scope = effectScope();
    let r;
    scope.run(() => { r = useAutoRefresh(fn, 1000); r.start(); });
    scope.stop();
    expect(r.running.value).toBe(false);
    await vi.advanceTimersByTimeAsync(5000);
    setHidden(true);
    setHidden(false);
    await vi.advanceTimersByTimeAsync(5000);
    expect(fn).not.toHaveBeenCalled();
  });
});
