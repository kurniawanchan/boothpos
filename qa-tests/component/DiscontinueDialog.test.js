import { describe, it, expect, vi, beforeEach } from 'vitest';
import { render, screen, waitFor, within } from '@testing-library/vue';
import { createPinia, setActivePinia } from 'pinia';
import DiscontinueDialog from '../../resources/js/components/product/DiscontinueDialog.vue';
import { discontinueProduct } from '../../resources/js/api/products';
import { listReasonCodes } from '../../resources/js/api/reasons';
import { useSettingsStore } from '../../resources/js/stores/settings';
import { ApiError } from '../../resources/js/utils/errors';

vi.mock('../../resources/js/api/products', () => ({ soldOut: vi.fn(), discontinueProduct: vi.fn() }));
vi.mock('../../resources/js/api/reasons', () => ({ listReasonCodes: vi.fn() }));

function renderDialog({ releases = false } = {}) {
  const pinia = createPinia();
  setActivePinia(pinia);
  useSettingsStore().menuReleaseEnabled = releases;
  const handlers = { onClose: vi.fn(), onDone: vi.fn(), onConflict: vi.fn() };
  render(DiscontinueDialog, { props: { open: true, product: { id: 12, name: 'Es Jeruk' }, ...handlers }, global: { plugins: [pinia] } });
  return handlers;
}
async function userEv() {
  const { default: userEvent } = await import('@testing-library/user-event');
  return userEvent.setup();
}

// 043 (T050) — dialog Discontinue.
describe('DiscontinueDialog (043)', () => {
  beforeEach(() => {
    vi.clearAllMocks();
    listReasonCodes.mockResolvedValue({ data: [{ id: 7, code: 'seasonal', label: 'Musiman selesai' }] });
  });

  it('explains that history and reports keep the item, and only mentions the release when releases are on', async () => {
    renderDialog({ releases: false });
    await screen.findByRole('combobox'); // dialog beralasan baru dirender setelah daftar kode alasan termuat
    const dialog = screen.getByRole('dialog');
    expect(listReasonCodes).toHaveBeenCalledWith({ category: 'discontinue' });
    expect(dialog).toHaveTextContent('Item tidak dapat ditambahkan ke order baru, tetapi tetap tampil di histori dan laporan.');
    expect(dialog).not.toHaveTextContent(/rilis menu berikutnya/i);
  });

  it('adds "Berlaku setelah rilis menu berikutnya." when menu releases are enabled', async () => {
    renderDialog({ releases: true });
    await screen.findByRole('combobox');
    expect(screen.getByRole('dialog')).toHaveTextContent('Berlaku setelah rilis menu berikutnya.');
  });

  it('requires a reason, then submits reason_code_id and note and reports the result', async () => {
    discontinueProduct.mockResolvedValue({ id: 12, status: 'discontinued', change: null });
    const user = await userEv();
    const { onDone } = renderDialog();
    await screen.findByRole('combobox'); // dialog beralasan baru dirender setelah daftar kode alasan termuat
    const dialog = screen.getByRole('dialog');
    const confirm = within(dialog).getByRole('button', { name: 'Discontinue' });
    expect(confirm).toBeDisabled();
    await user.selectOptions(within(dialog).getByRole('combobox'), '7');
    await user.click(confirm);
    await waitFor(() => expect(discontinueProduct).toHaveBeenCalledWith(12, { reason_code_id: 7, note: null }));
    expect(onDone).toHaveBeenCalledWith({ id: 12, status: 'discontinued', change: null });
  });

  it('passes a pending-change response (releases on) straight through so the page can avoid flipping the badge', async () => {
    const pending = { id: 12, status: 'active', change: { id: 5, kind: 'item_discontinue', status: 'pending' } };
    discontinueProduct.mockResolvedValue(pending);
    const user = await userEv();
    const { onDone } = renderDialog({ releases: true });
    await screen.findByRole('combobox'); // dialog beralasan baru dirender setelah daftar kode alasan termuat
    const dialog = screen.getByRole('dialog');
    await user.selectOptions(within(dialog).getByRole('combobox'), '7');
    await user.click(within(dialog).getByRole('button', { name: 'Discontinue' }));
    await waitFor(() => expect(onDone).toHaveBeenCalledWith(pending));
  });

  it('on 409 it asks the page to reload instead of claiming success', async () => {
    discontinueProduct.mockRejectedValue(new ApiError('Item sudah didiskontinu.', { status: 409 }));
    const user = await userEv();
    const { onDone, onConflict } = renderDialog();
    await screen.findByRole('combobox'); // dialog beralasan baru dirender setelah daftar kode alasan termuat
    const dialog = screen.getByRole('dialog');
    await user.selectOptions(within(dialog).getByRole('combobox'), '7');
    await user.click(within(dialog).getByRole('button', { name: 'Discontinue' }));
    await waitFor(() => expect(onConflict).toHaveBeenCalled());
    expect(onDone).not.toHaveBeenCalled();
  });
});
