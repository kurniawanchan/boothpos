// 045 Stage 2 (D17) — GET /floor mengembalikan bentuk lama ({ areas, takeaway_tabs }) bila hanya satu outlet
// yang berlaku, dan { outlets: [{ id, code, name, areas, takeaway_tabs }] } bila beberapa. Satu fungsi ini
// menyamakan keduanya supaya layar lantai dan papan Sales tidak perlu tahu bedanya; angka papan tetap dihitung
// di satu tempat (utils/floorBoard.js). Beberapa outlet: nama area diberi awalan nama outlet.
export function normalizeFloor(data) {
  if (!Array.isArray(data?.outlets)) {
    return { areas: data?.areas ?? [], takeaway_tabs: data?.takeaway_tabs ?? [], outlets: null };
  }

  const areas = data.outlets.flatMap((outlet) => (outlet.areas ?? []).map((a) => ({
    ...a,
    area: a.area ? `${outlet.name} · ${a.area}` : outlet.name,
    area_label: a.area ?? '', // nama area TANPA awalan outlet: papan Sales memberi judul per outlet sendiri
    outlet_id: outlet.id,
    outlet_name: outlet.name,
  })));
  const takeaway = data.outlets.flatMap((outlet) => (outlet.takeaway_tabs ?? []).map((tab) => ({ ...tab, outlet_id: outlet.id })));

  return { areas, takeaway_tabs: takeaway, outlets: data.outlets.map(({ id, code, name }) => ({ id, code, name })) };
}
