/**
 * 044-sales-table-view (research D2) — SATU-SATUNYA tempat angka papan Meja dan keadaan tiap ubin dihitung.
 * Papan, ubin, dan ringkasan empat angka hanya membaca keluaran fungsi ini, jadi angka tidak bisa menyimpang
 * dari ubin (SC-004). Murni: tanpa I/O, jam diberikan dari luar (`nowMs`).
 *
 * Aturan (data-model.md):
 *  - ubin terisi ⇔ meja punya order terbuka (`tab`); order takeaway selalu "terisi";
 *  - lewat batas ⇔ sudah terbuka LEBIH dari 45 menit (45 belum, 46 ya) — waktu nyata, bukan tanggal bisnis;
 *  - sebagian dibayar ⇔ 0 < dibayar < total;
 *  - angka "Meja > 45 menit" hanya menghitung ubin MEJA (takeaway boleh diberi tanda tetapi tidak dihitung);
 *  - uang dijumlah dalam sen bulat dari string uang server (tanpa selisih desimal float).
 */

/** Batas tetap (asumsi spec): tidak dapat diatur di fitur ini. */
export const OVER_LIMIT_MINUTES = 45;

const toCents = (value) => Math.round(Number(value ?? 0) * 100);
const centsToMoney = (cents) => (cents / 100).toFixed(2);

/** Menit penuh sejak `iso` (≥ 0); null bila tanggal tak valid/kosong. */
export function minutesSince(iso, nowMs) {
  const opened = Date.parse(iso ?? '');
  if (Number.isNaN(opened)) return null;

  return Math.max(0, Math.floor((nowMs - opened) / 60_000));
}

/** "19 mnt" di bawah satu jam, "1 j 05 m" di atasnya; `t` = fungsi terjemahan (kunci sales.elapsed_min / sales.elapsed_hm). */
export function formatElapsed(minutes, t) {
  if (minutes === null || minutes === undefined) return '';
  if (minutes < 60) return t('sales.elapsed_min', { n: minutes });

  return t('sales.elapsed_hm', { h: Math.floor(minutes / 60), m: minutes % 60 });
}

function orderTile(order, base, nowMs) {
  const elapsed = minutesSince(order.opened_at, nowMs);
  const total = toCents(order.total_amount);
  const paid = order.paid_amount === undefined ? 0 : toCents(order.paid_amount);

  return {
    ...base,
    state: 'occupied',
    orderId: order.id,
    orderNumber: order.order_number,
    elapsedMinutes: elapsed,
    overLimit: elapsed !== null && elapsed > OVER_LIMIT_MINUTES,
    partial: paid > 0 && paid < total,
    amount: centsToMoney(total),
    amountCents: total,
    paidCents: paid,
  };
}

const emptyTile = (base) => ({
  ...base, state: 'empty', orderId: null, orderNumber: null, elapsedMinutes: null,
  overLimit: false, partial: false, amount: null, amountCents: 0, paidCents: 0,
});

/**
 * @param {{areas?: Array, takeaway_tabs?: Array}} snapshot isi GET /floor
 * @param {number} nowMs jam saat ini (ms)
 */
export function buildBoard(snapshot, nowMs) {
  const groups = (snapshot?.areas ?? []).map((a) => {
    const tiles = (a.tables ?? []).map((table) => {
      const base = { key: `table-${table.id}`, kind: 'table', tableId: table.id, name: table.name, seats: table.seats ?? null, inactive: table.is_active === false };

      return table.tab ? orderTile(table.tab, base, nowMs) : emptyTile(base);
    });

    return { area: a.area ?? '', tiles, total: tiles.length, occupied: tiles.filter((t) => t.state === 'occupied').length };
  });

  const takeaway = (snapshot?.takeaway_tabs ?? []).map((tab) => orderTile(
    tab,
    { key: `takeaway-${tab.id}`, kind: 'takeaway', tableId: null, name: tab.tab_label || tab.order_number, seats: null, inactive: false },
    nowMs,
  ));

  const tableTiles = groups.flatMap((g) => g.tiles);
  const occupiedTables = tableTiles.filter((t) => t.state === 'occupied');
  const openCents = [...occupiedTables, ...takeaway].reduce((sum, t) => sum + t.amountCents, 0);

  return {
    figures: {
      openOrders: occupiedTables.length + takeaway.length,
      occupiedTables: occupiedTables.length,
      totalTables: tableTiles.length,
      occupancyPct: tableTiles.length ? Math.round((occupiedTables.length / tableTiles.length) * 100) : 0,
      overLimit: occupiedTables.filter((t) => t.overLimit).length,
      openValue: centsToMoney(openCents),
    },
    groups,
    takeaway,
  };
}
