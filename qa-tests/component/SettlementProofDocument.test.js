import { describe, it, expect } from 'vitest';
import { render, screen } from '@testing-library/vue';
import SettlementProofDocument from '../../resources/js/components/settlement/SettlementProofDocument.vue';
import SettlementPaymentProofDocument from '../../resources/js/components/settlement/SettlementPaymentProofDocument.vue';

// 076 (US3) — dokumen cetak. Yang dijaga: nomor & angka dari server tampil apa
// adanya, tanda tangan memakai src yang diberikan, biaya/laba hanya bila payload
// membawanya, dan tidak ada data pelanggan.
function doc(overrides = {}) {
  return {
    store_identity: { name: 'Toko Uji', logo_url: null, address: 'Jl. Contoh 1', contact_phone: null, contact_email: null },
    app_name: 'BoothPOS',
    settlement: { number: 'STL-20261026-0001', event: { name: 'Event Uji' }, seller: { name: 'Seller Uji', code: 'ABC' }, direction: 'store_pays_seller', finalized_at: '2026-10-26T12:00:00+07:00' },
    lines: {
      pos_units: 4, pos_revenue: '40000.00', preorder_units: 2, preorder_revenue: '5000.00', revenue_total: '45000.00',
      adjustments: [{ kind: 'deduction', label: 'Biaya booth', reason: 'R', basis: '40% porsi', amount: '-5000.00', post_finalization: false }],
      adjustments_total: '-5000.00', net_amount: '40000.00', paid_amount: '10000.00', balance: '30000.00',
    },
    sources: [
      { source_type: 'order', source_number: 'TRX-1', source_date: '2026-10-26T10:00:00+07:00', units: 4, gross_amount: '40000.00', amount: '40000.00' },
      { source_type: 'preorder', source_number: 'PO-1', source_date: '2026-10-26T11:00:00+07:00', units: 2, gross_amount: '20000.00', amount: '5000.00' },
    ],
    reconciliation: { difference: '0.00', balanced: true },
    payments: [{ amount: '10000.00', paid_at: '2026-10-27', method: 'bank_transfer', reference: 'TRF-1' }],
    acknowledgement: { id: 3, method: 'signature', signer_name: 'Budi Seller', note: null, has_signature: true, acknowledged_at: '2026-10-26T13:00:00+07:00' },
    memo: { preorder_uncollected: '15000.00' },
    ...overrides,
  };
}

describe('SettlementProofDocument', () => {
  it('shows number, parties, statement lines, reconciliation and the powered-by line', () => {
    render(SettlementProofDocument, { props: { doc: doc(), signatureSrc: null } });

    expect(screen.getByTestId('doc-number')).toHaveTextContent('STL-20261026-0001');
    expect(screen.getByText('Seller Uji')).toBeInTheDocument();
    expect(screen.getByText('Event Uji')).toBeInTheDocument();
    expect(screen.getByTestId('doc-net')).toHaveTextContent('Rp 40.000');
    expect(screen.getByTestId('doc-adjustment')).toHaveTextContent('Biaya booth');
    expect(screen.getByTestId('doc-adjustment')).toHaveTextContent('40% porsi');
    expect(screen.getByText('TRX-1')).toBeInTheDocument();
    expect(screen.getByText(/seimbang/i)).toBeInTheDocument();
    expect(screen.getByText(/Powered by BoothPOS/)).toBeInTheDocument();
  });

  it('uses the given data URL as the signature image, keyed by its own src', () => {
    const src = 'data:image/png;base64,AAAA';
    render(SettlementProofDocument, { props: { doc: doc(), signatureSrc: src } });

    const img = screen.getByRole('img', { name: /tanda tangan seller/i });
    expect(img.getAttribute('src')).toBe(src);
    expect(screen.getByText('Budi Seller')).toBeInTheDocument();
  });

  it('omits cost of goods and gross profit unless the payload carries them', () => {
    const { unmount } = render(SettlementProofDocument, { props: { doc: doc() } });
    expect(screen.queryByTestId('doc-cost')).not.toBeInTheDocument();
    unmount();

    render(SettlementProofDocument, { props: { doc: doc({ memo: { preorder_uncollected: '0.00', cost_of_goods: '18000.00', gross_profit: '27000.00' } }) } });
    expect(screen.getByTestId('doc-cost')).toHaveTextContent('Rp 18.000');
  });

  it('labels an acknowledgement recorded outside the system', () => {
    render(SettlementProofDocument, {
      props: { doc: doc({ acknowledgement: { id: 4, method: 'external', signer_name: null, note: 'Setuju via WhatsApp', has_signature: false, acknowledged_at: '2026-10-26T13:00:00+07:00' } }) },
    });

    expect(screen.getByTestId('doc-ack')).toHaveTextContent(/di luar sistem/i);
    expect(screen.getByTestId('doc-ack')).toHaveTextContent('Setuju via WhatsApp');
  });

  it('shows blank signature lines and "not yet acknowledged" when there is no acknowledgement', () => {
    render(SettlementProofDocument, { props: { doc: doc({ acknowledgement: null }) } });

    expect(screen.getByTestId('doc-ack')).toHaveTextContent(/belum diakui seller/i);
    expect(screen.queryByRole('img', { name: /tanda tangan seller/i })).not.toBeInTheDocument();
  });

  it('never renders customer wording', () => {
    render(SettlementProofDocument, { props: { doc: doc() } });

    expect(document.body.textContent).not.toMatch(/pelanggan|customer|telepon|email/i);
  });

  it('writes the direction when the seller pays the store', () => {
    render(SettlementProofDocument, {
      props: { doc: doc({ settlement: { ...doc().settlement, direction: 'seller_pays_store' }, lines: { ...doc().lines, net_amount: '-2000.00', balance: '2000.00' } }) },
    });

    expect(screen.getByText(/penjual membayar toko/i)).toBeInTheDocument();
    expect(screen.getByTestId('doc-net')).toHaveTextContent('-Rp 2.000');
  });
});

describe('SettlementPaymentProofDocument', () => {
  const paymentDoc = {
    store_identity: { name: 'Toko Uji', logo_url: null },
    app_name: 'BoothPOS',
    settlement: { number: 'STL-20261026-0001', event: { name: 'Event Uji' }, seller: { name: 'Seller Uji' }, direction: 'store_pays_seller', net_amount: '30000.00' },
    payment: { id: 9, amount: '10000.00', paid_at: '2026-10-27', method: 'bank_transfer', reference: 'TRF-1', notes: null, has_proof: true, recorded_by_name: 'Owner' },
    total_paid_after: '10000.00',
    balance_after: '20000.00',
  };

  it('shows the same settlement number, the amount, and the balance after this payment', () => {
    render(SettlementPaymentProofDocument, { props: { doc: paymentDoc } });

    expect(screen.getByTestId('doc-number')).toHaveTextContent('STL-20261026-0001');
    expect(screen.getByTestId('doc-amount')).toHaveTextContent('Rp 10.000');
    expect(screen.getByTestId('doc-balance-after')).toHaveTextContent('Rp 20.000');
    expect(screen.getByText(/dibayarkan ke penjual/i)).toBeInTheDocument();
    expect(screen.getByText('TRF-1')).toBeInTheDocument();
  });

  it('uses the received-from-seller wording when the seller pays the store', () => {
    render(SettlementPaymentProofDocument, {
      props: { doc: { ...paymentDoc, settlement: { ...paymentDoc.settlement, direction: 'seller_pays_store', net_amount: '-2000.00' } } },
    });

    expect(screen.getByText(/diterima dari penjual/i)).toBeInTheDocument();
  });
});
