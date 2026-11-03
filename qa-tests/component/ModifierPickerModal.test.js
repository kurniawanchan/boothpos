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

    expect(emitted().confirm[0][0]).toEqual({ modifier_option_ids: [11, 20], modifier_quantities: { 11: 1, 20: 1 }, note: 'tanpa es' });
  });

  it('sends a null note when none was typed', async () => {
    const user = userEvent.setup();
    const { emitted } = renderPicker({ groups: [size] });

    await user.click(screen.getByLabelText(/regular/i));
    await user.click(confirmButton());

    expect(emitted().confirm[0][0]).toEqual({ modifier_option_ids: [10], modifier_quantities: { 10: 1 }, note: null });
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

// 2026-10-31 (permintaan pemilik) — stok bahan per pilihan dan pemakaiannya; grup "tiap pilihan = 1 porsi".
describe('ModifierPickerModal — material stock and portions', () => {
  const rasa = { id: 5, name: 'Rasa (Indomie)', selection_type: 'multiple', is_required: true, min_select: 1, max_select: 2, portion_per_option: true, options: [
    { id: 50, name: 'SOTO', price_delta: '0.00', portions: 17, materials: [{ material_id: 9, name: 'Indomie Soto', unit: 'pcs', quantity: '1.0000', on_hand: '17.000' }] },
    { id: 51, name: 'AYAM BAWANG', price_delta: '0.00', portions: 0, materials: [{ material_id: 10, name: 'Indomie Ayam Bawang', unit: 'pcs', quantity: '1.0000', on_hand: '-1.000' }] },
  ] };
  const render2 = () => render(ModifierPickerModal, { props: { open: true, itemName: 'Indomie Kuah', basePrice: '8000.00', groups: [rasa] } });

  it('shows no stock or usage line under an option', () => {
    render2();
    expect(screen.queryByTestId('option-stock-50')).not.toBeInTheDocument();
    expect(screen.queryByText(/stok|pakai/i)).not.toBeInTheDocument();
  });

  // 2026-11-02 — grup "pilih 2": satu pilihan ×2 sudah cukup; dua pilihan berbeda mengunci jumlah dan pilihan lain.
  describe('group limits', () => {
    const pilih2 = { id: 7, name: 'Rasa', selection_type: 'multiple', is_required: true, min_select: 2, max_select: 2, options: [
      { id: 70, name: 'SOTO', price_delta: '0.00', min_qty: 1, max_qty: 2 },
      { id: 71, name: 'AYAM', price_delta: '0.00', min_qty: 1, max_qty: 2 },
      { id: 72, name: 'KARI', price_delta: '0.00', min_qty: 1, max_qty: 2 },
    ] };
    const open2 = () => render(ModifierPickerModal, { props: { open: true, itemName: 'Indomie Double', basePrice: '8000.00', groups: [pilih2] } });

    it('lets one option at the maximum quantity complete the group and blocks the other options', async () => {
      const user = userEvent.setup();
      open2();
      await user.click(screen.getByLabelText(/^SOTO/));
      expect(confirmButton()).toBeDisabled(); // 1 satuan < pilih 2
      await user.click(screen.getByRole('button', { name: /tambah jumlah soto/i }));
      expect(screen.getByTestId('qty-value-70')).toHaveTextContent('2');
      expect(confirmButton()).toBeEnabled();
      expect(screen.getByRole('button', { name: /tambah jumlah soto/i })).toBeDisabled();
      expect(screen.getByLabelText(/^AYAM/)).toBeDisabled();
    });

    it('resets every quantity to 1 and locks the rest when the distinct options reach the maximum, then unlocks on release', async () => {
      const user = userEvent.setup();
      open2();
      await user.click(screen.getByLabelText(/^SOTO/));
      await user.click(screen.getByLabelText(/^AYAM/));

      expect(screen.getByTestId('qty-value-70')).toHaveTextContent('1');
      expect(screen.getByTestId('qty-value-71')).toHaveTextContent('1');
      expect(screen.getByRole('button', { name: /tambah jumlah soto/i })).toBeDisabled();
      expect(screen.getByRole('button', { name: /tambah jumlah ayam/i })).toBeDisabled();
      expect(screen.getByLabelText(/^KARI/)).toBeDisabled();
      expect(confirmButton()).toBeEnabled();

      await user.click(screen.getByLabelText(/^AYAM/)); // lepas
      expect(screen.getByLabelText(/^KARI/)).toBeEnabled();
      expect(screen.getByRole('button', { name: /tambah jumlah soto/i })).toBeEnabled();
    });
  });

  it('charges the base price again for the second option and shows the portion count', async () => {
    const user = userEvent.setup();
    render2();

    await user.click(screen.getByLabelText(/SOTO/));
    expect(screen.getByTestId('modifier-price')).toHaveTextContent('8.000');
    await user.click(screen.getByLabelText(/AYAM BAWANG/));
    expect(screen.getByTestId('modifier-price')).toHaveTextContent('16.000');
    expect(screen.getByTestId('modifier-portions')).toHaveTextContent('2 porsi');
  });

  // 089 — jumlah per pilihan.
  describe('option quantity (089)', () => {
    const topping = { id: 5, name: 'Topping', selection_type: 'multiple', is_required: false, min_select: 0, max_select: null, options: [
      { id: 50, name: 'Telur', price_delta: '3000.00', min_qty: 1, max_qty: 3 },
      { id: 51, name: 'Keju', price_delta: '2000.00', min_qty: 2, max_qty: null },
      { id: 52, name: 'Kerupuk', price_delta: '1000.00', min_qty: 1, max_qty: 1 },
    ] };

    it('shows a stepper only for a chosen option that may be ordered more than once', async () => {
      const user = userEvent.setup();
      renderPicker({ groups: [topping] });

      expect(screen.queryByTestId('qty-50')).not.toBeInTheDocument();
      await user.click(screen.getByLabelText(/^telur/i));
      expect(screen.getByTestId('qty-50')).toBeInTheDocument();
      await user.click(screen.getByLabelText(/^kerupuk/i));
      expect(screen.queryByTestId('qty-52')).not.toBeInTheDocument(); // 1..1 = pilih atau tidak
    });

    it('starts at the minimum, respects min and max, and prices the quantity', async () => {
      const user = userEvent.setup();
      renderPicker({ groups: [topping] });

      await user.click(screen.getByLabelText(/^telur/i));
      expect(screen.getByTestId('qty-value-50')).toHaveTextContent('1');
      expect(screen.getByRole('button', { name: /kurangi jumlah telur/i })).toBeDisabled();
      await user.click(screen.getByRole('button', { name: /tambah jumlah telur/i }));
      await user.click(screen.getByRole('button', { name: /tambah jumlah telur/i }));
      expect(screen.getByTestId('qty-value-50')).toHaveTextContent('3');
      expect(screen.getByRole('button', { name: /tambah jumlah telur/i })).toBeDisabled();
      expect(screen.getByTestId('modifier-price')).toHaveTextContent('34.000'); // 25.000 + 3 × 3.000
    });

    it('starts a minimum-2 option at 2 and an unbounded option keeps growing', async () => {
      const user = userEvent.setup();
      renderPicker({ groups: [topping] });

      await user.click(screen.getByLabelText(/^keju/i));
      expect(screen.getByTestId('qty-value-51')).toHaveTextContent('2');
      expect(screen.getByRole('button', { name: /kurangi jumlah keju/i })).toBeDisabled();
      for (let i = 0; i < 5; i += 1) await user.click(screen.getByRole('button', { name: /tambah jumlah keju/i }));
      expect(screen.getByTestId('qty-value-51')).toHaveTextContent('7');
      expect(screen.getByRole('button', { name: /tambah jumlah keju/i })).toBeEnabled();
    });

    it('emits the chosen quantities, and un-ticking an option drops its quantity', async () => {
      const user = userEvent.setup();
      const { emitted } = renderPicker({ groups: [topping] });

      await user.click(screen.getByLabelText(/^telur/i));
      await user.click(screen.getByRole('button', { name: /tambah jumlah telur/i }));
      await user.click(screen.getByLabelText(/^kerupuk/i));
      await user.click(screen.getByLabelText(/^keju/i));
      await user.click(screen.getByLabelText(/^keju/i)); // lepas
      await user.click(confirmButton());

      expect(emitted().confirm[0][0]).toEqual({ modifier_option_ids: [50, 52], modifier_quantities: { 50: 2, 52: 1 }, note: null });
    });
  });
});
