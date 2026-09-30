import { computed, reactive, ref } from 'vue';
import { toLocalDateKey } from '../utils/date';

/**
 * Logika daftar transaksi halaman Sales, dikeluarkan dari SalesView.vue: filter,
 * urutan kolom, ringkasan, ringkasan shift, dan (de)serialisasi ke query URL.
 *
 * Semuanya dikerjakan di frontend atas `transactions[]` yang sudah dimuat (F10.6:
 * "tanpa perlu memuat ulang seluruh laporan"). Antar-filter di-AND-kan; beberapa nilai
 * dalam SATU filter (kasir, penjual, pembayaran, shift) di-OR-kan.
 *
 * Halaman Sales hanya menampilkan transaksi dari POS (pre-order tidak ikut). Baris lama tanpa
 * field baru (mis. dari fixture/payload lama) diperlakukan wajar: bukan batal, tanpa margin.
 */
const CUSTOMER_TYPES = ['named', 'walkin'];
const PAY_STATES = ['pending', 'rejected', 'verified'];
const DATE_RE = /^\d{4}-\d{2}-\d{2}$/;
const MAX_LIST = 50;

// Nilai kosong (walk-in, tanpa penjual, tanpa margin) SELALU di akhir, apa pun arahnya.
export const SORT_ACCESSORS = {
  order_number: (tx) => tx.order_number ?? null,
  customer_name: (tx) => tx.customer_name || null,
  artist_names: (tx) => [...(tx.artist_names ?? [])].sort((a, b) => a.localeCompare(b))[0] ?? null,
  created_at: (tx) => Date.parse(tx.created_at),
  cashier_name: (tx) => tx.cashier_name || null,
  item_count: (tx) => Number(tx.item_count ?? 0),
  payment_methods: (tx) => (tx.payment_methods ?? [])[0] ?? null,
  margin_amount: (tx) => (tx.margin_amount === undefined ? null : parseFloat(tx.margin_amount)),
  total_amount: (tx) => parseFloat(tx.total_amount),
};

const num = (value) => parseFloat(value) || 0;
const isVoided = (tx) => tx.status === 'voided';
const cashierKey = (tx) => tx.cashier_id ?? tx.cashier_name;
const listOf = (raw) => String(raw ?? '').split(',').map((s) => s.trim()).filter(Boolean).slice(0, MAX_LIST);

function compareValues(a, b) {
  if (typeof a === 'number' && typeof b === 'number') return a - b;
  return String(a).localeCompare(String(b), undefined, { sensitivity: 'base', numeric: true });
}

/**
 * @param {{transactions: import('vue').Ref<Array>, sessions: import('vue').Ref<Array>}} source
 */
