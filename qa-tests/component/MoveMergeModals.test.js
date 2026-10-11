import { describe, it, expect, vi, beforeEach } from 'vitest';
import { render, screen, waitFor } from '@testing-library/vue';
import userEvent from '@testing-library/user-event';
import { createPinia, setActivePinia } from 'pinia';
import MoveTabModal from '../../resources/js/components/restaurant/MoveTabModal.vue';
import MergeTabModal from '../../resources/js/components/restaurant/MergeTabModal.vue';
import { getFloor } from '../../resources/js/api/floor';
import { listTabs } from '../../resources/js/api/tabs';

vi.mock('../../resources/js/api/floor', () => ({ getFloor: vi.fn() }));
vi.mock('../../resources/js/api/tabs', () => ({ listTabs: vi.fn(), getTab: vi.fn() }));

const table = (over) => ({ id: 1, name: '1', seats: 4, is_active: true, state: 'free', tab: null, ...over });

function renderWith(component, props) {
  const pinia = createPinia();
  setActivePinia(pinia);
  return render(component, { props, global: { plugins: [pinia] } });
}

describe('MoveTabModal', () => {
  beforeEach(() => vi.clearAllMocks());

  it('offers only free tables other than the current one, grouped by area', async () => {
    getFloor.mockResolvedValue({
      areas: [
        { area: 'Indoor', tables: [table({ id: 1, name: '1' }), table({ id: 2, name: '2', state: 'occupied', tab: { id: 9 } }), table({ id: 3, name: '3' })] },
        { area: '', tables: [table({ id: 4, name: 'Bar' })] },
      ],
      takeaway_tabs: [], version: 'v',
    });
    renderWith(MoveTabModal, { open: true, currentTableId: 1 });

    expect(await screen.findByRole('button', { name: 'Pindah ke meja 3' })).toBeInTheDocument();
    expect(screen.getByRole('button', { name: 'Pindah ke meja Bar' })).toBeInTheDocument();
    expect(screen.queryByRole('button', { name: 'Pindah ke meja 1' })).toBeNull(); // meja sendiri
    expect(screen.queryByRole('button', { name: 'Pindah ke meja 2' })).toBeNull(); // terisi
    expect(screen.getByRole('region', { name: 'Tanpa area' })).toBeInTheDocument();
  });

  it('reloads the floor in full every time it opens, so the list is never stale', async () => {
    getFloor.mockResolvedValue({ areas: [], takeaway_tabs: [], version: 'v' });
    const { rerender } = renderWith(MoveTabModal, { open: false, currentTableId: 1 });
    expect(getFloor).not.toHaveBeenCalled();

    await rerender({ open: true });

    await waitFor(() => expect(getFloor).toHaveBeenCalledWith(null)); // pengambilan PENUH (tanpa token versi)
  });

  it('says so when there is no free table, and emits the chosen table otherwise', async () => {
    getFloor.mockResolvedValueOnce({ areas: [{ area: 'Indoor', tables: [table({ id: 1, name: '1' })] }], takeaway_tabs: [], version: 'v' });
    const { emitted } = renderWith(MoveTabModal, { open: true, currentTableId: 1 });
    expect(await screen.findByText(/tidak ada meja kosong/i)).toBeInTheDocument();
    expect(emitted().move).toBeUndefined();
  });

  it('emits the id of the table that was clicked', async () => {
    getFloor.mockResolvedValue({ areas: [{ area: 'Indoor', tables: [table({ id: 8, name: '8' })] }], takeaway_tabs: [], version: 'v' });
    const user = userEvent.setup();
    const { emitted } = renderWith(MoveTabModal, { open: true, currentTableId: 1 });

    await user.click(await screen.findByRole('button', { name: 'Pindah ke meja 8' }));

    expect(emitted().move[0]).toEqual([8]);
  });
});

describe('MergeTabModal', () => {
  const rows = [
    { id: 7, order_number: 'TRX-7', table: { id: 1, name: '7' }, tab_label: null, total_amount: '50000.00', session_id: 3 },
    { id: 8, order_number: 'TRX-8', table: { id: 2, name: '8' }, tab_label: null, total_amount: '25000.00', session_id: 3 },
    { id: 9, order_number: 'TRX-9', table: null, tab_label: 'Budi', total_amount: '10000.00', session_id: 3 },
  ];

  beforeEach(() => {
    vi.clearAllMocks();
    listTabs.mockResolvedValue({ data: rows });
  });

  it('lists the other open tabs of the same shift, never the current one', async () => {
    renderWith(MergeTabModal, { open: true, tab: { id: 7, session_id: 3 } });

    expect(await screen.findByText('Meja 8')).toBeInTheDocument();
    expect(screen.getByText('Budi')).toBeInTheDocument();
    expect(screen.queryByText('Meja 7')).toBeNull();
    expect(listTabs).toHaveBeenCalledWith({ session_id: 3 });
  });

  it('keeps Merge disabled until a tab is chosen, then emits its id', async () => {
    const user = userEvent.setup();
    const { emitted } = renderWith(MergeTabModal, { open: true, tab: { id: 7, session_id: 3 } });
    await screen.findByText('Meja 8');

    const confirm = screen.getByRole('button', { name: 'Gabungkan' });
    expect(confirm).toBeDisabled();
    await user.click(screen.getByLabelText(/meja 8/i));
    expect(confirm).toBeEnabled();
    await user.click(confirm);

    expect(emitted().merge[0]).toEqual([8]);
  });

  it('says so when there is nothing to merge', async () => {
    listTabs.mockResolvedValue({ data: [rows[0]] });
    renderWith(MergeTabModal, { open: true, tab: { id: 7, session_id: 3 } });

    expect(await screen.findByText(/tidak ada tab lain/i)).toBeInTheDocument();
  });

  it('shows that nothing can be merged, rather than crashing, when the list cannot be loaded', async () => {
    listTabs.mockRejectedValue(new Error('offline'));
    renderWith(MergeTabModal, { open: true, tab: { id: 7, session_id: 3 } });

    expect(await screen.findByText(/tidak ada tab lain/i)).toBeInTheDocument();
  });

  it('clears the previous choice when reopened', async () => {
    const user = userEvent.setup();
    const { rerender } = renderWith(MergeTabModal, { open: true, tab: { id: 7, session_id: 3 } });
    await screen.findByText('Meja 8');
    await user.click(screen.getByLabelText(/meja 8/i));

    await rerender({ open: false });
    await rerender({ open: true });
    await screen.findByText('Meja 8');

    expect(screen.getByRole('button', { name: 'Gabungkan' })).toBeDisabled();
  });
});
