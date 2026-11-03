import { describe, it, expect, beforeEach } from 'vitest';
import { adoptTokenFromOtherTabs, getToken, listenForTokenRequests, setToken, clearToken } from '../../resources/js/utils/storage';

// 2026-11-02 — tab baru memakai sesi tab lain yang masih terbuka. jsdom tak punya BroadcastChannel antar-"tab", jadi
// dipalsukan: semua instance dengan nama sama saling menerima pesan (kecuali pengirim).
class FakeChannel {
  static all = [];
  constructor(name) { this.name = name; this.onmessage = null; FakeChannel.all.push(this); }
  postMessage(data) { FakeChannel.all.filter((c) => c !== this && c.name === this.name).forEach((c) => c.onmessage?.({ data })); }
  close() { FakeChannel.all = FakeChannel.all.filter((c) => c !== this); }
}

describe('token lintas tab', () => {
  beforeEach(() => { FakeChannel.all = []; globalThis.BroadcastChannel = FakeChannel; clearToken(); });

  it('tab baru mengambil token dari tab lain yang menjawab', async () => {
    const other = new FakeChannel('boothpos_auth'); // tab lama
    other.onmessage = (e) => { if (e.data.type === 'request') other.postMessage({ type: 'token', token: 'abc' }); };

    expect(getToken()).toBeNull();
    expect(await adoptTokenFromOtherTabs(200)).toBe(true);
    expect(getToken()).toBe('abc');
  });

  it('tab yang punya token menjawab permintaan, tab tanpa token diam', async () => {
    listenForTokenRequests();
    const requester = new FakeChannel('boothpos_auth');
    const got = [];
    requester.onmessage = (e) => got.push(e.data);

    requester.postMessage({ type: 'request' });
    expect(got).toEqual([]);
    setToken('xyz');
    requester.postMessage({ type: 'request' });
    expect(got).toEqual([{ type: 'token', token: 'xyz' }]);
  });

  it('tanpa tab yang login, tidak ada token yang diambil', async () => {
    listenForTokenRequests();
    expect(await adoptTokenFromOtherTabs(50)).toBe(false);
    expect(getToken()).toBeNull();
  });
});
