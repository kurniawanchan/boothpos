import { describe, it, expect, vi, beforeEach } from 'vitest';
import { render, screen, waitFor, within } from '@testing-library/vue';
import { createPinia, setActivePinia } from 'pinia';
import { createRouter, createMemoryHistory } from 'vue-router';
import SalesView from '../../resources/js/views/SalesView.vue';
import { listEvents } from '../../resources/js/api/events';
import { salesReport, exportSalesTransactions } from '../../resources/js/api/reports';
import { toLocalDateKey } from '../../resources/js/utils/date';

vi.mock('../../resources/js/api/events', () => ({ listEvents: vi.fn() }));
vi.mock('../../resources/js/api/reports', () => ({ salesReport: vi.fn(), exportReport: vi.fn(), exportSalesTransactions: vi.fn() }));
vi.mock('../../resources/js/api/orders', () => ({ getOrder: vi.fn(), getReceipt: vi.fn(), voidOrder: vi.fn() }));
vi.mock('../../resources/js/api/products', () => ({ getProduct: vi.fn() }));

const toast = vi.hoisted(() => ({ success: vi.fn(), error: vi.fn() }));
vi.mock('../../resources/js/stores/toast', () => ({ useToastStore: () => toast }));

/**
 * Sales (HANYA transaksi dari POS): transaksi batal, status verifikasi pembayaran, shift
 * kasir + ringkasan kas, margin (hanya bila server mengirimnya), pratinjau item, preset
 * tanggal, filter di URL, dan ekspor "apa yang tampil / yang dipilih".
 */
const base = {
  discount_amount: '0.00', channel: 'offline', customer_id: null, customer_phone: null, customer_email: null, void_reason: null,
  payment_state: 'verified', items_more: 0,
};
const R1 = { ...base, key: 'order:301', id: 301, order_number: 'ORD-301', status: 'completed', customer_name: 'Budi', created_at: '2026-09-01T09:00:00Z', cashier_id: 1, cashier_name: 'Kasir A', session_id: 11, item_count: 2, unit_count: 4, total_amount: '60000.00', cash_amount: '60000.00', noncash_amount: '0.00', payment_methods: ['cash'], artist_names: ['Nekoyama Studio'], items_preview: [{ name: 'Akatsuki Keychain — Blue', qty: 3 }, { name: 'Sakura Sticker — Pink', qty: 1 }], items_more: 1 };
const R2 = { ...base, key: 'order:302', id: 302, order_number: 'ORD-302', status: 'completed', customer_name: null, created_at: '2026-09-01T11:00:00Z', cashier_id: 1, cashier_name: 'Kasir A', session_id: 11, item_count: 1, unit_count: 1, total_amount: '30000.00', cash_amount: '0.00', noncash_amount: '30000.00', payment_methods: ['qr_ewallet'], payment_state: 'pending', artist_names: ['Yukishiro Works'], items_preview: [{ name: 'Sakura Sticker — Pink', qty: 1 }] };
const R3 = { ...base, key: 'order:303', id: 303, order_number: 'ORD-303', status: 'completed', customer_name: 'Siti', created_at: '2026-09-02T10:00:00Z', cashier_id: 2, cashier_name: 'Kasir B', session_id: 12, item_count: 3, unit_count: 6, total_amount: '115000.00', cash_amount: '100000.00', noncash_amount: '15000.00', payment_methods: ['cash', 'bank_transfer'], artist_names: ['Nekoyama Studio', 'Hoshizora Craft'], items_preview: [{ name: 'Poster', qty: 2 }] };
const R4 = { ...base, key: 'order:304', id: 304, order_number: 'ORD-304', status: 'voided', void_reason: 'Salah input', customer_name: null, created_at: '2026-09-02T14:00:00Z', cashier_id: 2, cashier_name: 'Kasir B', session_id: 12, item_count: 1, unit_count: 1, total_amount: '15000.00', cash_amount: '15000.00', noncash_amount: '0.00', payment_methods: ['cash'], artist_names: ['Hoshizora Craft'], items_preview: [{ name: 'Pin', qty: 1 }] };

const SESSIONS = [
  { id: 12, cashier_name: 'Kasir B', opened_at: '2026-09-02T02:00:00Z', closed_at: '2026-09-02T12:00:00Z', status: 'closed', opening_cash: '50000.00', closing_cash: '95000.00', expected_cash: '100000.00' },
  { id: 11, cashier_name: 'Kasir A', opened_at: '2026-09-01T02:00:00Z', closed_at: null, status: 'open', opening_cash: '100000.00', closing_cash: null, expected_cash: null },
];

