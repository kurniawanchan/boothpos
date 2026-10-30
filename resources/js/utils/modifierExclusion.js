/**
 * 086 — memindahkan satu baris grup warisan antara daftar "Dari kategori" dan "Dilepas untuk item ini".
 * Dipakai laci Grup modifier DAN formulir Ubah item, supaya keduanya berperilaku sama (FR-006) tanpa menyalin logika.
 * Fungsi murni: mengembalikan dua larik BARU, tidak mengubah masukan. Id yang tidak ada di daftar asal tidak mengubah apa pun.
 *
 * @param {Array<{group_id: number}>} from daftar asal
 * @param {Array<{group_id: number}>} to daftar tujuan
 * @returns {{from: Array, to: Array}}
 */
export function moveRow(from, to, groupId) {
  const row = from.find((r) => r.group_id === groupId);
  if (!row) return { from: [...from], to: [...to] };

  return {
    from: from.filter((r) => r.group_id !== groupId),
    to: [...to, row].sort((a, b) => (a.display_order ?? 0) - (b.display_order ?? 0) || a.group_id - b.group_id),
  };
}

/** Id grup yang dilepas, urut naik, untuk dikirim sebagai `excluded_group_ids` dan dibandingkan dengan keadaan awal. */
export function excludedIds(excludedRows) {
  return excludedRows.map((r) => r.group_id).sort((a, b) => a - b);
}
