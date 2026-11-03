// 090 / US2 — cermin sisi-klien aturan server `App\Support\SelfServiceSettings::isUsable` (hanya umpan balik cepat;
// server tetap menegakkan): alamat dasar QR meja harus http/https dengan host yang bukan localhost/loopback, karena QR
// yang berisi http://localhost/... tidak membuka apa pun di ponsel pelanggan.
export function isReachableBaseUrl(raw) {
  try {
    const url = new URL(String(raw).trim());
    const host = url.hostname.replace(/^\[|\]$/g, '').toLowerCase();
    if (!['http:', 'https:'].includes(url.protocol)) return false;

    return !(host === 'localhost' || host.endsWith('.localhost') || /^127\./.test(host) || host === '0.0.0.0' || host === '::1' || host === '::');
  } catch {
    return false;
  }
}