// Angka bersama (unit_count / gross_sales / net_sales) SENGAJA berbeda dari yang POS-saja: laporan
// bersama memuat pendapatan pre-order, halaman Sales tidak — dan harus memakai pos_*.
const response = (transactions, totals = {}) => ({
  event: { id: 1, name: 'Event A', start_date: '2026-09-01', end_date: '2026-09-02' },
  totals: {
    order_count: 3, unit_count: 14, gross_sales: '265000.00', net_sales: '265000.00',
    pos_unit_count: 11, pos_gross_sales: '205000.00', pos_net_sales: '205000.00', ...totals,
  },
  sessions: SESSIONS,
  transactions,
});
const WITHOUT_VOIDED = response([R3, R2, R1]);
const WITH_VOIDED = response([R4, R3, R2, R1]);

let router;

// `ready` = teks yang menandakan tabel sudah tampil (bisa saja baris ORD-301 memang tersaring oleh URL).
async function setup({ url = '/sales', data = WITHOUT_VOIDED, ready = 'ORD-301' } = {}) {
  salesReport.mockImplementation(async (params = {}) => (params.include_voided ? WITH_VOIDED : data));
  const { default: userEvent } = await import('@testing-library/user-event');
  const user = userEvent.setup();
  const pinia = createPinia();
  setActivePinia(pinia);
  router = createRouter({
    history: createMemoryHistory(),
    routes: [
      { path: '/sales', name: 'sales', component: { template: '<div />' } },
    ],
  });
  router.push(url);
  await router.isReady();
  render(SalesView, { global: { plugins: [pinia, router] } });
  await screen.findByText(ready);

  return user;
}

/** Satu baris ringkasan shift: labelnya + nilainya SENDIRI (regex rakus atas seluruh panel bisa cocok dengan angka baris lain). */
const shiftLine = (label) => within(screen.getByTestId('shift-summary')).getByText(label).parentElement;

const order = () => screen.getAllByRole('button', { name: /^ORD-\d+$/ }).map((b) => b.textContent.trim());
const rowOf = (n) => screen.getByText(n).closest('tr');

async function pick(user, trigger, option) {
  await user.click(screen.getByText(trigger));
  await user.click(await screen.findByRole('option', { name: option }));
}

describe('SalesView — POS transactions only', () => {
  beforeEach(() => {
    vi.clearAllMocks();
    listEvents.mockResolvedValue({ data: [{ id: 1, name: 'Event A', status: 'active', start_date: '2026-09-01', end_date: '2026-09-02' }] });
  });

  it('lists only POS transactions, newest first, with a receipt for each', async () => {
    await setup();

    expect(order()).toEqual(['ORD-303', 'ORD-302', 'ORD-301']);
    for (const number of order()) {
      expect(within(rowOf(number)).getByRole('button', { name: 'Lihat struk' })).toBeInTheDocument();
    }
  });

  it('has no Type column, no type filter and no pre-order wording', async () => {
    await setup();

    expect(screen.queryByRole('columnheader', { name: /^Tipe/ })).not.toBeInTheDocument();
    expect(screen.queryByTestId('filter-type')).not.toBeInTheDocument();
    expect(screen.queryByText('Semua tipe')).not.toBeInTheDocument();
    expect(document.body.textContent).not.toMatch(/pre-order/i);
  });

  it('shows POS-only figures in the summary cards, not the shared report totals', async () => {
    await setup();

    const cards = screen.getByTestId('kpi-cards');
    expect(cards).toHaveTextContent(/Rp\s*205\.000/);        // penjualan kotor & bersih POS
    expect(cards).not.toHaveTextContent(/265\.000/);          // total bersama (memuat pre-order) tidak dipakai
    expect(within(cards).getByText('Unit terjual').nextElementSibling).toHaveTextContent('11');
    expect(within(cards).getByText('Transaksi').nextElementSibling).toHaveTextContent('3');
  });

  it('falls back to the shared figures when an older response has no POS-only ones', async () => {
    await setup({ data: { ...WITHOUT_VOIDED, totals: { order_count: 3, unit_count: 5, gross_sales: '150000.00', net_sales: '150000.00' } } });

    expect(screen.getByTestId('kpi-cards')).toHaveTextContent(/Rp\s*150\.000/);
  });
});

