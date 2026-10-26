import { describe, it, expect, beforeEach, afterEach, vi } from 'vitest';
import {
  adoptTokenFromOtherTabs,
  startSessionSync,
  broadcastLogout,
  resetTabSession,
  HANDOFF_TIMEOUT_MS,
} from '../../resources/js/utils/tabSession';

// 076-settlement-tab-session-branding (US1) — jsdom hanya punya SATU
// sessionStorage, jadi "tab lain" disimulasikan dengan kanal palsu yang
// berbagi bus dengan modul yang diuji (BroadcastChannel sungguhan tidak
// mengirim pesan ke objek yang sama, hanya ke objek lain bernama sama).
class FakeChannel {
  static all = new Set();

  constructor(name) {
    this.name = name;
    this.onmessage = null;
    FakeChannel.all.add(this);
  }

  postMessage(data) {
    for (const other of FakeChannel.all) {
      if (other !== this && other.name === this.name) {
        queueMicrotask(() => other.onmessage?.({ data }));
      }
    }
  }

  close() {
    FakeChannel.all.delete(this);
  }
}

const flush = () => new Promise((resolve) => queueMicrotask(resolve));

describe('tabSession (cross-tab session handoff)', () => {
  beforeEach(() => {
    sessionStorage.clear();
    localStorage.clear();
    FakeChannel.all.clear();
    resetTabSession();
    vi.stubGlobal('BroadcastChannel', FakeChannel);
  });

  afterEach(() => {
    vi.useRealTimers();
    vi.unstubAllGlobals();
  });

  it('adopts the token another signed-in tab answers with, into sessionStorage only', async () => {
    const peer = new FakeChannel('boothpos-session');
    peer.onmessage = (e) => {
      if (e.data.type === 'request') peer.postMessage({ type: 'token', token: 'tok-from-other-tab' });
    };

    const token = await adoptTokenFromOtherTabs();

    expect(token).toBe('tok-from-other-tab');
    expect(sessionStorage.getItem('boothpos_token')).toBe('tok-from-other-tab');
    expect(localStorage.length).toBe(0);
  });

  it('resolves null after the timeout when no tab answers', async () => {
    vi.useFakeTimers();
    const pending = adoptTokenFromOtherTabs();

    await vi.advanceTimersByTimeAsync(HANDOFF_TIMEOUT_MS + 1);

    await expect(pending).resolves.toBeNull();
    expect(sessionStorage.getItem('boothpos_token')).toBeNull();
  });

  it('resolves null immediately when BroadcastChannel is unavailable (today\'s behaviour)', async () => {
    vi.stubGlobal('BroadcastChannel', undefined);

    await expect(adoptTokenFromOtherTabs()).resolves.toBeNull();
  });

  it('does not ask anyone when this tab already has a token', async () => {
    sessionStorage.setItem('boothpos_token', 'mine');
    const peer = new FakeChannel('boothpos-session');
    const seen = vi.fn();
    peer.onmessage = seen;

    await expect(adoptTokenFromOtherTabs()).resolves.toBe('mine');
    await flush();

    expect(seen).not.toHaveBeenCalled();
  });

  it('ignores replies that are not a non-empty token string', async () => {
    vi.useFakeTimers();
    const peer = new FakeChannel('boothpos-session');
    peer.onmessage = (e) => {
      if (e.data.type === 'request') {
        peer.postMessage({ type: 'token', token: '' });
        peer.postMessage({ type: 'token', token: { evil: true } });
        peer.postMessage({ type: 'something-else', token: 'x' });
      }
    };

    const pending = adoptTokenFromOtherTabs();
    await vi.advanceTimersByTimeAsync(HANDOFF_TIMEOUT_MS + 1);

    await expect(pending).resolves.toBeNull();
    expect(sessionStorage.getItem('boothpos_token')).toBeNull();
  });

  it('a signed-in tab answers a request with its token; a signed-out tab stays silent', async () => {
    const peer = new FakeChannel('boothpos-session');
    const replies = [];
    peer.onmessage = (e) => replies.push(e.data);

    startSessionSync(() => {});
    peer.postMessage({ type: 'request' });
    await flush();
    await flush();
    expect(replies).toEqual([]); // tanpa token → diam

    sessionStorage.setItem('boothpos_token', 'secret-token');
    peer.postMessage({ type: 'request' });
    await flush();
    await flush();
    expect(replies).toEqual([{ type: 'token', token: 'secret-token' }]);
  });

  it('calls onLogout when another tab broadcasts a logout, and ignores unrelated messages', async () => {
    const onLogout = vi.fn();
    startSessionSync(onLogout);
    const peer = new FakeChannel('boothpos-session');

    peer.postMessage({ type: 'noise' });
    await flush();
    expect(onLogout).not.toHaveBeenCalled();

    peer.postMessage({ type: 'logout' });
    await flush();
    expect(onLogout).toHaveBeenCalledTimes(1);
  });

  it('broadcastLogout reaches the other tabs', async () => {
    startSessionSync(() => {});
    const peer = new FakeChannel('boothpos-session');
    const seen = vi.fn();
    peer.onmessage = seen;

    broadcastLogout();
    await flush();

    expect(seen).toHaveBeenCalledWith({ data: { type: 'logout' } });
  });
});
