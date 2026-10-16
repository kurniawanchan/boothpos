import { parseMoney } from './money';

/**
 * 048 — total keranjang POS untuk PRATINJAU sebelum order dibuat. Rumusnya SAMA dengan server
 * (App\Support\TaxCalculator + OrderService::create) dan tidak boleh ditambah aturan lain:
 *   dasar pajak = subtotal − diskon tingkat-order (tidak pernah negatif)
 *   pajak       = dibulatkan setengah ke atas PER ORDER ke rupiah penuh: round(dasar × tarif ÷ 100)
 *   total       = dasar + pajak
 * Setelah POST /orders, server yang menentukan total: struk dan laporan membacanya dari server.
 */

/**
 * Pajak untuk satu dasar pajak pada tarif persen (mis. 10 = 10%). Aritmetika bilangan bulat dalam seperseratus
 * supaya tidak ada galat float: floor((dasar × tarif100 + 5000) / 10000) = round-half-up(dasar × tarif ÷ 100).
 */
export function taxOnBase(base, ratePercent) {
  const rate = Number.isFinite(ratePercent) ? ratePercent : 0;
  if (rate <= 0 || base <= 0) return 0;

  const rateHundredths = Math.round(rate * 100);
  return Math.floor((base * rateHundredths + 5000) / 10000);
}

/**
 * @returns {{subtotal: number, discount: number, base: number, tax: number, total: number}} semua angka dalam rupiah
 */
export function cartTotals({ subtotal, discount = 0, taxRate = 0 }) {
  const sub = parseMoney(subtotal);
  const disc = parseMoney(discount);
  // Sama dengan PosView sebelumnya: dasar tidak pernah negatif; diskon di atas subtotal menghasilkan total 0.
  const base = Math.max(sub - disc, 0);
  const tax = taxOnBase(base, parseMoney(taxRate));

  return { subtotal: sub, discount: disc, base, tax, total: base + tax };
}