describe('SalesView — voided transactions', () => {
  beforeEach(() => {
    vi.clearAllMocks();
    listEvents.mockResolvedValue({ data: [{ id: 1, name: 'Event A', status: 'active', start_date: '2026-09-01', end_date: '2026-09-02' }] });
  });

  it('hides voided orders by default', async () => {
    await setup();

    expect(order()).not.toContain('ORD-304');
    expect(salesReport).toHaveBeenLastCalledWith(expect.not.objectContaining({ include_voided: 1 }));
  });

  it('shows them, clearly marked, when asked — and asks the server, not just the browser', async () => {
    const user = await setup();

    await user.click(screen.getByLabelText('Tampilkan transaksi batal'));

    await waitFor(() => expect(order()).toContain('ORD-304'));
    expect(salesReport).toHaveBeenLastCalledWith(expect.objectContaining({ include_voided: 1 }));
    expect(within(rowOf('ORD-304')).getByText('Dibatalkan')).toBeInTheDocument();
  });

  it('never counts voided rows in the summary, but says how many are hidden inside it', async () => {
    const user = await setup();
    await user.click(screen.getByLabelText('Tampilkan transaksi batal'));
    await waitFor(() => expect(order()).toContain('ORD-304'));

    const summary = screen.getByText(/Menampilkan 4 dari 4 transaksi/);
    expect(summary).toHaveTextContent('1 batal tidak dihitung');
    expect(summary).toHaveTextContent('11 unit');           // 4+1+6 — tanpa 1 unit yang batal
    expect(summary).toHaveTextContent(/205\.000/);          // 60+30+115 — tanpa 15.000 yang batal
  });
});

describe('SalesView — payment verification', () => {
  beforeEach(() => {
    vi.clearAllMocks();
    listEvents.mockResolvedValue({ data: [{ id: 1, name: 'Event A', status: 'active', start_date: '2026-09-01', end_date: '2026-09-02' }] });
  });

  it('flags payments that are not yet verified', async () => {
    await setup();

    expect(within(rowOf('ORD-302')).getByText('Belum diverifikasi')).toBeInTheDocument();
    expect(within(rowOf('ORD-301')).queryByText('Belum diverifikasi')).not.toBeInTheDocument();
  });

  it('filters to the ones that need attention', async () => {
    const user = await setup();

    await pick(user, 'Semua status bayar', 'Perlu verifikasi');

    expect(order()).toEqual(['ORD-302']);
  });
});

describe('SalesView — cashier shifts and cash summary', () => {
  beforeEach(() => {
    vi.clearAllMocks();
    listEvents.mockResolvedValue({ data: [{ id: 1, name: 'Event A', status: 'active', start_date: '2026-09-01', end_date: '2026-09-02' }] });
  });

  it('filters by shift', async () => {
    const user = await setup();

    await user.click(screen.getByText('Semua shift'));
    await user.click(await screen.findByRole('option', { name: /Kasir A/ }));

    expect(order()).toEqual(['ORD-302', 'ORD-301']);
  });

  it('shows the expected cash for a single OPEN shift: opening + cash sales', async () => {
    const user = await setup();
    await user.click(screen.getByText('Semua shift'));
    await user.click(await screen.findByRole('option', { name: /Kasir A/ }));

    expect(shiftLine('Kas awal')).toHaveTextContent('Kas awal Rp 100.000');
    expect(shiftLine('Penjualan tunai')).toHaveTextContent('Penjualan tunai Rp 60.000');
    expect(shiftLine('Perkiraan kas di laci')).toHaveTextContent('Perkiraan kas di laci Rp 160.000');
  });

  it('shows counted cash and the difference for a CLOSED shift, and ignores voided sales', async () => {
    const user = await setup();
    await user.click(screen.getByLabelText('Tampilkan transaksi batal'));
    await waitFor(() => expect(order()).toContain('ORD-304'));
    await user.click(screen.getByText('Semua shift'));
    await user.click(await screen.findByRole('option', { name: /Kasir B/ }));

    expect(shiftLine('Penjualan tunai')).toHaveTextContent('Penjualan tunai Rp 100.000'); // ORD-303 saja; ORD-304 batal (15.000) tidak dihitung
    expect(shiftLine('Perkiraan kas di laci')).toHaveTextContent('Perkiraan kas di laci Rp 100.000'); // nilai tersimpan dari server
    expect(shiftLine('Kas dihitung')).toHaveTextContent('Kas dihitung Rp 95.000');
    expect(shiftLine('Selisih')).toHaveTextContent('Selisih Rp -5.000'); // dihitung 95.000 − 100.000
  });

  it('shows no shift panel unless exactly one shift is selected', async () => {
    const user = await setup();
    expect(screen.queryByTestId('shift-summary')).not.toBeInTheDocument();

    await user.click(screen.getByText('Semua shift'));
    await user.click(await screen.findByRole('option', { name: /Kasir A/ }));
    await user.click(await screen.findByRole('option', { name: /Kasir B/ }));

    expect(screen.queryByTestId('shift-summary')).not.toBeInTheDocument();
  });

  it('summarises cash and non-cash for what is on screen', async () => {
    await setup();

    const summary = screen.getByText(/Menampilkan 3 dari 3 transaksi/);
    expect(summary).toHaveTextContent(/Tunai Rp\s*160\.000/);      // 60 + 0 + 100
    expect(summary).toHaveTextContent(/Non-tunai Rp\s*45\.000/);   // 0 + 30 + 15
  });
});

