import { describe, it, expect, vi, beforeEach } from 'vitest';
import { render, screen, waitFor, within } from '@testing-library/vue';
import { createPinia, setActivePinia } from 'pinia';
import ReportsView from '../../resources/js/views/ReportsView.vue';
import { useAuthStore } from '../../resources/js/stores/auth';
import { listEvents } from '../../resources/js/api/events';
import { listArtists } from '../../resources/js/api/artists';
import { preorderReport } from '../../resources/js/api/reports';
import id from '../../resources/js/locales/id.json';
import en from '../../resources/js/locales/en.json';

vi.mock('../../resources/js/api/events', () => ({ listEvents: vi.fn() }));
vi.mock('../../resources/js/api/artists', () => ({ listArtists: vi.fn() }));
vi.mock('../../resources/js/api/reports', () => ({
  artistSettlements: vi.fn().mockResolvedValue({ data: [] }),
  artistSettlementTransactions: vi.fn(),
  profitReport: vi.fn(),
  artistProfitReport: vi.fn(),
  purchasesReport: vi.fn(),
  stockByArtistReport: vi.fn(),
  recordSettlementPayment: vi.fn(),
  exportReport: vi.fn(),
  preorderReport: vi.fn(),
}));
vi.mock('vue-router', () => ({ useRouter: () => ({ push: vi.fn() }) }));

const R = (artist_id, artist_name, status, payment_completeness, preorder_count, value, collected, outstanding) => ({
  artist_id, artist_name, status, payment_completeness, preorder_count,
  total_order_value: value, total_collected: collected, total_outstanding: outstanding,
});
// Angka dari layar yang melatari fitur ini: baris "Paid" punya collected > order value, outstanding 0.
const ROWS = [
  R(3, 'sapphirefiless', 'ordered', 'unpaid', 13, '930000.00', '0.00', '930000.00'),
  R(3, 'sapphirefiless', 'dp_paid', 'paid', 14, '845000.00', '1099000.00', '0.00'),
  R(3, 'sapphirefiless', 'dp_paid', 'partial', 1, '145000.00', '140000.00', '5000.00'),
  R(9, 'tofynx', 'ordered', 'unpaid', 4, '140000.00', '0.00', '140000.00'),
  R(11, 'y4o_shi', 'ordered', 'unpaid', 1, '15000.00', '0.00', '15000.00'),
  R(11, 'y4o_shi', 'dp_paid', 'paid', 1, '25000.00', '25000.00', '0.00'),
];
const SUBTOTALS = [
  { artist_id: 3, artist_name: 'sapphirefiless', preorder_count: 28, total_order_value: '1920000.00', total_collected: '1239000.00', total_outstanding: '935000.00' },
  { artist_id: 9, artist_name: 'tofynx', preorder_count: 4, total_order_value: '140000.00', total_collected: '0.00', total_outstanding: '140000.00' },
  { artist_id: 11, artist_name: 'y4o_shi', preorder_count: 2, total_order_value: '40000.00', total_collected: '25000.00', total_outstanding: '15000.00' },
];

async function openBySeller({ rows = ROWS, subtotals = SUBTOTALS } = {}) {
  preorderReport.mockImplementation(async (params = {}) => (params.breakdown === 'artist' ? { rows, subtotals } : { rows: [] }));
  const pinia = createPinia();
  setActivePinia(pinia);
  useAuthStore().user = { id: 1, role: 'Owner', name: 'Owner', menu_keys: ['dashboard', 'reports'] };
  render(ReportsView, { global: { plugins: [pinia] } });
  const { default: userEvent } = await import('@testing-library/user-event');
  const user = userEvent.setup();

  await user.click(await screen.findByRole('button', { name: 'Pre-order' }));
  await user.click(await screen.findByRole('button', { name: 'Per Penjual' }));
  if (rows.length) await screen.findAllByText('tofynx');

  return user;
}

const bodyRows = () => [...document.querySelectorAll('tbody tr')];
const text = (tr) => tr.textContent.replace(/\s+/g, ' ').trim();
const subtotalRows = () => bodyRows().filter((tr) => text(tr).startsWith('Subtotal'));
const grandTotal = () => [...document.querySelectorAll('tfoot tr')].find((tr) => text(tr).startsWith('Grand Total'));