export function useSalesFilters({ transactions, sessions }) {
  const search = ref('');
  const includeVoided = ref(false);
  const filters = reactive({
    dateFrom: '', dateTo: '', cashiers: [], sellers: [], payments: [], sessions: [],
    customerType: '', paymentState: '', minTotal: '', maxTotal: '',
  });
  const sortKey = ref('created_at');
  const sortDir = ref('desc');

  // Setiap baris punya `key` ("order:5") — dipakai untuk seleksi baris dan ekspor. Payload lama
  // tanpa `key` dilengkapi di sini.
  const all = computed(() => (transactions.value ?? []).map((tx) => (tx.key ? tx : { ...tx, key: `order:${tx.id}` })));

  // --- opsi filter (diturunkan dari data yang dimuat) ----------------------------
  const cashierOptions = computed(() => {
    const seen = new Map();
    for (const tx of all.value) if (tx.cashier_name && !seen.has(cashierKey(tx))) seen.set(cashierKey(tx), tx.cashier_name);
    return [...seen].map(([value, label]) => ({ value, label })).sort((a, b) => a.label.localeCompare(b.label));
  });

  const sellerOptions = computed(() =>
    [...new Set(all.value.flatMap((tx) => tx.artist_names ?? []))]
      .sort((a, b) => a.localeCompare(b))
      .map((name) => ({ value: name, label: name }))
  );

  const PAYMENT_ORDER = ['cash', 'qr_ewallet', 'bank_transfer'];
  const paymentMethods = computed(() => {
    const methods = [...new Set(all.value.flatMap((tx) => tx.payment_methods ?? []))];
    return methods.sort((a, b) => (PAYMENT_ORDER.indexOf(a) + 1 || 99) - (PAYMENT_ORDER.indexOf(b) + 1 || 99));
  });

  // --- penyaringan ----------------------------------------------------------------
  function matchesSearch(tx, q) {
    const artistNames = (tx.artist_names ?? []).join(' ').toLowerCase();
    return [tx.order_number, tx.customer_name, tx.cashier_name].some((v) => (v ?? '').toLowerCase().includes(q)) || artistNames.includes(q);
  }

  function matchesFilters(tx) {
    const f = filters;
    if (f.cashiers.length && !f.cashiers.some((v) => v == cashierKey(tx))) return false;
    if (f.sellers.length && !(tx.artist_names ?? []).some((name) => f.sellers.includes(name))) return false;
    if (f.payments.length && !(tx.payment_methods ?? []).some((m) => f.payments.includes(m))) return false;
    if (f.sessions.length && !f.sessions.some((id) => id == tx.session_id)) return false;
    if (f.paymentState && (tx.payment_state ?? 'none') !== f.paymentState) return false;
    if (f.customerType === 'walkin' && tx.customer_name) return false;
    if (f.customerType === 'named' && !tx.customer_name) return false;

    const total = parseFloat(tx.total_amount);
    if (f.minTotal !== '' && f.minTotal !== null && total < Number(f.minTotal)) return false;
    if (f.maxTotal !== '' && f.maxTotal !== null && total > Number(f.maxTotal)) return false;

    // Hari LOKAL pengguna, bukan UTC: transaksi pukul 00.30 WIB milik hari itu.
    const day = toLocalDateKey(tx.created_at);
    if (f.dateFrom && day < f.dateFrom) return false;
    if (f.dateTo && day > f.dateTo) return false;
    return true;
  }

  const filtered = computed(() => {
    const q = search.value.trim().toLowerCase();
    return all.value.filter((tx) => (!q || matchesSearch(tx, q)) && matchesFilters(tx));
  });

  const hasFilterOtherThanSearch = computed(() => activeCount.value - (search.value.trim() !== '' ? 1 : 0) > 0);

  const activeCount = computed(() => {
    const f = filters;
    return [
      search.value.trim() !== '', f.dateFrom !== '', f.dateTo !== '', f.cashiers.length > 0, f.sellers.length > 0,
      f.payments.length > 0, f.sessions.length > 0, f.customerType !== '', f.paymentState !== '',
      f.minTotal !== '' && f.minTotal !== null, f.maxTotal !== '' && f.maxTotal !== null,
    ].filter(Boolean).length;
  });

  function reset() {
    search.value = '';
    Object.assign(filters, {
      dateFrom: '', dateTo: '', cashiers: [], sellers: [], payments: [], sessions: [],
      customerType: '', paymentState: '', minTotal: '', maxTotal: '',
    });
  }

  // --- urutan ------------------------------------------------------------------------
  function sortBy({ key, dir }) {
    sortKey.value = key;
    sortDir.value = dir;
  }

  const sorted = computed(() => {
    const get = SORT_ACCESSORS[sortKey.value] ?? SORT_ACCESSORS.created_at;
    const direction = sortDir.value === 'asc' ? 1 : -1;
    return [...filtered.value].sort((x, y) => {
      const a = get(x);
      const b = get(y);
      const aEmpty = a === null || a === undefined || Number.isNaN(a);
      const bEmpty = b === null || b === undefined || Number.isNaN(b);
      if (aEmpty !== bEmpty) return aEmpty ? 1 : -1;
      const primary = aEmpty ? 0 : compareValues(a, b) * direction;
      // Pemecah seri yang stabil: terbaru dulu.
      return primary || Date.parse(y.created_at) - Date.parse(x.created_at) || y.id - x.id;
    });
  });

  // --- ringkasan baris yang tampil ---------------------------------------------------
  // Transaksi batal tampil (bila diminta) tapi TIDAK PERNAH ikut dihitung.
  const hasMargin = computed(() => all.value.some((tx) => tx.margin_amount !== undefined));

  const summary = computed(() => {
    const rows = filtered.value;
    const counted = rows.filter((tx) => !isVoided(tx));
    const sum = (fn) => counted.reduce((acc, tx) => acc + fn(tx), 0);
    return {
      shown: rows.length,
      total: all.value.length,
      voided: rows.length - counted.length,
      units: Number(sum((tx) => Number(tx.unit_count ?? tx.item_count ?? 0)).toFixed(2)),
      amount: sum((tx) => num(tx.total_amount)),
      cash: sum((tx) => num(tx.cash_amount)),
      noncash: sum((tx) => num(tx.noncash_amount)),
      margin: hasMargin.value ? sum((tx) => num(tx.margin_amount)) : null,
    };
  });

  // --- ringkasan shift (hanya bila TEPAT satu shift dipilih) ---------------------------
  const shiftSummary = computed(() => {
    if (filters.sessions.length !== 1) return null;
    const session = (sessions.value ?? []).find((s) => s.id == filters.sessions[0]);
    if (!session) return null;

    // Dari SEMUA baris shift itu (bukan yang sudah disaring filter lain), tanpa yang batal.
    const cashSales = all.value
      .filter((tx) => tx.session_id == session.id && !isVoided(tx))
      .reduce((acc, tx) => acc + num(tx.cash_amount), 0);
    const opening = num(session.opening_cash);
    const closed = session.status === 'closed' && session.expected_cash !== null && session.closing_cash !== null;

    return {
      opening,
      cashSales,
      // Shift tertutup memakai nilai yang tersimpan di server saat ditutup; yang masih
      // berjalan dihitung: kas awal + penjualan tunai.
      expected: closed ? num(session.expected_cash) : opening + cashSales,
      counted: closed ? num(session.closing_cash) : null,
      difference: closed ? num(session.closing_cash) - num(session.expected_cash) : null,
    };
  });

  // --- query URL -----------------------------------------------------------------------
  /** Hanya nilai yang berbeda dari default, supaya URL bersih. */
  function toQuery() {
    const f = filters;
    const q = {};
    const put = (key, value) => { if (value !== '' && value !== null && value !== undefined && !(Array.isArray(value) && !value.length)) q[key] = Array.isArray(value) ? value.join(',') : String(value); };
    put('q', search.value.trim());
    put('from', f.dateFrom); put('to', f.dateTo);
    put('cashier', f.cashiers); put('seller', f.sellers); put('pay', f.payments); put('session', f.sessions);
    put('cust', f.customerType); put('pstate', f.paymentState);
    put('min', f.minTotal); put('max', f.maxTotal);
    if (includeVoided.value) q.voided = '1';
    if (sortKey.value !== 'created_at' || sortDir.value !== 'desc') { q.sort = sortKey.value; q.dir = sortDir.value; }
    return q;
  }

  /** Pulihkan dari query; nilai yang tak dikenal/tak valid diabaikan, bukan menyebabkan galat. */
  function applyQuery(query = {}) {
    const one = (v) => (Array.isArray(v) ? v[0] : v);
    const oneOf = (v, allowed) => (allowed.includes(one(v)) ? one(v) : '');
    const date = (v) => (DATE_RE.test(one(v) ?? '') ? one(v) : '');
    const amount = (v) => { const n = Number(one(v)); return one(v) !== undefined && one(v) !== '' && Number.isFinite(n) && n >= 0 ? n : ''; };

    search.value = String(one(query.q) ?? '').slice(0, 100);
    filters.dateFrom = date(query.from);
    filters.dateTo = date(query.to);
    filters.cashiers = listOf(one(query.cashier));
    filters.sellers = listOf(one(query.seller));
    filters.payments = listOf(one(query.pay));
    filters.sessions = listOf(one(query.session));
    filters.customerType = oneOf(query.cust, CUSTOMER_TYPES);
    filters.paymentState = oneOf(query.pstate, PAY_STATES);
    filters.minTotal = amount(query.min);
    filters.maxTotal = amount(query.max);
    includeVoided.value = one(query.voided) === '1';

    const sort = one(query.sort);
    if (Object.hasOwn(SORT_ACCESSORS, sort)) {
      sortKey.value = sort;
      sortDir.value = one(query.dir) === 'asc' ? 'asc' : 'desc';
    }
  }

  return {
    search, filters, includeVoided, sortKey, sortDir,
    cashierOptions, sellerOptions, paymentMethods,
    filtered, sorted, summary, shiftSummary, hasMargin,
    activeCount, hasFilterOtherThanSearch,
    reset, sortBy, toQuery, applyQuery,
  };
}
