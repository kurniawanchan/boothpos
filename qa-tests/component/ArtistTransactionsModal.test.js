import { describe, it, expect, vi, beforeEach } from 'vitest';
import { render, screen, waitFor } from '@testing-library/vue';
import { createPinia, setActivePinia } from 'pinia';
import ArtistTransactionsModal from '../../resources/js/components/report/ArtistTransactionsModal.vue';
import { artistSettlementTransactions } from '../../resources/js/api/reports';

vi.mock('../../resources/js/api/reports', () => ({
  artistSettlementTransactions: vi.fn(),
}));

// 012-seller-preorder-report-detail-export (US1/T005) — the seller
// transaction-detail drilldown now renders a MERGED list of order- and
// preorder-sourced entries (FR-001..FR-004), each shaped
// {key, number, source: 'order'|'preorder', created_at, items, amount_for_artist}
// so a preorder's collected-for-this-seller amount is traceable alongside
// regular sales, not just folded into the Seller Recap aggregate.
const MIXED_RESPONSE = {
  transactions: [
    {
      key: 'order-201',
      number: 'ORD-0201',
      source: 'order',
      created_at: '2026-09-01T10:00:00Z',
      items: [{ sku: 'KC-001', name: 'Keychain A', qty: 2, line_total: '30000.00' }],
      amount_for_artist: '30000.00',
    },
    {
      key: 'preorder-55',
      number: 'PO-0055',
      source: 'preorder',
      created_at: '2026-09-02T11:00:00Z',
      items: [{ sku: 'KC-002', name: 'Keychain B', qty: 1, line_total: '15000.00' }],
      amount_for_artist: '15000.00',
    },
  ],
};

function renderModal(props) {
  const pinia = createPinia();
  setActivePinia(pinia);
  return render(ArtistTransactionsModal, {
    props: {
      open: true,
      artistId: 5,
      artistName: 'Artist Uno',
      eventId: 1,
      ...props,
    },
    global: { plugins: [pinia] },
  });
}

describe('ArtistTransactionsModal — merged order + preorder transaction detail', () => {
  beforeEach(() => vi.clearAllMocks());

  it('renders both an order-sourced and a preorder-sourced entry, each with its own number/date/items/amount', async () => {
    artistSettlementTransactions.mockResolvedValue(MIXED_RESPONSE);

    renderModal();

    await waitFor(() => expect(artistSettlementTransactions).toHaveBeenCalledWith(5, 1));

    // Order entry
    expect(await screen.findByText('ORD-0201')).toBeInTheDocument();
    expect(screen.getByText('KC-001')).toBeInTheDocument();
    expect(screen.getByText('Keychain A')).toBeInTheDocument();
    expect(screen.getAllByText('Rp 30.000').length).toBeGreaterThan(0);

    // Preorder entry
    expect(screen.getByText('PO-0055')).toBeInTheDocument();
    expect(screen.getByText('KC-002')).toBeInTheDocument();
    expect(screen.getByText('Keychain B')).toBeInTheDocument();
    expect(screen.getAllByText('Rp 15.000').length).toBeGreaterThan(0);
  });

  it('shows no Vue key-collision warning when both sources are rendered together', async () => {
    const warnSpy = vi.spyOn(console, 'warn').mockImplementation(() => {});
    artistSettlementTransactions.mockResolvedValue(MIXED_RESPONSE);

    renderModal();
    await screen.findByText('ORD-0201');

    const duplicateKeyWarning = warnSpy.mock.calls.some((args) =>
      args.some((arg) => typeof arg === 'string' && arg.toLowerCase().includes('duplicate key'))
    );
    expect(duplicateKeyWarning).toBe(false);
    warnSpy.mockRestore();
  });

  it('labels each entry with the type badge matching its source', async () => {
    artistSettlementTransactions.mockResolvedValue(MIXED_RESPONSE);

    renderModal();
    await screen.findByText('ORD-0201');
    await waitFor(() => expect(screen.getByText('PO-0055')).toBeInTheDocument());

    expect(screen.getByText('Penjualan')).toBeInTheDocument();
    expect(screen.getByText('Pre-order')).toBeInTheDocument();
  });

  it('shows an empty state when there are no contributing transactions', async () => {
    artistSettlementTransactions.mockResolvedValue({ transactions: [] });

    renderModal();

    expect(await screen.findByText(/belum ada transaksi yang menyumbang/i)).toBeInTheDocument();
  });
});
