// 090 — keranjang halaman pesan mandiri. SEMUA fungsi murni dan hanya untuk TAMPILAN/pratinjau: server selalu menghitung
// ulang harga, modifier, pajak, dan total (Konstitusi IV), jadi angka di sini tidak pernah dikirim sebagai uang.
import { parseMoney } from './money';
import { describeSelection, modifierPayload, selectionDelta } from './modifierRules';

/**
 * Kartu menu dari payload PUBLIK (`GET /self-service/{token}/menu`). Bentuk payload ini berbeda dari daftar POS
 * (tanpa stok, `is_active`, atau outlet asal), jadi `buildProductCards` milik POS (`utils/posProductCards.js`) tidak
 * dipakai di sini; pembuatan kartu tetap SATU fungsi ini, bukan `map` inline di view.
 */
export function buildSelfServiceCards(products) {
  return (products || []).map((p) => {
    const variants = p.variants || [];
    const prices = variants.map((v) => parseMoney(v.price));
    return {
      product_id: p.id,
      name: p.name,
      description: p.description ?? null,
      category_id: p.category_id,
      category_name: p.category_name ?? null,
      image_url: p.image_url ?? null,
      sold_out: Boolean(p.sold_out) || variants.length === 0,
      min_price: prices.length ? Math.min(...prices) : 0,
      max_price: prices.length ? Math.max(...prices) : 0,
      variant_count: variants.length,
      variants,
      modifier_groups: p.modifier_groups ?? [],
    };
  });
}

/** Kartu yang butuh lembar pilihan (varian ganda atau ada grup modifier); selain itu langsung masuk keranjang. */
export const needsPicker = (card) => card.variant_count > 1 || (card.modifier_groups ?? []).length > 0;

/** Nama tampilan baris: varian tunggal bernama "Standard" cukup nama produk (aturan sama dengan server `lineName`). */
export function lineName(card, variant) {
  const single = card.variant_count === 1 && String(variant.name).trim().toLowerCase() === 'standard';
  return single || !variant.name ? card.name : `${card.name} — ${variant.name}`;
}

/** Harga satuan pratinjau = harga varian + selisih modifier (aturan porsi/jumlah sama dengan POS). */
export function unitPrice(variant, groups, selection = {}, quantities = {}) {
  const base = parseMoney(variant.price);
  return base + selectionDelta(groups ?? [], selection, base, quantities);
}

/** Kunci penggabungan: varian + catatan + pilihan identik = baris yang sama. */
export function lineKey(variantId, note, ids, quantities = {}) {
  const sel = [...ids].sort((a, b) => a - b).map((id) => `${id}x${Math.max(1, Number(quantities[id] ?? 1))}`).join(',');
  return `${variantId}|${(note ?? '').trim()}|${sel}`;
}

/**
 * @param {object} p { card, variant, selection (groupId→[optionId]), quantities (optionId→qty), qty, note }
 */
export function makeLine({ card, variant, selection = {}, quantities = {}, qty = 1, note = '' }) {
  const groups = card.modifier_groups ?? [];
  const ids = Object.values(selection).flat();
  const cleanNote = String(note ?? '').trim();
  return {
    key: lineKey(variant.id, cleanNote, ids, quantities),
    product_id: card.product_id,
    variant_id: variant.id,
    name: lineName(card, variant),
    image_url: card.image_url,
    qty,
    unit_price: unitPrice(variant, groups, selection, quantities),
    note: cleanNote,
    option_ids: ids,
    quantities: Object.fromEntries(ids.map((id) => [id, Math.max(1, Number(quantities[id] ?? 1))])),
    modifiers: describeSelection(groups, ids, quantities).map((m) => m.label),
  };
}

export function addToCart(lines, line) {
  const existing = lines.find((l) => l.key === line.key);
  if (!existing) return [...lines, { ...line }];
  return lines.map((l) => (l.key === line.key ? { ...l, qty: Math.min(99, l.qty + line.qty) } : l));
}

export function setLineQty(lines, key, qty) {
  if (qty <= 0) return lines.filter((l) => l.key !== key);
  return lines.map((l) => (l.key === key ? { ...l, qty: Math.min(99, qty) } : l));
}

export const removeLine = (lines, key) => lines.filter((l) => l.key !== key);

/** Total pratinjau; pajak dibulatkan ke rupiah penuh per ORDER (HALF_UP), sama dengan TaxCalculator di server. */
export function cartTotals(lines, taxRate = 0) {
  const subtotal = lines.reduce((sum, l) => sum + l.unit_price * l.qty, 0);
  const tax = Math.round((subtotal * (Number(taxRate) || 0)) / 100);
  return { count: lines.reduce((n, l) => n + l.qty, 0), subtotal, tax, total: subtotal + tax };
}

/** Isi permintaan kirim. TIDAK memuat harga, diskon, outlet, atau meja: semuanya ditolak server. */
export function buildOrderPayload({ lines, serviceType, proofToken, localRef, customer = null }) {
  return {
    local_ref: localRef,
    service_type: serviceType,
    proof_token: proofToken,
    ...(customer ? { customer } : {}),
    items: lines.map((l) => ({
      variant_id: l.variant_id,
      qty: l.qty,
      ...(l.note ? { note: l.note } : {}),
      ...(l.option_ids.length ? modifierPayload(l.option_ids, l.quantities) : {}),
    })),
  };
}

// --- draf keranjang (bertahan saat muat ulang; FR-013) --------------------------------------------------------------

const DRAFT_TTL_MS = 12 * 60 * 60 * 1000;

export function serializeDraft({ lines, serviceType, localRef }, now = Date.now()) {
  return JSON.stringify({ lines, serviceType, localRef, savedAt: now });
}

/** Draf tidak valid/kedaluwarsa → null (halaman mulai kosong), tidak pernah melempar. */
export function parseDraft(raw, now = Date.now()) {
  try {
    const d = JSON.parse(raw);
    if (!d || !Array.isArray(d.lines) || typeof d.localRef !== 'string') return null;
    if (now - Number(d.savedAt) > DRAFT_TTL_MS) return null;
    const lines = d.lines.filter((l) => l && Number.isInteger(l.variant_id) && Number.isInteger(l.qty) && l.qty > 0 && Number.isFinite(l.unit_price));
    return { lines, serviceType: d.serviceType === 'takeaway' ? 'takeaway' : 'dine_in', localRef: d.localRef };
  } catch {
    return null;
  }
}