describe('SalesView — margin (only when the server sends it)', () => {
  beforeEach(() => {
    vi.clearAllMocks();
    listEvents.mockResolvedValue({ data: [{ id: 1, name: 'Event A', status: 'active', start_date: '2026-09-01', end_date: '2026-09-02' }] });
  });

  const withMargin = response([
    { ...R3, cost_total: '40000.00', margin_amount: '75000.00', margin_percent: 65.2 },
    { ...R1, cost_total: '30000.00', margin_amount: '30000.00', margin_percent: 50 },
  ]);

  it('shows a Margin column and total for owners/admins', async () => {
    await setup({ data: withMargin });

    expect(screen.getByRole('columnheader', { name: /^Margin/ })).toBeInTheDocument();
    expect(rowOf('ORD-301')).toHaveTextContent(/30\.000.*50%/);
    expect(screen.getByText(/Menampilkan 2 dari 2 transaksi/)).toHaveTextContent(/Margin Rp\s*105\.000/);
  });

  it('sorts by margin', async () => {
    const user = await setup({ data: withMargin });

    await user.click(screen.getByRole('columnheader', { name: /^Margin/ }));

    expect(order()).toEqual(['ORD-301', 'ORD-303']);
  });

  it('has no Margin column at all when rows carry no margin (cashiers)', async () => {
    await setup();

    expect(screen.queryByRole('columnheader', { name: /^Margin/ })).not.toBeInTheDocument();
    expect(screen.getByText(/Menampilkan 3 dari 3 transaksi/)).not.toHaveTextContent('Margin');
  });
});

describe('SalesView — row detail at a glance', () => {
  beforeEach(() => {
    vi.clearAllMocks();
    listEvents.mockResolvedValue({ data: [{ id: 1, name: 'Event A', status: 'active', start_date: '2026-09-01', end_date: '2026-09-02' }] });
  });

  it('previews the biggest lines and counts the rest', async () => {
    await setup();
    const row = rowOf('ORD-301');

    expect(row).toHaveTextContent('Akatsuki Keychain — Blue ×3');
    expect(row).toHaveTextContent('Sakura Sticker — Pink ×1');
    expect(row).toHaveTextContent('+1 lainnya');
    expect(rowOf('ORD-302')).not.toHaveTextContent('lainnya');
  });
});

