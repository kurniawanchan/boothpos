/**
 * 043 (US8) — pratinjau biaya resep di sisi klien. SATU definisi rumus di frontend, mencerminkan
 * App\Support\RecipeCost (sumber kebenaran server) langkah demi langkah termasuk pembulatannya:
 *
 *   biaya baris   = jumlah ÷ (1 − susut%/100) × biaya satuan          (4 desimal)
 *   biaya porsi   = Σ biaya baris ÷ yield                             (4 desimal)
 *   food cost %   = biaya porsi ÷ harga dasar × 100                   (1 desimal)
 *   margin kotor  = harga dasar − biaya porsi                         (2 desimal)
 *
 * Ini HANYA pratinjau di drawer: server menghitung ulang saat menyimpan dan angka resmi selalu dari server.
 * Baris tanpa biaya satuan TIDAK dianggap nol — `incomplete` menyala dan food cost % / margin menjadi null,
 * supaya angka yang kurang tidak tampak seperti angka yang benar (sama dengan server).
 */
const round = (n, dp) => {
  const f = 10 ** dp;
  return Math.round((n + Number.EPSILON) * f) / f;
};

const num = (v) => {
  if (v === '' || v === null || v === undefined) return NaN;
  return Number(v);
};

/** Biaya satu baris, atau null bila biaya satuan tidak diketahui atau masukan belum sah. */
export function lineCost(quantity, shrinkagePct, unitCost) {
  const q = num(quantity);
  const s = num(shrinkagePct === '' ? 0 : shrinkagePct);
  const u = unitCost === null || unitCost === undefined ? NaN : Number(unitCost);
  if (!Number.isFinite(u) || !Number.isFinite(q) || !Number.isFinite(s) || s >= 100) return null;
  return round((q / (1 - s / 100)) * u, 4);
}

/**
 * @param {Array<{quantity: *, shrinkage_pct: *, unit_cost: number|null}>} lines
 * @param {number} yieldQty
 * @param {number|null} basePrice
 */
export function computeRecipeCost(lines, yieldQty, basePrice) {
  const costs = [];
  let sum = 0;
  let unknown = 0;
  for (const l of lines) {
    const c = lineCost(l.quantity, l.shrinkage_pct, l.unit_cost);
    costs.push(c);
    if (c === null) unknown += 1;
    else sum += c;
  }
  const y = Number(yieldQty);
  const portion = Number.isFinite(y) && y > 0 ? round(sum / y, 4) : 0;
  const incomplete = unknown > 0;
  const priced = !incomplete && basePrice !== null && basePrice !== undefined && Number(basePrice) > 0;
  return {
    lineCosts: costs,
    unknownCount: unknown,
    portionCost: portion,
    incomplete,
    foodCostPct: priced ? round((portion / Number(basePrice)) * 100, 1) : null,
    grossMargin: priced ? round(Number(basePrice) - portion, 2) : null,
  };
}

/**
 * Harga acuan bahan dari daftar harga vendor — preferensi dulu, lalu termurah, lalu id terkecil
 * (cermin Material::referencePrice()). Null bila tidak ada harga.
 */
export function referencePrice(vendorPrices) {
  const list = (vendorPrices ?? []).filter((p) => Number.isFinite(Number(p.price)));
  if (!list.length) return null;
  const sorted = [...list].sort((a, b) => Number(!!b.is_preferred) - Number(!!a.is_preferred) || Number(a.price) - Number(b.price) || (a.id ?? 0) - (b.id ?? 0));
  return Number(sorted[0].price);
}
