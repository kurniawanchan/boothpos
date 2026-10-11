import { describe, it, expect, vi, beforeEach } from 'vitest';
import { render, screen, waitFor, within } from '@testing-library/vue';
import { createPinia, setActivePinia } from 'pinia';
import SoldOutDialog from '../../resources/js/components/product/SoldOutDialog.vue';
import { soldOut } from '../../resources/js/api/products';
import { listReasonCodes } from '../../resources/js/api/reasons';
import { useToastStore } from '../../resources/js/stores/toast';
import { ApiError } from '../../resources/js/utils/errors';

vi.mock('../../resources/js/api/products', () => ({ soldOut: vi.fn(), discontinueProduct: vi.fn() }));
vi.mock('../../resources/js/api/reasons', () => ({ listReasonCodes: vi.fn() }));

const REASONS = { data: [{ id: 3, code: 'out_of_stock', label: 'Bahan habis' }, { id: 4, code: 'equipment', label: 'Alat rusak' }] };

function renderDialog(props = {}) {
  const pinia = createPinia();
  setActivePinia(pinia);
  const handlers = { onClose: vi.fn(), onDone: vi.fn(), onConflict: vi.fn() };
  const view = render(SoldOutDialog, { props: { open: true, product: { id: 12, name: 'Es Jeruk' }, ...handlers, ...props }, global: { plugins: [pinia] } });
  return { ...view, ...handlers };
}
async function userEv() {
  const { default: userEvent } = await import('@testing-library/user-event');
  return userEvent.setup();
}

// 043 (T044) — dialog Tandai 86.
describe('SoldOutDialog (043)', () => {
  beforeEach(() => {
    vi.clearAllMocks();
    listReasonCodes.mockResolvedValue(REASONS);
  });

  it('asks the sold_out reason codes and states only what the system does (5 seconds, until the business day ends)', async () => {
    renderDialog();
    await screen.findByRole('combobox'); // dialog beralasan baru dirender setelah daftar kode alasan termuat
    const dialog = screen.getByRole('dialog');
    expect(listReasonCodes).toHaveBeenCalledWith({ category: 'sold_out' });
    expect(within(dialog).getByText('Tandai Es Jeruk sold out?')).toBeInTheDocument();
    expect(dialog).toHaveTextContent(/sekitar 5 detik/);
    expect(dialog).toHaveTextContent(/hari bisnis berakhir/);
    expect(dialog.textContent).not.toMatch(/online|gofood|grab|shopee/i); // tidak ada janji kanal online
  });

  it('keeps "Tandai 86" disabled until a reason is chosen, then submits reason_code_id and the note', async () => {
    soldOut.mockResolvedValue({ id: 12 });
    const user = await userEv();
    const { onDone } = renderDialog();
    await screen.findByRole('combobox'); // dialog beralasan baru dirender setelah daftar kode alasan termuat
    const dialog = screen.getByRole('dialog');
    const confirm = within(dialog).getByRole('button', { name: 'Tandai 86' });
    expect(confirm).toBeDisabled();

    await user.selectOptions(within(dialog).getByRole('combobox'), '3');
    await user.type(within(dialog).getByRole('textbox'), 'susu habis');
    expect(confirm).toBeEnabled();
    await user.click(confirm);

    await waitFor(() => expect(soldOut).toHaveBeenCalledWith(12, { reason_code_id: 3, note: 'susu habis' }));
    expect(onDone).toHaveBeenCalled();
  });

  it('on 409 (already 86) it does not report success but asks the page to reload via `conflict`', async () => {
    soldOut.mockRejectedValue(new ApiError('Item sudah ditandai 86.', { status: 409 }));
    const user = await userEv();
    const { onDone, onConflict } = renderDialog();
    await screen.findByRole('combobox'); // dialog beralasan baru dirender setelah daftar kode alasan termuat
    const dialog = screen.getByRole('dialog');
    await user.selectOptions(within(dialog).getByRole('combobox'), '4');
    await user.click(within(dialog).getByRole('button', { name: 'Tandai 86' }));
    await waitFor(() => expect(onConflict).toHaveBeenCalled());
    expect(onDone).not.toHaveBeenCalled();
  });

  it('on 422 it shows the server message and stays open', async () => {
    soldOut.mockRejectedValue(new ApiError('Alasan wajib.', { status: 422, errors: { reason_code_id: ['Alasan wajib diisi.'] } }));
    const user = await userEv();
    const { onDone, onConflict } = renderDialog();
    await screen.findByRole('combobox'); // dialog beralasan baru dirender setelah daftar kode alasan termuat
    const dialog = screen.getByRole('dialog');
    await user.selectOptions(within(dialog).getByRole('combobox'), '3');
    await user.click(within(dialog).getByRole('button', { name: 'Tandai 86' }));
    await waitFor(() => expect(useToastStore().items.map((i) => i.message)).toContain('Alasan wajib diisi.'));
    expect(onDone).not.toHaveBeenCalled();
    expect(onConflict).not.toHaveBeenCalled();
    expect(screen.getByRole('dialog')).toBeInTheDocument();
  });

  it('never falls back to a free-text reason: a failed reason list shows an error + Retry', async () => {
    listReasonCodes.mockRejectedValueOnce(new Error('boom'));
    const user = await userEv();
    renderDialog();
    const dialog = await screen.findByRole('dialog');
    expect(await within(dialog).findByRole('alert')).toBeInTheDocument();
    expect(within(dialog).queryByRole('textbox')).not.toBeInTheDocument();
    await user.click(within(dialog).getByRole('button', { name: /coba lagi/i }));
    expect(await within(screen.getByRole('dialog')).findByRole('combobox')).toBeInTheDocument();
  });

  it('says so when no reason codes exist (no free-text fallback)', async () => {
    listReasonCodes.mockResolvedValue({ data: [] });
    renderDialog();
    expect(await screen.findByTestId('no-reasons')).toBeInTheDocument();
    expect(screen.queryByRole('textbox')).not.toBeInTheDocument();
  });

  it('emits close from Batal', async () => {
    const user = await userEv();
    const { onClose } = renderDialog();
    await screen.findByRole('combobox'); // dialog beralasan baru dirender setelah daftar kode alasan termuat
    const dialog = screen.getByRole('dialog');
    await within(dialog).findByRole('combobox');
    await user.click(within(dialog).getByRole('button', { name: 'Batal' }));
    expect(onClose).toHaveBeenCalled();
  });
});