describe('SalesView — date presets', () => {
  beforeEach(() => {
    vi.clearAllMocks();
    listEvents.mockResolvedValue({ data: [{ id: 1, name: 'Event A', status: 'active', start_date: '2026-09-01', end_date: '2026-09-02' }] });
  });

  it('offers a shortcut for every day of the event and filters to that day', async () => {
    const user = await setup();

    await user.click(screen.getByRole('button', { name: 'Hari 1' }));
    expect(screen.getByLabelText('Dari tanggal')).toHaveValue('2026-09-01');
    expect(screen.getByLabelText('Sampai tanggal')).toHaveValue('2026-09-01');
    expect(order()).toEqual(['ORD-302', 'ORD-301']);

    await user.click(screen.getByRole('button', { name: 'Hari 2' }));
    expect(order()).toEqual(['ORD-303']);
  });

  it('has Today and Yesterday shortcuts based on the local date', async () => {
    const user = await setup();
    const today = toLocalDateKey(new Date());

    await user.click(screen.getByRole('button', { name: 'Hari ini' }));

    expect(screen.getByLabelText('Dari tanggal')).toHaveValue(today);
    expect(screen.getByLabelText('Sampai tanggal')).toHaveValue(today);

    const y = new Date();
    y.setDate(y.getDate() - 1);
    await user.click(screen.getByRole('button', { name: 'Kemarin' }));
    expect(screen.getByLabelText('Dari tanggal')).toHaveValue(toLocalDateKey(y));
  });

  it('clicking the active shortcut again clears the dates', async () => {
    const user = await setup();

    await user.click(screen.getByRole('button', { name: 'Hari 1' }));
    await user.click(screen.getByRole('button', { name: 'Hari 1' }));

    expect(screen.getByLabelText('Dari tanggal')).toHaveValue('');
    expect(order()).toHaveLength(3);
  });
});

describe('SalesView — filters live in the URL', () => {
  beforeEach(() => {
    vi.clearAllMocks();
    listEvents.mockResolvedValue({ data: [{ id: 1, name: 'Event A', status: 'active', start_date: '2026-09-01', end_date: '2026-09-02' }] });
  });

  it('writes the active filters and sort to the address, and removes them on reset', async () => {
    const user = await setup();

    await pick(user, 'Semua kasir', 'Kasir A');
    await user.click(screen.getByRole('columnheader', { name: /^Total/ }));

    await waitFor(() => expect(router.currentRoute.value.query.cashier).toBe('1'));
    expect(router.currentRoute.value.query.sort).toBe('total_amount');
    expect(router.currentRoute.value.query.dir).toBe('asc');

    await user.click(screen.getByRole('button', { name: 'Atur ulang filter' }));
    await waitFor(() => expect(router.currentRoute.value.query.cashier).toBeUndefined());
  });

  it('restores the filters and sort from the address when the page opens', async () => {
    await setup({ url: '/sales?cashier=2&sort=total_amount&dir=asc&min=20000', ready: 'ORD-303' });

    expect(order()).toEqual(['ORD-303']);                    // kasir id 2, total >= 20.000
    expect(screen.getByRole('columnheader', { name: /^Total/ })).toHaveAttribute('aria-sort', 'ascending');
    expect(screen.getByLabelText('Total minimum')).toHaveValue(20000);
  });

  it('ignores garbage in the address instead of breaking the page — including the retired type filter', async () => {
    await setup({ url: '/sales?type=preorder&sort=drop_table&dir=sideways&min=abc&from=nope' });

    expect(order()).toEqual(['ORD-303', 'ORD-302', 'ORD-301']);   // ?type=… tidak lagi berarti apa-apa
  });
});

