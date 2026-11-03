// 090 / US4 — data diri pelanggan di halaman pesan mandiri. Hanya umpan balik cepat: server memvalidasi ulang (nama + telepon
// wajib bila mengisi, telepon 8–15 angka, surel sah) dan memilikinya sebagai satu-satunya sumber kebenaran.
//
// PRIVASI: data ini HANYA di memori (store), tidak pernah ke localStorage (draf keranjang menyimpan baris saja). Menutup
// halaman = data hilang; pelanggan tidak meninggalkan data pribadi di peramban perangkat orang lain.

export const emptyCustomer = () => ({ mode: 'guest', name: '', phone: '', email: '' });

/** Panjang angka telepon setelah membuang non-angka; aturan sama dengan App\Support\PhoneNormalizer (8–15, 62/0 depan). */
export function phoneDigits(raw) {
  let d = String(raw ?? '').replace(/\D+/g, '');
  if (d === '') return null;
  if (d.startsWith('62')) d = `0${d.slice(2)}`;
  else if (!d.startsWith('0')) d = `0${d}`;
  return d.length >= 8 && d.length <= 15 ? d : null;
}

const EMAIL = /^[^\s@]+@[^\s@]+\.[^\s@]+$/;

/** @returns {{name?: string, phone?: string, email?: string}} kunci galat; kosong = sah. Tamu selalu sah. */
export function validateCustomer(c) {
  if (c.mode !== 'details') return {};
  const errors = {};
  if (c.name.trim() === '' || c.name.trim().length > 100) errors.name = 'name';
  if (phoneDigits(c.phone) === null) errors.phone = 'phone';
  if (c.email.trim() !== '' && (!EMAIL.test(c.email.trim()) || c.email.trim().length > 150)) errors.email = 'email';
  return errors;
}

/** Blok `customer` untuk permintaan kirim; tamu → null (kunci dihilangkan). */
export function customerPayload(c) {
  if (c.mode !== 'details') return null;
  return { name: c.name.trim(), phone: c.phone.trim(), ...(c.email.trim() !== '' ? { email: c.email.trim() } : {}) };
}
