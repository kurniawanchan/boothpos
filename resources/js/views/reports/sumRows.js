import { parseMoney } from '../../utils/money';

// Field mana yang dijumlahkan (sebagai uang vs angka polos) untuk baris Grand Total tiap tab — satu
// tempat, dipakai oleh computed *Totals di tiap tab, supaya baris footer tidak menduplikasi logika sum.
// 039: uang dijumlahkan dalam SEN (bukan float) supaya Grand Total persis sama dengan jumlah semua
// subtotal per penjual (yang dihitung server dalam sen). Nilai tampil tidak berubah untuk tabel lain:
// semua masukan sudah bernilai 2 desimal.
export function sumRows(rows, moneyKeys = [], countKeys = []) {
  const cents = {};
  const totals = {};
  for (const k of moneyKeys) cents[k] = 0;
  for (const k of countKeys) totals[k] = 0;
  for (const row of rows) {
    for (const k of moneyKeys) cents[k] += Math.round(parseMoney(row[k]) * 100);
    for (const k of countKeys) totals[k] += Number(row[k]) || 0;
  }
  for (const k of moneyKeys) totals[k] = cents[k] / 100;
  return totals;
}
