import { describe, it, expect, vi, beforeEach } from 'vitest';
import { render, screen } from '@testing-library/vue';
import { createPinia, setActivePinia } from 'pinia';
import PosCartPanel from '../../resources/js/components/pos/PosCartPanel.vue';
import PosPaymentModal from '../../resources/js/components/payment/PosPaymentModal.vue';
import { usePosCartStore } from '../../resources/js/stores/posCart';
import { useSettingsStore } from '../../resources/js/stores/settings';

vi.mock('../../resources/js/api/payments', () => ({
  listPaymentChannels: vi.fn().mockResolvedValue({ data: [] }),
  uploadPaymentProof: vi.fn(),
}));

function renderCart({ rate = 10, label = 'PPN', show = true } = {}) {
  const pinia = createPinia();
  setActivePinia(pinia);
  Object.assign(useSettingsStore(pinia), { taxRate: rate, taxLabel: label, taxShow: show });
  usePosCartStore(pinia).add({ variant_id: 1, sku: 'SKU1', name: 'Kopi', sell_price: '7000.00', current_stock: 10 });

  return render(PosCartPanel, { global: { plugins: [pinia] } });
}

function renderPayment(over = {}) {
  const pinia = createPinia();
  setActivePinia(pinia);

  return render(PosPaymentModal, {
    props: {
      open: true,
      lines: [{ key: 1, name: 'Kopi', qty: 1, lineTotal: '7000.00' }],
      subtotal: '7000.00', discountAmount: '0.00', total: '7700.00',
      taxAmount: '700.00', taxRate: 10, taxLabel: 'PPN',
      ...over,
    },
    global: { plugins: [pinia] },
  });
}

// 083 / US2 — baris pajak mengikuti toggle. Catatan bertanggal 2026-10-31 (perintah pemilik, membalik 083):
// toggle mati = pajak TIDAK dihitung, jadi total = dasar tanpa pajak.
describe('keranjang POS', () => {
  beforeEach(() => vi.clearAllMocks());

  it('menampilkan baris pajak dan total 7.700 saat tampil aktif', async () => {
    renderCart();
    await Promise.resolve();

    expect(screen.getByText('PPN (10%)')).toBeInTheDocument();
    expect(screen.getAllByText('Rp 7.700').length).toBeGreaterThan(0);
  });

  it('tampil mati: baris pajak hilang DAN pajak tidak dihitung (total 7.000)', async () => {
    renderCart({ show: false });
    await Promise.resolve();

    expect(screen.queryByText(/PPN/)).not.toBeInTheDocument();
    expect(screen.queryByText('Rp 7.700')).not.toBeInTheDocument();
    expect(screen.getAllByText('Rp 7.000').length).toBeGreaterThan(0);
  });

  it('tarif 0 tidak menampilkan baris walau tampil aktif', async () => {
    renderCart({ rate: 0 });
    await Promise.resolve();

    expect(screen.queryByText(/PPN/)).not.toBeInTheDocument();
    expect(screen.getAllByText('Rp 7.000').length).toBeGreaterThan(0);
  });
});

describe('modal pembayaran POS', () => {
  it('menampilkan baris pajak secara bawaan', () => {
    renderPayment();

    expect(screen.getByText('PPN (10%)')).toBeInTheDocument();
    expect(screen.getAllByText('Rp 7.700').length).toBeGreaterThan(0);
  });

  // Modal hanya MENAMPILKAN total dari PosView (prop); bila pajak ditampilkan mati, baris pajak tidak muncul.
  it('menyembunyikan baris pajak; total adalah prop dari PosView', () => {
    renderPayment({ taxShow: false });

    expect(screen.queryByText(/PPN/)).not.toBeInTheDocument();
    expect(screen.getAllByText('Rp 7.700').length).toBeGreaterThan(0);
  });
});
