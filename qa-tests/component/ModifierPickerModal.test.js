import { describe, it, expect } from 'vitest';
import { render, screen, within } from '@testing-library/vue';
import userEvent from '@testing-library/user-event';
import ModifierPickerModal from '../../resources/js/components/restaurant/ModifierPickerModal.vue';

const size = { id: 1, name: 'Size', selection_type: 'single', is_required: true, min_select: 1, max_select: 1, options: [
  { id: 10, name: 'Regular', price_delta: '0.00' }, { id: 11, name: 'Large', price_delta: '5000.00' },
] };
const addons = { id: 2, name: 'Add-ons', selection_type: 'multiple', is_required: false, min_select: 0, max_select: 2, options: [
  { id: 20, name: 'Extra shot', price_delta: '8000.00' }, { id: 21, name: 'Oat milk', price_delta: '10000.00' }, { id: 22, name: 'Syrup', price_delta: '3000.00' },
] };

function renderPicker(props = {}) {
  return render(ModifierPickerModal, { props: { open: true, itemName: 'Es Kopi', basePrice: '25000.00', groups: [size, addons], ...props } });
}
const confirmButton = () => screen.getByRole('button', { name: 'Tambah ke tab' });

describe('ModifierPickerModal', () => {
  it('shows each group with its rule and each option with its signed price', () => {
    renderPicker();

    const sizeGroup = screen.getByTestId('group-1');
    expect(within(sizeGroup).getByText('Size')).toBeInTheDocument();
    expect(sizeGroup).toHaveTextContent('Wajib');
    expect(within(sizeGroup).getByText(/\+.*5\.000/)).toBeInTheDocument();
    expect(screen.getByTestId('group-2')).toHaveTextContent('Opsional');
    expect(screen.getByTestId('group-2')).toHaveTextContent('0–2');
  });

  it('keeps Add disabled until the required group has a choice', async () => {
    const user = userEvent.setup();
    renderPicker();

    expect(confirmButton()).toBeDisabled();
    await user.click(screen.getByLabelText(/regular/i));
    expect(confirmButton()).toBeEnabled();
  });

  it('shows the running unit price including the chosen differences', async () => {
    const user = userEvent.setup();
    renderPicker();

    expect(screen.getByTestId('modifier-price')).toHaveTextContent('25.000');
    await user.click(screen.getByLabelText(/large/i));
    await user.click(screen.getByLabelText(/extra shot/i));
    expect(screen.getByTestId('modifier-price')).toHaveTextContent('38.000');
  });

  it('stops offering more options once a multiple group reached its maximum', async () => {
    const user = userEvent.setup();
    renderPicker();

    await user.click(screen.getByLabelText(/extra shot/i));
    await user.click(screen.getByLabelText(/oat milk/i));

    expect(screen.getByLabelText(/syrup/i)).toBeDisabled();
    await user.click(screen.getByLabelText(/oat milk/i)); // lepas satu → terbuka lagi
    expect(screen.getByLabelText(/syrup/i)).toBeEnabled();
  });

  it('lets an optional single group be cleared by choosing the same option again', async () => {
    const user = userEvent.setup();
    const optional = { ...size, id: 3, name: 'Sugar', is_required: false };
    renderPicker({ groups: [optional] });

    expect(confirmButton()).toBeEnabled(); // opsional → sah tanpa pilihan
    await user.click(screen.getByLabelText(/large/i));
    expect(screen.getByLabelText(/large/i)).toBeChecked();
    await user.click(screen.getByLabelText(/large/i));
    expect(screen.getByLabelText(/large/i)).not.toBeChecked();
  });

  it('emits the chosen option ids and the trimmed note', async () => {
    const user = userEvent.setup();
    const { emitted } = renderPicker();

    await user.click(screen.getByLabelText(/large/i));
    await user.click(screen.getByLabelText(/extra shot/i));
    await user.type(screen.getByLabelText(/catatan untuk dapur/i), '  tanpa es  ');
    await user.click(confirmButton());

    expect(emitted().confirm[0][0]).toEqual({ modifier_option_ids: [11, 20], note: 'tanpa es' });
  });

  it('sends a null note when none was typed', async () => {
    const user = userEvent.setup();
    const { emitted } = renderPicker({ groups: [size] });

    await user.click(screen.getByLabelText(/regular/i));
    await user.click(confirmButton());

    expect(emitted().confirm[0][0]).toEqual({ modifier_option_ids: [10], note: null });
  });

  it('blocks a choice that would make the price negative and says why', async () => {
    const user = userEvent.setup();
    const promo = { id: 4, name: 'Promo', selection_type: 'single', is_required: true, min_select: 1, max_select: 1, options: [{ id: 40, name: 'Gratis', price_delta: '-30000.00' }] };
    renderPicker({ groups: [promo] });

    await user.click(screen.getByLabelText(/gratis/i));

    expect(screen.getByRole('alert')).toHaveTextContent(/menjadi negatif/i);
    expect(confirmButton()).toBeDisabled();
  });

  it('resets its choices every time it is reopened', async () => {
    const user = userEvent.setup();
    const { rerender } = renderPicker();
    await user.click(screen.getByLabelText(/large/i));
    expect(screen.getByLabelText(/large/i)).toBeChecked();

    await rerender({ open: false });
    await rerender({ open: true });

    expect(screen.getByLabelText(/large/i)).not.toBeChecked();
  });
});