describe('SalesView — export and selection', () => {
  beforeEach(() => {
    vi.clearAllMocks();
    listEvents.mockResolvedValue({ data: [{ id: 1, name: 'Event A', status: 'active', start_date: '2026-09-01', end_date: '2026-09-02' }] });
    exportSalesTransactions.mockResolvedValue(new Blob(['x']));
    URL.createObjectURL = vi.fn(() => 'blob:fake');
    URL.revokeObjectURL = vi.fn();
    HTMLAnchorElement.prototype.click = vi.fn();
  });

  it('exports exactly the rows on screen, in screen order', async () => {
    const user = await setup();

    await user.click(screen.getByRole('button', { name: 'Ekspor tampilan ini (3)' }));

    await waitFor(() => expect(exportSalesTransactions).toHaveBeenCalledTimes(1));
    expect(exportSalesTransactions).toHaveBeenCalledWith(
      expect.objectContaining({ event_id: 1 }),
      ['order:303', 'order:302', 'order:301'],
    );
    expect(URL.createObjectURL).toHaveBeenCalled();
  });

  it('follows the filters', async () => {
    const user = await setup();

    await pick(user, 'Semua kasir', 'Kasir A');
    await user.click(screen.getByRole('button', { name: 'Ekspor tampilan ini (2)' }));

    await waitFor(() => expect(exportSalesTransactions).toHaveBeenCalled());
    expect(exportSalesTransactions.mock.calls[0][1]).toEqual(['order:302', 'order:301']);
  });

  it('exports only the ticked rows when some are selected', async () => {
    const user = await setup();

    await user.click(screen.getByLabelText('Pilih ORD-301'));
    await user.click(screen.getByLabelText('Pilih ORD-303'));
    await user.click(screen.getByRole('button', { name: 'Ekspor terpilih (2)' }));

    await waitFor(() => expect(exportSalesTransactions).toHaveBeenCalled());
    expect(exportSalesTransactions.mock.calls[0][1]).toEqual(['order:303', 'order:301']); // urutan layar
  });

  it('select-all ticks the visible rows only', async () => {
    const user = await setup();
    await pick(user, 'Semua kasir', 'Kasir B');

    await user.click(screen.getByLabelText('Pilih semua'));

    expect(screen.getByRole('button', { name: 'Ekspor terpilih (1)' })).toBeInTheDocument();
  });

  it('passes the voided toggle to the export so the file matches the screen', async () => {
    const user = await setup();
    await user.click(screen.getByLabelText('Tampilkan transaksi batal'));
    await waitFor(() => expect(order()).toContain('ORD-304'));

    await user.click(screen.getByRole('button', { name: 'Ekspor tampilan ini (4)' }));

    await waitFor(() => expect(exportSalesTransactions).toHaveBeenCalled());
    expect(exportSalesTransactions.mock.calls[0][0]).toEqual(expect.objectContaining({ include_voided: 1 }));
  });

  it('reports an export failure', async () => {
    exportSalesTransactions.mockRejectedValue(new Error('gagal'));
    const user = await setup();

    await user.click(screen.getByRole('button', { name: 'Ekspor tampilan ini (3)' }));

    await waitFor(() => expect(toast.error).toHaveBeenCalled());
  });
});

// 028-partial-split-payment (US5) — penjualan yang dibayar sebagian terlihat di daftar,
// bisa disaring, dan sisa tagihannya dijumlahkan di ringkasan.
describe('SalesView — partially paid sales (028 US5)', () => {
  const R5 = { ...base, key: 'order:305', id: 305, order_number: 'ORD-305', status: 'completed', customer_name: 'Wulan', created_at: '2026-09-02T12:00:00Z', cashier_id: 2, cashier_name: 'Kasir B', session_id: 12, item_count: 1, unit_count: 2, total_amount: '100000.00', cash_amount: '40000.00', noncash_amount: '0.00', payment_methods: ['cash'], artist_names: ['Nekoyama Studio'], items_preview: [{ name: 'Poster', qty: 2 }], payment_status: 'partially_paid', paid_amount: '40000.00', balance_amount: '60000.00' };
  const paid = (r) => ({ ...r, payment_status: 'fully_paid', paid_amount: r.total_amount, balance_amount: '0.00' });

  beforeEach(() => {
    vi.clearAllMocks();
    listEvents.mockResolvedValue({ data: [{ id: 1, name: 'Event A', status: 'active', start_date: '2026-09-01', end_date: '2026-09-02' }] });
  });

  it('marks a partially paid sale with its remaining balance and sums the outstanding in the summary', async () => {
    await setup({ data: response([R5, paid(R3)]), ready: 'ORD-305' });

    expect(within(rowOf('ORD-305')).getByTestId('row-partial')).toHaveTextContent('60.000');
    expect(within(rowOf('ORD-303')).queryByTestId('row-partial')).not.toBeInTheDocument();
    expect(screen.getByText(/sisa tagihan Rp\s*60\.000/)).toBeInTheDocument();
  });

  it('restores the payment-status filter from the URL so only partially paid sales are listed', async () => {
    await setup({ url: '/sales?settle=partially_paid', data: response([R5, paid(R3)]), ready: 'ORD-305' });

    expect(order()).toEqual(['ORD-305']);
  });

  it('uses the shift cash received (including later payments) for the open shift panel', async () => {
    const sessions = [
      { id: 12, cashier_name: 'Kasir B', opened_at: '2026-09-02T02:00:00Z', closed_at: null, status: 'open', opening_cash: '50000.00', closing_cash: null, expected_cash: null, cash_received: '340000.00' },
    ];
    await setup({ url: '/sales?session=12', data: { ...response([R5, paid(R3)]), sessions }, ready: 'ORD-305' });

    expect(shiftLine('Penjualan tunai')).toHaveTextContent('340.000');
    expect(shiftLine('Perkiraan kas di laci')).toHaveTextContent('390.000');
  });
});
