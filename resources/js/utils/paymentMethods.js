/**
 * Label tampilan metode pembayaran (cash / bank_transfer / qr_ewallet). Metode yang
 * tidak dikenal ditampilkan apa adanya alih-alih kunci terjemahan mentah, supaya
 * metode baru di backend tidak muncul sebagai "reports.payment_xyz" di layar.
 *
 * @param {(key: string) => string} t fungsi terjemahan vue-i18n
 */
export function paymentMethodLabel(t, method) {
  const key = `reports.payment_${method}`;
  const label = t(key);

  return label === key ? String(method) : label;
}
