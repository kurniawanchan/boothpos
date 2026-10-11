import { describe, it, expect, vi, beforeEach } from 'vitest';
import { render, screen, waitFor, within } from '@testing-library/vue';
import { createPinia, setActivePinia } from 'pinia';
import ReleaseDialog from '../../resources/js/components/product/ReleaseDialog.vue';
import { listMenuChanges, createMenuRelease, cancelMenuChange } from '../../resources/js/api/menu';
import { useToastStore } from '../../resources/js/stores/toast';
import { ApiError } from '../../resources/js/utils/errors';

vi.mock('../../resources/js/api/menu', () => ({ listMenuChanges: vi.fn(), createMenuRelease: vi.fn(), cancelMenuChange: vi.fn() }));

const CHANGE = (id, extra = {}) => ({
  id, kind: 'price', status: 'pending', product_name: `Item ${id}`, variant_name: 'Reguler', old_price: '10000.00', new_price: '11000.00', requested_by: 'Rina', ...extra,
});
const CHANGES = { data: [CHANGE(1), CHANGE(2, { status: 'approved' }), CHANGE(3, { status: 'awaiting_approval' }), CHANGE(4, { status: 'rejected' })] };

function renderDialog() {
  const pinia = createPinia();
  setActivePinia(pinia);
  const handlers = { onClose: vi.fn(), onReleased: vi.fn(), onChanged: vi.fn() };
  const view = render(ReleaseDialog, { props: { open: true, ...handlers }, global: { plugins: [pinia] } });
  return { ...view, ...handlers };
}
async function userEv() {
  const { default: userEvent } = await import('@testing-library/user-event');
  return userEvent.setup();
}

