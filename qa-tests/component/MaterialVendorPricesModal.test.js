import { describe, it, expect, vi, beforeEach } from 'vitest';
import { render, screen, waitFor } from '@testing-library/vue';
import { createPinia, setActivePinia } from 'pinia';
import MaterialVendorPricesModal from '../../resources/js/components/masterData/MaterialVendorPricesModal.vue';
import { getMaterial, addVendorPrice } from '../../resources/js/api/materials';
import { listVendors } from '../../resources/js/api/vendors';

vi.mock('../../resources/js/api/materials', () => ({
  getMaterial: vi.fn(),
  addVendorPrice: vi.fn(),
  updateVendorPrice: vi.fn(),
  deleteVendorPrice: vi.fn(),
}));
vi.mock('../../resources/js/api/vendors', () => ({ listVendors: vi.fn() }));

function baseMaterial(vendorPrices = []) {
  return { id: 1, code: 'BHN1', name: 'Kain Katun', unit: 'meter', vendor_prices: vendorPrices };
}

function renderModal(props = {}) {
  const pinia = createPinia();
  setActivePinia(pinia);
  return render(MaterialVendorPricesModal, {
    props: { open: true, materialId: 1, ...props },
    global: { plugins: [pinia] },
  });
}

describe('MaterialVendorPricesModal', () => {
  beforeEach(() => {
    vi.clearAllMocks();
    listVendors.mockResolvedValue({ data: [{ id: 10, name: 'Vendor Kain' }] });
  });

  it('makes the preferred vendor visually obvious with a badge', async () => {
    getMaterial.mockResolvedValue(
      baseMaterial([
        { id: 1, vendor_id: 10, vendor_name: 'Vendor Kain', price: '15000.00', is_preferred: true, notes: null },
        { id: 2, vendor_id: 11, vendor_name: 'Vendor Lain', price: '12000.00', is_preferred: false, notes: null },
      ])
    );
    renderModal();

    await screen.findByText('Vendor Kain');
    // Explanatory copy above the list also mentions the word "Preferred" —
    // the badge itself is the rounded-full pill, so scope to that.
    const badges = screen.getAllByText('Preferred').filter((el) => el.className.includes('rounded-full'));
    expect(badges).toHaveLength(1);
  });

  it('shows an empty state when the material has no vendor prices yet', async () => {
    getMaterial.mockResolvedValue(baseMaterial([]));
    renderModal();
    expect(await screen.findByText(/belum ada harga vendor/i)).toBeInTheDocument();
  });

  it('adds a vendor price and marks it preferred', async () => {
    const { default: userEvent } = await import('@testing-library/user-event');
    const user = userEvent.setup();
    getMaterial.mockResolvedValue(baseMaterial([]));
    addVendorPrice.mockResolvedValue({ id: 5 });
    renderModal();

    await screen.findByText(/belum ada harga vendor/i);
    await user.click(screen.getByRole('button', { name: /tambah harga vendor/i }));
    await user.click(screen.getByRole('combobox', { name: /vendor/i }));
    await user.click(screen.getByRole('option', { name: 'Vendor Kain' }));
    await user.type(screen.getByLabelText(/harga per unit/i), '15000');
    await user.click(screen.getByLabelText(/jadikan vendor preferred/i));
    await user.click(screen.getByRole('button', { name: /^simpan$/i }));

    await waitFor(() =>
      expect(addVendorPrice).toHaveBeenCalledWith(1, expect.objectContaining({ vendor_id: 10, is_preferred: true }))
    );
  });
});
