import { describe, it, expect, vi, beforeEach } from 'vitest';
import { render, screen, waitFor } from '@testing-library/vue';
import SetPinModal from '../../resources/js/components/users/SetPinModal.vue';
import { setUserPin, suggestPin } from '../../resources/js/api/users';

vi.mock('../../resources/js/api/users', () => ({
  setUserPin: vi.fn(),
  suggestPin: vi.fn(),
}));

const cashier = { id: 2, name: 'Kasir Satu', has_pin: false };

function renderModal(user = cashier) {
  return render(SetPinModal, { props: { open: true, user } });
}

async function userSetup() {
  const { default: userEvent } = await import('@testing-library/user-event');
  return userEvent.setup();
}

async function typePin(user, value) {
  const input = screen.getByTestId('pin-input');
  await user.clear(input);
  await user.type(input, value);
  await waitFor(() => expect(input).toHaveValue(value.replace(/\D/g, '').slice(0, 6)));
}

describe('SetPinModal (074)', () => {
  beforeEach(() => {
    vi.clearAllMocks();
    setUserPin.mockResolvedValue({ id: 2, name: 'Kasir Satu', has_pin: true, pin_locked: false });
  });

  it('masks the PIN, keeps only digits and stops at 6', async () => {
    const user = await userSetup();
    renderModal();

    const input = screen.getByTestId('pin-input');
    expect(input).toHaveAttribute('type', 'password');
    await typePin(user, 'a4b8c2916789');
    expect(input).toHaveValue('482916');
  });

  it('keeps Save disabled until there are 6 digits', async () => {
    const user = await userSetup();
    renderModal();

    expect(screen.getByTestId('pin-save')).toBeDisabled();
    await typePin(user, '48291');
    expect(screen.getByTestId('pin-save')).toBeDisabled();
    await typePin(user, '482916');
    expect(screen.getByTestId('pin-save')).toBeEnabled();
  });

  it('saves a typed PIN with source "typed", emits saved and never shows the PIN again', async () => {
    const user = await userSetup();
    const { emitted } = renderModal();

    await typePin(user, '482916');
    await user.click(screen.getByTestId('pin-save'));

    await waitFor(() => expect(setUserPin).toHaveBeenCalledWith(2, '482916', 'typed'));
    expect(await screen.findByTestId('pin-saved')).toBeInTheDocument();
    expect(emitted().saved).toHaveLength(1);
    expect(screen.queryByTestId('pin-kept-box')).not.toBeInTheDocument();
    expect(screen.queryByTestId('pin-input')).not.toBeInTheDocument();
    expect(document.body.textContent).not.toContain('482916');
  });

  it('shows a generated PIN once, saves it with source "generated" and keeps it readable until the dialog is closed', async () => {
    const user = await userSetup();
    suggestPin.mockResolvedValue({ pin: '739204' });
    const { emitted } = renderModal();

    await user.click(screen.getByTestId('pin-generate'));
    expect(await screen.findByTestId('pin-generated')).toHaveTextContent('739204');
    expect(screen.getByTestId('pin-input')).toHaveValue('739204');
    expect(setUserPin).not.toHaveBeenCalled(); // tidak tersimpan sebelum admin menekan Simpan

    await user.click(screen.getByTestId('pin-save'));
    await waitFor(() => expect(setUserPin).toHaveBeenCalledWith(2, '739204', 'generated'));
    expect(await screen.findByTestId('pin-kept')).toHaveTextContent('739204');

    await user.click(screen.getByTestId('pin-close'));
    expect(emitted().close).toHaveLength(1);
    expect(document.body.textContent).not.toContain('739204');
  });

  it('wipes everything when the dialog is reopened', async () => {
    const user = await userSetup();
    suggestPin.mockResolvedValue({ pin: '739204' });
    const { rerender } = renderModal();

    await user.click(screen.getByTestId('pin-generate'));
    await screen.findByTestId('pin-generated');
    await rerender({ open: false, user: cashier });
    await rerender({ open: true, user: cashier });

    expect(document.body.textContent).not.toContain('739204');
    expect(screen.getByTestId('pin-input')).toHaveValue('');
    expect(screen.queryByTestId('pin-generated-box')).not.toBeInTheDocument();
  });

  it('reports source "typed" when a generated PIN is edited before saving', async () => {
    const user = await userSetup();
    suggestPin.mockResolvedValue({ pin: '739204' });
    renderModal();

    await user.click(screen.getByTestId('pin-generate'));
    await screen.findByTestId('pin-generated');
    await typePin(user, '518273');
    await user.click(screen.getByTestId('pin-save'));

    await waitFor(() => expect(setUserPin).toHaveBeenCalledWith(2, '518273', 'typed'));
  });

  it('shows the server message inline and stays open when the PIN is refused', async () => {
    const user = await userSetup();
    setUserPin.mockRejectedValue(Object.assign(new Error('PIN terlalu mudah ditebak.'), { isValidation: true, errors: null }));
    const { emitted } = renderModal();

    await typePin(user, '111111');
    await user.click(screen.getByTestId('pin-save'));

    expect(await screen.findByText('PIN terlalu mudah ditebak.')).toBeInTheDocument();
    expect(emitted().saved).toBeUndefined();
    expect(screen.getByTestId('pin-input')).toBeInTheDocument();
  });

  it('shows a message when generating fails and saves nothing', async () => {
    const user = await userSetup();
    suggestPin.mockRejectedValue(new Error('Server tidak terjangkau.'));
    renderModal();

    await user.click(screen.getByTestId('pin-generate'));

    expect(await screen.findByText('Server tidak terjangkau.')).toBeInTheDocument();
    expect(screen.queryByTestId('pin-generated-box')).not.toBeInTheDocument();
    expect(setUserPin).not.toHaveBeenCalled();
  });

  it('titles the dialog "Atur PIN" for a user without a PIN and "Reset PIN" (with a hint) for one who has it', () => {
    const { unmount } = renderModal();
    expect(screen.getByRole('dialog', { name: /atur pin — kasir satu/i })).toBeInTheDocument();
    expect(screen.queryByTestId('pin-replace-hint')).not.toBeInTheDocument();
    unmount();

    renderModal({ ...cashier, has_pin: true });
    expect(screen.getByRole('dialog', { name: /reset pin — kasir satu/i })).toBeInTheDocument();
    expect(screen.getByTestId('pin-replace-hint')).toBeInTheDocument();
  });
});
