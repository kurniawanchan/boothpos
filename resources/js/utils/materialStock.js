/**
 * 087 — saldo bahan kini boleh minus (penjualan tidak ditolak karena bahan; FR-014). Minus = tanda untuk stok
 * opname atau pembelian. Dengan beberapa outlet yang dinilai saldo per outlet (yang terlihat); tanpa rincian
 * outlet yang dinilai total.
 *
 * BUG YANG DITEMUKAN & DIPERBAIKI (2026-10-31): saat satu outlet dipilih di navbar dan bahan belum punya baris saldo
 * di outlet itu, daftar jatuh ke `current_stock` (TOTAL semua outlet). "15" milik WJ3 tampil di WDM, lalu penjualan di
 * WDM membuat −1 dan tampak seperti bug pemotongan. Outlet terpilih kini selalu memakai saldonya sendiri (0 bila
 * belum ada baris), tidak pernah total.
 */
const fmt = (n) => {
  const v = Number(n);
  return v < 0 ? `−${Math.abs(v)}` : String(v);
};

function balanceAt(row, outletId) {
  const hit = (row?.outlet_stock ?? []).find((o) => o.outlet_id === outletId);
  return hit ? Number(hit.on_hand) : 0;
}

export function negativeOutlets(row) {
  return (row?.outlet_stock ?? []).filter((o) => Number(o.on_hand) < 0);
}

/** `selectedOutletId` (opsional) = outlet navbar; bila ada, hanya saldo outlet itu yang dinilai. */
export function isNegativeStock(row, selectedOutletId = null) {
  if (selectedOutletId != null) return balanceAt(row, selectedOutletId) < 0;
  if ((row?.outlet_stock ?? []).length) return negativeOutlets(row).length > 0;
  return Number(row?.current_stock ?? 0) < 0;
}

/** Teks kolom Stok: outlet terpilih → saldonya; semua outlet → "KODE saldo" per outlet; satu outlet → total. */
export function materialStockText(row, { multi = false, selected = null } = {}) {
  if (selected != null) return fmt(balanceAt(row, selected));
  const rows = row?.outlet_stock ?? [];
  if (multi && rows.length) return rows.map((o) => `${o.outlet_code} ${fmt(o.on_hand)}`).join(' · ');
  return fmt(row?.current_stock ?? 0);
}

/** Tipe pergerakan bahan (urutan filter) dan warna pill-nya — satu definisi untuk riwayat per bahan dan log Stok. */
export const MATERIAL_MOVEMENT_TYPES = ['sale', 'return', 'waste', 'purchase', 'adjustment', 'transfer_out', 'transfer_in', 'transfer_cancel'];
const MATERIAL_MOVEMENT_TONE = { sale: 'warning', waste: 'danger', return: 'mint', purchase: 'success' };
export const materialMovementTone = (type) => MATERIAL_MOVEMENT_TONE[type] ?? 'neutral';
