import { describe, it, expect, vi, beforeEach } from 'vitest';
import { render, screen, waitFor } from '@testing-library/vue';
import { createPinia, setActivePinia } from 'pinia';
import VariantBomModal from '../../resources/js/components/product/VariantBomModal.vue';
import { listBomLines, getCostBreakdown, addBomLine } from '../../resources/js/api/materials';
import { listMaterials } from '../../resources/js/api/materials';

vi.mock('../../resources/js/api/materials', () => ({
  listMaterials: vi.fn(),
  listBomLines: vi.fn(),
  addBomLine: vi.fn(),
  updateBomLine: vi.fn(),
  deleteBomLine: vi.fn(),
  getCostBreakdown: vi.fn(),
}));

function renderModal(props = {}) {
  const pinia = createPinia();
  setActivePinia(pinia);
  return render(VariantBomModal, {
    props: { open: true, variantId: 42, variantSku: 'ARTKACSN0001', variantName: 'Standard', ...props },
    global: { plugins: [pinia] },
  });
}

describe('VariantBomModal — cost breakdown', () => {
  beforeEach(() => {
    vi.clearAllMocks();
    listMaterials.mockResolvedValue({ data: [{ id: 1, name: 'Kain Katun', unit: 'meter', is_active: true }] });
  });

  it('shows cost_price and bom_cost side by side without conflating them', async () => {
    listBomLines.mockResolvedValue({ data: [{ id: 1, material_id: 1, material_name: 'Kain Katun', material_unit: 'meter', qty_needed: '2.0000', notes: null }] });
    getCostBreakdown.mockResolvedValue({
      product_variant_id: 42,
      sku: 'ARTKACSN0001',
      cost_price: '10000.00',
      bom_cost: '30000.00',
      lines: [
        {
          bom_line_id: 1,
          material_id: 1,
          material_name: 'Kain Katun',
          unit: 'meter',
          qty_needed: '2.0000',
          unit_cost: '15000.00',
          line_cost: '30000.00',
          has_price: true,
          reference_vendor_id: 10,
          reference_vendor_name: 'Vendor Kain',
          reference_is_preferred: true,
        },
      ],
    });
    renderModal();

    await waitFor(() => expect(screen.getAllByText('Rp 30.000').length).toBeGreaterThan(0));
    // Manual cost_price is displayed unchanged, distinct from bom_cost.
    expect(screen.getByText('Rp 10.000')).toBeInTheDocument();
    expect(screen.getByText('Vendor Kain')).toBeInTheDocument();
    expect(screen.getByText('Preferred')).toBeInTheDocument();
  });

  it('flags a BOM line with no vendor price as a visible warning, not a silent zero', async () => {
    listBomLines.mockResolvedValue({ data: [{ id: 2, material_id: 1, material_name: 'Kain Katun', material_unit: 'meter', qty_needed: '1.0000', notes: null }] });
    getCostBreakdown.mockResolvedValue({
      product_variant_id: 42,
      sku: 'ARTKACSN0001',
      cost_price: '5000.00',
      bom_cost: '0.00',
      lines: [
        {
          bom_line_id: 2,
          material_id: 1,
          material_name: 'Kain Katun',
          unit: 'meter',
          qty_needed: '1.0000',
          unit_cost: '0.00',
          line_cost: '0.00',
          has_price: false,
          reference_vendor_id: null,
          reference_vendor_name: null,
          reference_is_preferred: false,
        },
      ],
    });
    renderModal();

    expect(await screen.findByText(/belum ada harga vendor/i)).toBeInTheDocument();
  });

  it('adds a BOM line via the material select and qty input', async () => {
    const { default: userEvent } = await import('@testing-library/user-event');
    const user = userEvent.setup();
    listBomLines.mockResolvedValue({ data: [] });
    getCostBreakdown.mockResolvedValue({ product_variant_id: 42, sku: 'ARTKACSN0001', cost_price: '0.00', bom_cost: '0.00', lines: [] });
    addBomLine.mockResolvedValue({ id: 9 });
    renderModal();

    await screen.findByText(/belum ada bahan pada bom/i);
    await user.click(screen.getByRole('button', { name: /tambah bahan ke bom/i }));
    await user.click(screen.getByRole('combobox', { name: /bahan/i }));
    await user.click(screen.getByRole('option', { name: /kain katun/i }));
    await user.type(screen.getByLabelText(/jumlah dibutuhkan/i), '2');
    await user.click(screen.getByRole('button', { name: /^simpan$/i }));

    await waitFor(() => expect(addBomLine).toHaveBeenCalledWith(42, expect.objectContaining({ material_id: 1, qty_needed: '2' })));
  });
});
