import { describe, it, expect, vi, beforeEach } from 'vitest';
import { render, screen, fireEvent, waitFor } from '@testing-library/vue';
import { createPinia, setActivePinia } from 'pinia';
import { createRouter, createMemoryHistory } from 'vue-router';

vi.mock('../../resources/js/api/settlements', () => ({
  getSettlement: vi.fn(),
  refreshSettlement: vi.fn(),
  deleteSettlement: vi.fn(),
  finalizeSettlement: vi.fn(),
  reopenSettlement: vi.fn(),
  addSettlementAdjustment: vi.fn(),
  updateSettlementAdjustment: vi.fn(),
  deleteSettlementAdjustment: vi.fn(),
  acknowledgeSettlement: vi.fn(),
  getAcknowledgementSignature: vi.fn(),
  addSettlementPayment: vi.fn(),
  deleteSettlementPayment: vi.fn(),
  getSettlementPaymentProof: vi.fn(),
}));

import * as api from '../../resources/js/api/settlements';
import SettlementDetailView from '../../resources/js/views/SettlementDetailView.vue';

// 076 (US2) — layar detail settlement. API di-mock; yang diuji: angka dari
// server ditampilkan apa adanya, aksi mengikuti `can`, aksi yang tak tersedia
// tetap terlihat tetapi nonaktif dengan alasan, gagal-muat = keadaan gagal.

function detail(overrides = {}) {
  return {
    id: 12, number: null, status: 'draft', display_status: 'draft',
    event: { id: 3, name: 'Event Uji' }, seller: { id: 7, name: 'Seller Uji', code: 'ABC' },
    pos_units: 4, pos_revenue: '40000.00', preorder_units: 2, preorder_revenue: '5000.00',
    revenue_total: '45000.00', adjustments_total: '-5000.00', net_amount: '40000.00',
    direction: 'store_pays_seller', paid_amount: '0.00', balance: '40000.00', payment_status: 'unpaid',
    acknowledged: false, finalized_at: null, created_at: '2026-10-26T10:00:00+07:00',
    sources: [
      { id: 1, source_type: 'order', source_id: 5, source_number: 'TRX-1', source_date: '2026-10-26T10:00:00+07:00', units: 4, gross_amount: '40000.00', amount: '40000.00', uncollected_amount: '0.00', cost_amount: '16000.00' },
      { id: 2, source_type: 'preorder', source_id: 9, source_number: 'PO-1', source_date: '2026-10-26T11:00:00+07:00', units: 2, gross_amount: '20000.00', amount: '5000.00', uncollected_amount: '15000.00', cost_amount: '2000.00' },
    ],
    adjustments: [
      { id: 5, kind: 'deduction', label: 'Biaya booth', reason: 'Bagian biaya', basis: '40% porsi', amount: '-5000.00', post_finalization: false, created_by_name: 'Owner', created_at: '2026-10-26T10:05:00+07:00' },
    ],
    acknowledgement: null, acknowledgement_history: [], payments: [],
    reconciliation: { sources_sum: '45000.00', adjustments_sum: '-5000.00', expected_net: '40000.00', net_amount: '40000.00', difference: '0.00', pos_check: '0.00', preorder_check: '0.00', payment_check: '0.00', balanced: true, failing: null },
    memo: { preorder_uncollected: '15000.00', cost_of_goods: '18000.00', gross_profit: '27000.00' },
    source_changed: null,
    can: { finalize: true, reopen: false, delete: true, acknowledge: false, add_payment: false, adjust: true },
    ...overrides,
  };
}

async function renderDetail(data) {
  api.getSettlement.mockResolvedValue({ data });
  const pinia = createPinia();
  setActivePinia(pinia);
  const router = createRouter({
    history: createMemoryHistory(),
    routes: [
      { path: '/settlements', name: 'settlements', component: { template: '<div />' } },
      { path: '/settlements/:id', name: 'settlement-detail', component: SettlementDetailView },
      { path: '/preorders', name: 'preorders', component: { template: '<div />' } },
    ],
  });
  router.push('/settlements/12');
  await router.isReady();
  return { router, ...render(SettlementDetailView, { global: { plugins: [pinia, router] } }) };
}

