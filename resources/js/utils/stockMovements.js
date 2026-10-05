/**
 * 036 — satu definisi tipe pergerakan stok (warna pill + kunci label) untuk
 * layar Stok DAN riwayat transaksi per varian, supaya keduanya tidak pernah
 * berbeda tampilan untuk data yang sama. Tipe mengikuti stock_movements.type.
 */
export const MOVEMENT_TYPE_VARIANT = {
  purchase: 'mint',
  sale: 'neutral',
  preorder_handover: 'warn',
  adjustment: 'neutral',
  return: 'mint',
  initial: 'neutral',
};

export function movementTypeVariant(type) {
  return MOVEMENT_TYPE_VARIANT[type] ?? 'neutral';
}

export function movementTypeLabelKey(type) {
  return `master_data.type_${type}`;
}

/** Tipe yang valid untuk filter (urutan tampil). */
export const MOVEMENT_TYPES = Object.keys(MOVEMENT_TYPE_VARIANT);
