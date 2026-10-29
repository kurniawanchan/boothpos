import { parseMoney } from './money';

/**
 * 083 — aturan tampil baris pajak untuk ORDER TERSIMPAN (detail Penjualan, struk), satu tempat. Baris tampil hanya bila
 * pengaturan "tampilkan" aktif DAN order itu memang dikenai pajak: order lama bertarif 0 punya `tax_amount` 0 dan tidak
 * boleh memunculkan baris "Pajak Rp 0". Tidak pernah memengaruhi total — itu angka tersimpan dari server.
 *
 * Layar kerja (keranjang, pembayaran) memakai getter `showTaxLine` di store pengaturan, karena di sana angkanya
 * perkiraan dari tarif saat ini, bukan jumlah tersimpan.
 */
export function shouldShowTaxLine({ show, amount }) {
  return show !== false && parseMoney(amount) > 0;
}

/** "PPN (10%)" — tarif bulat tanpa desimal, tarif pecahan apa adanya ("PPN (11.5%)"); tanpa tarif hanya label. */
export function formatTaxLabel(label, ratePercent) {
  const text = (label || '').trim() || 'Pajak';
  const rate = parseMoney(ratePercent);
  if (!(rate > 0)) return text;

  return `${text} (${Number.isInteger(rate) ? rate : rate.toString()}%)`;
}
