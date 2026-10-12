import { describe, it, expect, vi, beforeEach } from 'vitest';
import { render, screen, waitFor } from '@testing-library/vue';
import userEvent from '@testing-library/user-event';
import { createPinia, setActivePinia } from 'pinia';
import MaterialStockAdjustModal from '../../resources/js/components/masterData/MaterialStockAdjustModal.vue';
import { adjustMaterialStock } from '../../resources/js/api/materials';
import { useOutletStore } from '../../resources/js/stores/outlet';

// 045 Stage 5 (T081) — koreksi stok bahan per outlet.
vi.mock('../../resources/js/api/materials', () => ({ adjustMaterialStock: vi.fn() }));

const OUTLETS = [{ id: 1, code: 'WJ1', name: 'Warkop 1' }, { id: 2, code: 'WJ2', name: 'Warkop 2' }];

function renderModal({ multi = true, selected = null } = {}) {
  const pinia = createPinia();
  setActivePinia(pinia);
  Object.assign(useOutletStore(), { outlets: OUTLETS, multi, selected });
  return render(MaterialStockAdjustModal, { props: { open: true, material: { id: 5, name: 'Susu' } }, global: { plugins: [pinia] } });
}

describe('MaterialStockAdjustModal (045)', () => {
  beforeEach(() => { vi.clearAllMocks(); adjustMaterialStock.mockResolvedValue({}); });

  it('sends the selected outlet, the signed change and the reason', async () => {
    const user = userEvent.setup();
    const { emitted } = renderModal({ selected: 2 });
    await user.type(screen.getByLabelText(/selisih/i), '-1.5');
    await user.type(screen.getByLabelText(/alasan/i), 'tumpah');
    await user.click(screen.getByRole('button', { name: /simpan/i }));

    await waitFor(() => expect(adjustMaterialStock).toHaveBeenCalledWith(5, { outlet_id: 2, qty_change: -1.5, reason: 'tumpah' }));
    expect(emitted().saved).toBeTruthy();
  });

  it('keeps Save disabled until an outlet is chosen when there are several, and has no outlet at one outlet', async () => {
    const user = userEvent.setup();
    const { unmount } = renderModal();
    await user.type(screen.getByLabelText(/selisih/i), '2');
    await user.type(screen.getByLabelText(/alasan/i), 'opname');
    expect(screen.getByRole('button', { name: /simpan/i })).toBeDisabled();
    unmount();

    renderModal({ multi: false });
    expect(screen.queryByTestId('material-adjust-outlet')).toBeNull();
    await user.type(screen.getByLabelText(/selisih/i), '2');
    await user.type(screen.getByLabelText(/alasan/i), 'opname');
    await user.click(screen.getByRole('button', { name: /simpan/i }));
    await waitFor(() => expect(adjustMaterialStock).toHaveBeenCalledWith(5, { qty_change: 2, reason: 'opname' }));
  });
});