// 043 (T066) — dialog Rilis menu.
describe('ReleaseDialog (043)', () => {
  beforeEach(() => {
    vi.clearAllMocks();
    listMenuChanges.mockResolvedValue(CHANGES);
  });

  it('lists the changes, pre-selects the ready ones and disables the ones waiting for approval or rejected', async () => {
    renderDialog();
    await screen.findAllByTestId('release-change');
    const boxes = screen.getAllByRole('checkbox');
    expect(boxes).toHaveLength(4);
    expect(boxes[0]).toBeChecked();
    expect(boxes[1]).toBeChecked();
    expect(boxes[2]).toBeDisabled();
    expect(boxes[3]).toBeDisabled();
    expect(boxes[2]).not.toBeChecked();
    expect(screen.getByText('Menunggu persetujuan')).toBeInTheDocument();
    expect(screen.getByText('Ditolak')).toBeInTheDocument();
  }, 30000);

  it('releases the chosen changes now (publish_at null) with the note, toasts and closes', async () => {
    createMenuRelease.mockResolvedValue({ id: 8, version: 5, status: 'published' });
    const user = await userEv();
    const { onReleased, onClose } = renderDialog();
    await screen.findAllByTestId('release-change');
    await user.click(screen.getAllByRole('checkbox')[1]); // lepas Item 2
    await user.type(screen.getByLabelText('Catatan (opsional)'), 'rilis pagi');
    await user.click(screen.getByRole('button', { name: 'Rilis sekarang' }));

    await waitFor(() => expect(createMenuRelease).toHaveBeenCalledWith({ publish_at: null, note: 'rilis pagi', change_ids: [1] }));
    expect(onReleased).toHaveBeenCalledWith({ id: 8, version: 5, status: 'published' });
    expect(onClose).toHaveBeenCalled();
    expect(useToastStore().items.at(-1).message).toBe('Rilis v5 dibuat');
  }, 30000);

  it('schedules a release: needs a date, then sends an ISO publish_at', async () => {
    createMenuRelease.mockResolvedValue({ id: 9, version: 6, status: 'scheduled' });
    const user = await userEv();
    renderDialog();
    await screen.findAllByTestId('release-change');
    await user.click(screen.getByRole('radio', { name: 'Jadwalkan' }));
    expect(screen.getByRole('button', { name: 'Jadwalkan rilis' })).toBeInTheDocument();

    await user.click(screen.getByRole('button', { name: 'Jadwalkan rilis' }));
    expect(await screen.findByTestId('release-error')).toHaveTextContent('Isi tanggal dan jam rilis.');
    expect(createMenuRelease).not.toHaveBeenCalled();

    await user.type(screen.getByLabelText('Tanggal dan jam rilis'), '2099-01-02T09:30');
    await user.click(screen.getByRole('button', { name: 'Jadwalkan rilis' }));
    await waitFor(() => expect(createMenuRelease).toHaveBeenCalled());
    const body = createMenuRelease.mock.calls[0][0];
    expect(body.publish_at).toMatch(/^2099-01-0[12]T\d{2}:\d{2}:00\.000Z$/);
    expect(body.change_ids).toEqual([1, 2]);
  }, 30000);

  it('explains a 409 changes_not_ready inline, reloads the list and keeps the dialog open', async () => {
    createMenuRelease.mockRejectedValue(new ApiError('Ada perubahan yang belum siap.', { status: 409, errors: { change_ids: ['Perubahan 2 belum disetujui.'] } }));
    const user = await userEv();
    const { onReleased, onClose } = renderDialog();
    await screen.findAllByTestId('release-change');
    expect(listMenuChanges).toHaveBeenCalledTimes(1);

    await user.click(screen.getByRole('button', { name: 'Rilis sekarang' }));
    expect(await screen.findByTestId('release-error')).toHaveTextContent('Perubahan 2 belum disetujui.');
    await waitFor(() => expect(listMenuChanges).toHaveBeenCalledTimes(2));
    expect(onReleased).not.toHaveBeenCalled();
    expect(onClose).not.toHaveBeenCalled();
  }, 30000);

  it('blocks submitting with nothing selected', async () => {
    const user = await userEv();
    renderDialog();
    await screen.findAllByTestId('release-change');
    await user.click(screen.getAllByRole('checkbox')[0]);
    await user.click(screen.getAllByRole('checkbox')[1]);
    await user.click(screen.getByRole('button', { name: 'Rilis sekarang' }));
    expect(await screen.findByTestId('release-error')).toHaveTextContent('Pilih minimal satu perubahan.');
    expect(createMenuRelease).not.toHaveBeenCalled();
  }, 30000);

  it('cancels a change after confirmation and reloads the list', async () => {
    cancelMenuChange.mockResolvedValue({ id: 1, status: 'cancelled' });
    const user = await userEv();
    const { onChanged } = renderDialog();
    await screen.findAllByTestId('release-change');
    await user.click(screen.getByRole('button', { name: 'Batalkan perubahan Item 1 · Reguler' }));
    const confirm = screen.getAllByRole('dialog').at(-1);
    expect(within(confirm).getByText('Batalkan perubahan ini?')).toBeInTheDocument();
    await user.click(within(confirm).getByRole('button', { name: 'Batalkan perubahan' }));
    await waitFor(() => expect(cancelMenuChange).toHaveBeenCalledWith(1));
    await waitFor(() => expect(listMenuChanges).toHaveBeenCalledTimes(2));
    expect(onChanged).toHaveBeenCalled();
  }, 30000);

  it('shows an error state with Retry (not "nothing to release") when the list fails to load', async () => {
    listMenuChanges.mockRejectedValueOnce(new ApiError('boom', { status: 500 }));
    const user = await userEv();
    renderDialog();
    expect(await screen.findByRole('alert')).toBeInTheDocument();
    expect(screen.queryByTestId('release-none')).not.toBeInTheDocument();
    await user.click(screen.getByRole('button', { name: 'Coba lagi' }));
    expect((await screen.findAllByTestId('release-change')).length).toBe(4);
  }, 30000);

  it('shows the empty message when no change is waiting and offers no release button', async () => {
    listMenuChanges.mockResolvedValue({ data: [] });
    renderDialog();
    expect(await screen.findByTestId('release-none')).toBeInTheDocument();
    expect(screen.queryByRole('button', { name: 'Rilis sekarang' })).not.toBeInTheDocument();
  }, 30000);
});
