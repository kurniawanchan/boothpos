import { describe, it, expect, vi, beforeEach, afterEach } from 'vitest';
import { effectScope } from 'vue';
import { usePolling } from '../../resources/js/composables/usePolling';

// document.hidden hanya-baca di jsdom — ditimpa lewat defineProperty.
let hidden = false;
function setHidden(value) {
  hidden = value;
  document.dispatchEvent(new Event('visibilitychange'));
}

beforeEach(() => {
  hidden = false;
  Object.defineProperty(document, 'hidden', { configurable: true, get: () => hidden });
  vi.useFakeTimers();
  vi.spyOn(Math, 'random').mockReturnValue(0); // jitter = 0 → jeda deterministik
});

afterEach(() => {
  vi.useRealTimers();
  vi.restoreAllMocks();
});

describe('usePolling', () => {
  it('fetches immediately with full=true, then every interval with full=false', async () => {
    const fetchFn = vi.fn().mockResolvedValue();
    const poll = usePolling(fetchFn, { interval: 3000 });

    poll.start();
    await vi.advanceTimersByTimeAsync(0);
    expect(fetchFn).toHaveBeenCalledTimes(1);
    expect(fetchFn).toHaveBeenLastCalledWith({ full: true });

    await vi.advanceTimersByTimeAsync(3000);
    expect(fetchFn).toHaveBeenCalledTimes(2);
    expect(fetchFn).toHaveBeenLastCalledWith({ full: false });

    await vi.advanceTimersByTimeAsync(3000);
    expect(fetchFn).toHaveBeenCalledTimes(3);
    poll.stop();
  });

  it('pauses while the tab is hidden and refetches in full the moment it is visible again', async () => {
    const fetchFn = vi.fn().mockResolvedValue();
    const poll = usePolling(fetchFn, { interval: 3000 });
    poll.start();
    await vi.advanceTimersByTimeAsync(0);
    expect(fetchFn).toHaveBeenCalledTimes(1);

    setHidden(true);
    await vi.advanceTimersByTimeAsync(30000);
    expect(fetchFn).toHaveBeenCalledTimes(1); // tidak ada permintaan selama tersembunyi

    setHidden(false);
    await vi.advanceTimersByTimeAsync(0);
    expect(fetchFn).toHaveBeenCalledTimes(2); // langsung, tidak menunggu interval
    expect(fetchFn).toHaveBeenLastCalledWith({ full: true });
    poll.stop();
  });

  it('does not fetch at all when started while hidden, and starts when shown', async () => {
    hidden = true;
    const fetchFn = vi.fn().mockResolvedValue();
    const poll = usePolling(fetchFn, { interval: 3000 });
    poll.start();
    await vi.advanceTimersByTimeAsync(10000);
    expect(fetchFn).not.toHaveBeenCalled();

    setHidden(false);
    await vi.advanceTimersByTimeAsync(0);
    expect(fetchFn).toHaveBeenCalledTimes(1);
    poll.stop();
  });

  it('backs off after a failure, asks for a full refetch, and recovers by itself', async () => {
    const fetchFn = vi.fn()
      .mockResolvedValueOnce() // sukses
      .mockRejectedValueOnce(new Error('offline'))
      .mockResolvedValue();
    const poll = usePolling(fetchFn, { interval: 3000 });
    poll.start();
    await vi.advanceTimersByTimeAsync(0); // #1 sukses
    await vi.advanceTimersByTimeAsync(3000); // #2 gagal
    expect(fetchFn).toHaveBeenCalledTimes(2);
    expect(poll.lastError.value).toBeInstanceOf(Error);

    await vi.advanceTimersByTimeAsync(3000); // jeda sudah dua kali lipat (6000)
    expect(fetchFn).toHaveBeenCalledTimes(2);

    await vi.advanceTimersByTimeAsync(3000); // #3 — pulih
    expect(fetchFn).toHaveBeenCalledTimes(3);
    expect(fetchFn).toHaveBeenLastCalledWith({ full: true }); // keadaan lengkap setelah galat
    expect(poll.lastError.value).toBeNull();

    await vi.advanceTimersByTimeAsync(3000); // jeda kembali normal
    expect(fetchFn).toHaveBeenCalledTimes(4);
    expect(fetchFn).toHaveBeenLastCalledWith({ full: false });
    poll.stop();
  });

  it('never runs two requests at once, and coalesces refresh() calls made meanwhile', async () => {
    let release;
    const fetchFn = vi.fn(() => new Promise((resolve) => { release = resolve; }));
    const poll = usePolling(fetchFn, { interval: 3000 });
    poll.start();
    await vi.advanceTimersByTimeAsync(0);
    expect(fetchFn).toHaveBeenCalledTimes(1);

    await vi.advanceTimersByTimeAsync(20000); // jauh melewati interval, tapi masih in-flight
    poll.refresh();
    poll.refresh();
    expect(fetchFn).toHaveBeenCalledTimes(1);

    release();
    await vi.advanceTimersByTimeAsync(0);
    expect(fetchFn).toHaveBeenCalledTimes(2); // satu pengambilan susulan, bukan dua
    release();
    poll.stop();
  });

  it('stop() ends polling and detaches the visibility listener', async () => {
    const fetchFn = vi.fn().mockResolvedValue();
    const poll = usePolling(fetchFn, { interval: 3000 });
    poll.start();
    await vi.advanceTimersByTimeAsync(0);
    poll.stop();

    await vi.advanceTimersByTimeAsync(20000);
    setHidden(true);
    setHidden(false);
    await vi.advanceTimersByTimeAsync(0);
    expect(fetchFn).toHaveBeenCalledTimes(1);
    expect(poll.running.value).toBe(false);
  });

  it('stops automatically when the owning effect scope is disposed', async () => {
    const fetchFn = vi.fn().mockResolvedValue();
    const scope = effectScope();
    const poll = scope.run(() => usePolling(fetchFn, { interval: 3000 }));
    poll.start();
    await vi.advanceTimersByTimeAsync(0);

    scope.stop();
    await vi.advanceTimersByTimeAsync(20000);
    expect(fetchFn).toHaveBeenCalledTimes(1);
  });

  it('with immediate=false the first fetch waits one interval', async () => {
    const fetchFn = vi.fn().mockResolvedValue();
    const poll = usePolling(fetchFn, { interval: 3000, immediate: false });

    poll.start();
    await vi.advanceTimersByTimeAsync(2999);
    expect(fetchFn).not.toHaveBeenCalled();

    await vi.advanceTimersByTimeAsync(1);
    expect(fetchFn).toHaveBeenCalledTimes(1);
    expect(fetchFn).toHaveBeenLastCalledWith({ full: true });
    poll.stop();
  });
});