describe('SettlementDetailView', () => {
  beforeEach(() => vi.clearAllMocks());

  it('shows the server figures, the reconciliation difference and the draft actions', async () => {
    await renderDetail(detail());

    expect(await screen.findByTestId('net-amount')).toHaveTextContent('Rp 40.000');
    expect(screen.getByTestId('revenue-total')).toHaveTextContent('Rp 45.000');
    expect(screen.getByTestId('balance')).toHaveTextContent('Rp 40.000');
    expect(screen.getByTestId('settlement-number')).toHaveTextContent(/draf/i);
    expect(screen.getByTestId('reconciliation-state')).toHaveTextContent(/seimbang/i);
    expect(screen.getByTestId('reconciliation-difference')).toHaveTextContent('Rp 0');
    expect(screen.getAllByTestId('adjustment-row')).toHaveLength(1);
    expect(screen.getByText('Biaya booth')).toBeInTheDocument();
    expect(screen.getByRole('button', { name: /finalkan/i })).toBeEnabled();
    expect(screen.getByRole('button', { name: /tambah potongan/i })).toBeInTheDocument();
    expect(screen.queryByRole('button', { name: /buka kembali/i })).not.toBeInTheDocument();
  });

  it('lists transactions by number only — no customer fields — and links pre-orders', async () => {
    await renderDetail(detail());

    await screen.findByText('TRX-1');
    const link = screen.getByRole('link', { name: 'PO-1' });
    expect(link.getAttribute('href')).toContain('preorder_id=9');
    expect(document.body.textContent).not.toMatch(/customer|pelanggan/i);
  });

  it('finalizing asks for confirmation, then shows the number the server assigned', async () => {
    await renderDetail(detail());
    api.finalizeSettlement.mockResolvedValue({
      data: detail({ status: 'finalized', display_status: 'finalized', number: 'STL-20261026-0001', finalized_at: '2026-10-26T12:00:00+07:00', can: { finalize: false, reopen: true, delete: false, acknowledge: true, add_payment: true, adjust: true } }),
    });

    await fireEvent.click(await screen.findByRole('button', { name: /finalkan/i }));
    expect(api.finalizeSettlement).not.toHaveBeenCalled();
    await fireEvent.click(await screen.findByRole('button', { name: /ya, lanjutkan/i }));

    await waitFor(() => expect(screen.getByTestId('settlement-number')).toHaveTextContent('STL-20261026-0001'));
    expect(api.finalizeSettlement).toHaveBeenCalledWith(12);
    expect(screen.getByRole('button', { name: /buka kembali/i })).toBeEnabled();
    expect(screen.queryByRole('button', { name: /hapus draf/i })).not.toBeInTheDocument();
  });

  it('keeps Reopen visible but disabled when the server says payments exist', async () => {
    await renderDetail(detail({
      status: 'finalized', display_status: 'partially_paid', number: 'STL-20261026-0001', acknowledged: true,
      paid_amount: '10000.00', balance: '30000.00',
      can: { finalize: false, reopen: false, delete: false, acknowledge: true, add_payment: true, adjust: true },
    }));

    expect(await screen.findByRole('button', { name: /buka kembali/i })).toBeDisabled();
    // Potongan hanya untuk draf; penyesuaian tetap tersedia setelah final.
    expect(screen.queryByRole('button', { name: /tambah potongan/i })).not.toBeInTheDocument();
    expect(screen.getByRole('button', { name: /tambah penyesuaian/i })).toBeInTheDocument();
  });

  it('shows the source-changed banner with both deltas for a finalized statement', async () => {
    await renderDetail(detail({
      status: 'finalized', display_status: 'finalized', number: 'STL-20261026-0001',
      source_changed: { pos_delta: '-20000.00', preorder_delta: '2500.00', as_of: '2026-10-26T13:00:00+07:00' },
      can: { finalize: false, reopen: true, delete: false, acknowledge: true, add_payment: true, adjust: true },
    }));

    const banner = await screen.findByTestId('source-changed');
    expect(banner).toHaveTextContent('-Rp 20.000');
    expect(banner).toHaveTextContent('Rp 2.500');
  });

  it('shows an unbalanced reconciliation clearly', async () => {
    await renderDetail(detail({
      reconciliation: { ...detail().reconciliation, difference: '1.00', balanced: false, failing: 'difference' },
    }));

    expect(await screen.findByTestId('reconciliation-state')).toHaveTextContent(/tidak seimbang/i);
    expect(screen.getByTestId('reconciliation-difference')).toHaveTextContent('Rp 1');
  });

  it('a failed load is a failure state with Retry, never an empty statement', async () => {
    api.getSettlement.mockRejectedValueOnce(new Error('boom'));
    const pinia = createPinia();
    setActivePinia(pinia);
    const router = createRouter({
      history: createMemoryHistory(),
      routes: [
        { path: '/settlements', name: 'settlements', component: { template: '<div />' } },
        { path: '/settlements/:id', name: 'settlement-detail', component: SettlementDetailView },
        { path: '/preorders', name: 'preorders', component: { template: '<div />' } },
      ],
    });
    router.push('/settlements/12');
    await router.isReady();
    render(SettlementDetailView, { global: { plugins: [pinia, router] } });

    expect(await screen.findByRole('alert')).toHaveTextContent(/gagal memuat/i);
    expect(screen.queryByTestId('net-amount')).not.toBeInTheDocument();

    api.getSettlement.mockResolvedValueOnce({ data: detail() });
    await fireEvent.click(screen.getByRole('button', { name: /coba lagi/i }));
    expect(await screen.findByTestId('net-amount')).toBeInTheDocument();
  });

  it('adding a deduction sends the typed positive amount and shows the server result', async () => {
    await renderDetail(detail({ adjustments: [], adjustments_total: '0.00', net_amount: '45000.00' }));
    api.addSettlementAdjustment.mockResolvedValue({ data: detail() });

    await fireEvent.click(await screen.findByRole('button', { name: /tambah potongan/i }));
    await fireEvent.update(screen.getByLabelText(/nama/i), 'Biaya booth');
    await fireEvent.update(screen.getByLabelText(/alasan/i), 'Bagian biaya');
    await fireEvent.update(screen.getByLabelText(/^jumlah/i), '5000');
    await fireEvent.click(screen.getByRole('button', { name: /simpan/i }));

    await waitFor(() => expect(api.addSettlementAdjustment).toHaveBeenCalledWith(12, {
      kind: 'deduction', label: 'Biaya booth', reason: 'Bagian biaya', basis: null, amount: '5000',
    }));
    expect(await screen.findByTestId('net-amount')).toHaveTextContent('Rp 40.000');
  });
});