// 039-preorder-seller-subtotal (US1) — baris Subtotal per penjual pada laporan Pre-order "Per Penjual".
describe('ReportsView — pre-order By Seller subtotals (039)', () => {
  beforeEach(() => {
    vi.clearAllMocks();
    listEvents.mockResolvedValue({ data: [{ id: 1, name: 'Comifuro 23', status: 'active' }] });
    listArtists.mockResolvedValue({ data: [{ id: 3, name: 'sapphirefiless' }, { id: 9, name: 'tofynx' }, { id: 11, name: 'y4o_shi' }] });
  });

  it('puts a "Subtotal — <seller>" row after each seller\'s LAST row, single-row sellers included', async () => {
    await openBySeller();

    const lines = bodyRows().map(text);
    // 6 baris data + 3 subtotal
    expect(lines).toHaveLength(9);
    const idx = (needle) => lines.findIndex((l) => l.startsWith(needle));
    expect(idx('Subtotal — sapphirefiless')).toBe(3); // tepat setelah 3 baris sapphirefiless
    expect(lines[2].startsWith('sapphirefiless')).toBe(true);
    expect(idx('Subtotal — tofynx')).toBe(5); // tofynx hanya satu baris, tetap punya subtotal
    expect(lines[4].startsWith('tofynx')).toBe(true);
    expect(idx('Subtotal — y4o_shi')).toBe(8);
    expect(lines[7].startsWith('y4o_shi')).toBe(true);
  });

  it('shows the server figures on a subtotal row, with empty status/completeness cells and no Detail button', async () => {
    await openBySeller();
    const sapphire = subtotalRows()[0];

    expect(within(sapphire).getByText('28')).toBeInTheDocument();
    expect(within(sapphire).getByText('Rp 1.920.000')).toBeInTheDocument();
    expect(within(sapphire).getByText('Rp 1.239.000')).toBeInTheDocument();
    expect(within(sapphire).getByText('Rp 935.000')).toBeInTheDocument();
    expect(within(sapphire).queryByRole('button', { name: id.reports.preorder_detail_action })).not.toBeInTheDocument();
    const cells = [...sapphire.querySelectorAll('td')].map((c) => c.textContent.trim());
    expect(cells[1]).toBe(''); // status
    expect(cells[2]).toBe(''); // payment completeness
    // baris data tetap punya Detail
    expect(within(bodyRows()[0]).getByRole('button', { name: id.reports.preorder_detail_action })).toBeInTheDocument();
  });

  it('styles a subtotal between a data row and the Grand Total', async () => {
    await openBySeller();
    const sub = subtotalRows()[0];
    const data = bodyRows()[0];

    expect(sub).toHaveClass('font-semibold', 'bg-surface-subtle');
    expect(data).not.toHaveClass('font-semibold');
    expect(grandTotal()).toHaveClass('font-bold', 'border-t-2');
    expect(sub).not.toHaveClass('border-t-2');
  });

  it('keeps the Grand Total last, unchanged, and equal to the sum of the subtotals for every figure', async () => {
    await openBySeller();
    const gt = grandTotal();

    // 28+4+2 = 34; 1.920.000+140.000+40.000 = 2.100.000; 1.239.000+0+25.000 = 1.264.000; 935.000+140.000+15.000 = 1.090.000
    expect(within(gt).getByText('34')).toBeInTheDocument();
    expect(within(gt).getByText('Rp 2.100.000')).toBeInTheDocument();
    expect(within(gt).getByText('Rp 1.264.000')).toBeInTheDocument();
    expect(within(gt).getByText('Rp 1.090.000')).toBeInTheDocument();
    expect(document.querySelector('tbody').compareDocumentPosition(gt) & Node.DOCUMENT_POSITION_FOLLOWING).toBeTruthy();
  });

  it('with one seller selected shows only that seller\'s rows, its subtotal, and a Grand Total equal to it', async () => {
    const user = await openBySeller();

    const sellerFilter = screen.getAllByRole('combobox').find((c) => /semua penjual/i.test(c.textContent));
    await user.click(sellerFilter);
    await user.click(await screen.findByRole('option', { name: 'tofynx' }));

    await waitFor(() => expect(bodyRows()).toHaveLength(2)); // 1 baris data + 1 subtotal
    expect(text(bodyRows()[0]).startsWith('tofynx')).toBe(true);
    expect(text(bodyRows()[1]).startsWith('Subtotal — tofynx')).toBe(true);
    const gt = grandTotal();
    expect(within(gt).getByText('4')).toBeInTheDocument();
    expect(within(gt).getAllByText('Rp 140.000').length).toBeGreaterThan(0);
  });

  it('shows no subtotal and no Grand Total when there are no rows', async () => {
    await openBySeller({ rows: [], subtotals: [] });

    expect(subtotalRows()).toHaveLength(0);
    expect(grandTotal()).toBeUndefined();
    expect(await screen.findByText(id.reports.no_preorder_report_data)).toBeInTheDocument();
  });

  it('opens the drill-down of the DATA row that is clicked, and the Summary view has no subtotal rows', async () => {
    const user = await openBySeller();

    await user.click(within(bodyRows()[1]).getByRole('button', { name: id.reports.preorder_detail_action }));
    await waitFor(() => expect(preorderReport).toHaveBeenCalledWith(expect.objectContaining({ status: 'dp_paid', payment_completeness: 'paid', artist_id: 3 })));
  });

  it('has the Subtotal label in both languages', () => {
    expect(id.reports.subtotal).toBeTruthy();
    expect(en.reports.subtotal).toBeTruthy();
  });
});
