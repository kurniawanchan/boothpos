import { describe, it, expect } from 'vitest';
import { render, screen } from '@testing-library/vue';
import userEvent from '@testing-library/user-event';
import ReasonDialog from '../../resources/js/components/restaurant/ReasonDialog.vue';

function renderDialog(props = {}) {
  return render(ReasonDialog, { props: { open: true, title: 'Batalkan item', message: 'Yakin?', confirmLabel: 'Batalkan item', ...props } });
}
const confirm = () => screen.getByRole('button', { name: 'Batalkan item' });

describe('ReasonDialog', () => {
  it('shows the title and message', () => {
    renderDialog();

    expect(screen.getByText('Yakin?')).toBeInTheDocument();
    expect(screen.getByRole('dialog')).toBeInTheDocument();
  });

  it('keeps the confirm button disabled while the reason is empty or only spaces', async () => {
    const user = userEvent.setup();
    renderDialog();

    expect(confirm()).toBeDisabled();
    await user.type(screen.getByLabelText(/alasan/i), '   ');
    expect(confirm()).toBeDisabled();
    await user.type(screen.getByLabelText(/alasan/i), 'salah pesan');
    expect(confirm()).toBeEnabled();
  });

  it('emits the TRIMMED reason on confirm', async () => {
    const user = userEvent.setup();
    const { emitted } = renderDialog();

    await user.type(screen.getByLabelText(/alasan/i), '  pelanggan batal  ');
    await user.click(confirm());

    expect(emitted().confirm[0]).toEqual(['pelanggan batal']);
  });

  it('emits close when cancelled and never confirms', async () => {
    const user = userEvent.setup();
    const { emitted } = renderDialog();

    await user.type(screen.getByLabelText(/alasan/i), 'x');
    await user.click(screen.getByRole('button', { name: 'Batal' }));

    expect(emitted().close).toBeTruthy();
    expect(emitted().confirm).toBeUndefined();
  });

  it('starts empty every time it is reopened', async () => {
    const user = userEvent.setup();
    const { rerender } = renderDialog();
    await user.type(screen.getByLabelText(/alasan/i), 'sisa alasan lama');

    await rerender({ open: false });
    await rerender({ open: true });

    expect(screen.getByLabelText(/alasan/i)).toHaveValue('');
    expect(confirm()).toBeDisabled();
  });

  it('limits the reason to 255 characters, like the server', () => {
    renderDialog();

    expect(screen.getByLabelText(/alasan/i)).toHaveAttribute('maxlength', '255');
  });
});
