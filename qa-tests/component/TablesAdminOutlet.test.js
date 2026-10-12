import { describe, it, expect, vi, beforeEach } from 'vitest';
import { render, screen, waitFor } from '@testing-library/vue';
import userEvent from '@testing-library/user-event';
import { createPinia, setActivePinia } from 'pinia';
import TablesAdmin from '../../resources/js/components/restaurant/TablesAdmin.vue';
import { listDiningTables, createDiningTable } from '../../resources/js/api/diningTables';
import { useOutletStore } from '../../resources/js/stores/outlet';

// 045 Stage 2 — meja baru menyebut outletnya bila ada lebih dari satu; di satu outlet kolomnya tidak ada.
vi.mock('../../resources/js/api/diningTables', () => ({
  listDiningTables: vi.fn(), createDiningTable: vi.fn(), updateDiningTable: vi.fn(), deleteDiningTable: vi.fn(),
}));

const TWO = [{ id: 1, code: 'WJ1', name: 'Warkop WJM 1' }, { id: 2, code: 'WJ2', name: 'Warkop WJM 2' }];

function renderAdmin(outlets, selected = null) {
  const pinia = createPinia();
  setActivePinia(pinia);
  const store = useOutletStore();
  store.outlets = outlets;
  store.multi = outlets.length > 1;
  store.selected = selected;
  return render(TablesAdmin, { global: { plugins: [pinia] } });
}

describe('TablesAdmin outlet field (045 Stage 2)', () => {
  beforeEach(() => {
    vi.clearAllMocks();
    listDiningTables.mockResolvedValue({ data: [] });
    createDiningTable.mockResolvedValue({ id: 9 });
  });

  it('sends the chosen outlet when several are visible', async () => {
    const user = userEvent.setup();
    renderAdmin(TWO, 2);

    await user.click(await screen.findByRole('button', { name: /meja baru/i }));
    await user.type(await screen.findByLabelText(/nama \/ nomor meja/i), 'T1');
    await user.click(screen.getByRole('button', { name: 'Simpan' }));

    await waitFor(() => expect(createDiningTable).toHaveBeenCalled());
    expect(createDiningTable.mock.calls[0][0].outlet_id).toBe(2);
  });

  it('hides the field and sends no outlet at a single outlet', async () => {
    const user = userEvent.setup();
    renderAdmin([TWO[0]]);

    await user.click(await screen.findByRole('button', { name: /meja baru/i }));
    await user.type(await screen.findByLabelText(/nama \/ nomor meja/i), 'T1');
    expect(screen.queryByLabelText(/^outlet/i)).not.toBeInTheDocument();
    await user.click(screen.getByRole('button', { name: 'Simpan' }));

    await waitFor(() => expect(createDiningTable).toHaveBeenCalled());
    expect(createDiningTable.mock.calls[0][0]).not.toHaveProperty('outlet_id');
  });
});
