import { describe, it, expect, vi } from 'vitest';
import { render, screen, fireEvent } from '@testing-library/vue';
import userEvent from '@testing-library/user-event';
import BaseSelect from '../../resources/js/components/ui/BaseSelect.vue';

// Regression coverage for the native <select> replacement — the popup
// list on a real <select> uses OS chrome that can't be restyled and
// looked completely out of place next to the rest of the app (see the
// commit this test landed with). This component is a custom listbox
// instead, teleported to <body> so it can't be silently clipped by an
// ancestor drawer/modal's `overflow`, which is a real trap: geometry
// and computed style all look correct even when nothing is visible.
const OPTIONS = [
  { value: 1, label: 'Ryu Illustration' },
  { value: 2, label: 'Yayi' },
];

describe('BaseSelect', () => {
  it('shows the placeholder when nothing is selected, and does not render a native <select>', () => {
    render(BaseSelect, { props: { options: OPTIONS, placeholder: 'Pilih…' } });
    expect(screen.getByRole('combobox')).toHaveTextContent('Pilih…');
    expect(document.querySelector('select')).toBeNull();
  });

  it('shows the selected option label when modelValue matches', () => {
    render(BaseSelect, { props: { options: OPTIONS, modelValue: 2 } });
    expect(screen.getByRole('combobox')).toHaveTextContent('Yayi');
  });

  it('opens the listbox on click and lists every option', async () => {
    const user = userEvent.setup();
    render(BaseSelect, { props: { options: OPTIONS } });
    await user.click(screen.getByRole('combobox'));
    expect(screen.getByRole('listbox')).toBeInTheDocument();
    expect(screen.getByRole('option', { name: 'Ryu Illustration' })).toBeInTheDocument();
    expect(screen.getByRole('option', { name: 'Yayi' })).toBeInTheDocument();
  });

  it('emits update:modelValue and closes when an option is clicked', async () => {
    const user = userEvent.setup();
    const { emitted } = render(BaseSelect, { props: { options: OPTIONS } });
    await user.click(screen.getByRole('combobox'));
    await user.click(screen.getByRole('option', { name: 'Yayi' }));
    expect(emitted()['update:modelValue'][0]).toEqual([2]);
    expect(screen.queryByRole('listbox')).not.toBeInTheDocument();
  });

  it('opens on ArrowDown when closed, matching native <select> semantics', async () => {
    const user = userEvent.setup();
    render(BaseSelect, { props: { options: OPTIONS } });
    screen.getByRole('combobox').focus();
    await user.keyboard('{ArrowDown}');
    expect(screen.getByRole('listbox')).toBeInTheDocument();
  });

  it('once open, ArrowDown moves the active option and Enter commits it', async () => {
    const user = userEvent.setup();
    const { emitted } = render(BaseSelect, { props: { options: OPTIONS, modelValue: 1 } });
    await user.click(screen.getByRole('combobox'));
    await user.keyboard('{ArrowDown}');
    // Still open — the component must preventDefault so the page doesn't
    // scroll and inadvertently trigger the click-outside/scroll-close path.
    expect(screen.getByRole('listbox')).toBeInTheDocument();
    await user.keyboard('{Enter}');
    expect(emitted()['update:modelValue'][0]).toEqual([2]);
    expect(screen.queryByRole('listbox')).not.toBeInTheDocument();
  });

  it('closes on Escape without changing the value', async () => {
    const user = userEvent.setup();
    const { emitted } = render(BaseSelect, { props: { options: OPTIONS, modelValue: 1 } });
    await user.click(screen.getByRole('combobox'));
    await user.keyboard('{Escape}');
    expect(screen.queryByRole('listbox')).not.toBeInTheDocument();
    expect(emitted()['update:modelValue']).toBeUndefined();
  });

  it('does not open when disabled', async () => {
    const user = userEvent.setup();
    render(BaseSelect, { props: { options: OPTIONS, disabled: true } });
    await user.click(screen.getByRole('combobox'));
    expect(screen.queryByRole('listbox')).not.toBeInTheDocument();
  });

  it('renders the error message and marks the trigger invalid', () => {
    render(BaseSelect, { props: { options: OPTIONS, error: 'Wajib diisi' } });
    expect(screen.getByText('Wajib diisi')).toBeInTheDocument();
    expect(screen.getByRole('combobox')).toHaveAttribute('aria-invalid', 'true');
  });
});

// 036-bom-variant-stock-ux (US2) — daftar panjang harus bisa di-scroll dan dicari.
// AKAR MASALAH: listener scroll tingkat window (capture) menutup panel pada SETIAP
// scroll, termasuk scroll di dalam daftarnya sendiri — daftar pendek tidak pernah
// perlu di-scroll, jadi tidak ada yang melihatnya sampai produk punya banyak varian.
const MANY = Array.from({ length: 30 }, (_, i) => ({ value: i + 1, label: `SPF-KC-MCY-${String(i + 1).padStart(3, '0')} — Varian ${i + 1}` }));

describe('BaseSelect — scrolling and search (036)', () => {
  it('stays open when the list itself is scrolled', async () => {
    const user = userEvent.setup();
    render(BaseSelect, { props: { options: MANY } });
    await user.click(screen.getByRole('combobox'));

    await fireEvent.scroll(screen.getByRole('listbox'));

    expect(screen.getByRole('listbox')).toBeInTheDocument();
  });

  it('still closes when something OUTSIDE the list scrolls (fixed-position panel would float)', async () => {
    const user = userEvent.setup();
    render(BaseSelect, { props: { options: MANY } });
    await user.click(screen.getByRole('combobox'));

    await fireEvent.scroll(document.body);

    expect(screen.queryByRole('listbox')).not.toBeInTheDocument();
  });

  it('has no search box unless it is opted in', async () => {
    const user = userEvent.setup();
    render(BaseSelect, { props: { options: MANY } });
    await user.click(screen.getByRole('combobox'));

    expect(screen.queryByRole('textbox')).not.toBeInTheDocument();
  });

  it('searchable: filters case-insensitively on the label and says so when nothing matches', async () => {
    const user = userEvent.setup();
    render(BaseSelect, { props: { options: MANY, searchable: true } });
    await user.click(screen.getByRole('combobox'));
    const box = screen.getByRole('textbox', { name: 'Cari…' });
    expect(box).toHaveFocus();
    expect(screen.getAllByRole('option').length).toBeGreaterThan(30); // 30 pilihan + baris placeholder

    await user.type(box, 'mcy-012');
    expect(screen.getByRole('option', { name: /SPF-KC-MCY-012/ })).toBeInTheDocument();
    expect(screen.queryByRole('option', { name: /SPF-KC-MCY-013/ })).not.toBeInTheDocument();

    await user.clear(box);
    await user.type(box, 'zzz-nothing');
    expect(screen.queryAllByRole('option')).toHaveLength(0);
    expect(screen.getByText('Tidak ada pilihan.')).toBeInTheDocument();
  });

  it('searchable: arrows and Enter act on the FILTERED list, and the choice is emitted', async () => {
    const user = userEvent.setup();
    const { emitted } = render(BaseSelect, { props: { options: MANY, searchable: true } });
    await user.click(screen.getByRole('combobox'));

    await user.type(screen.getByRole('textbox', { name: 'Cari…' }), 'varian 2');
    // cocok: Varian 2, 20..29  ->  yang aktif pertama = Varian 2; ArrowDown -> Varian 20
    await user.keyboard('{ArrowDown}{Enter}');

    expect(emitted()['update:modelValue'][0]).toEqual([20]);
    expect(screen.queryByRole('listbox')).not.toBeInTheDocument();
  });

  it('searchable: Escape closes without a value, and reopening starts with an empty search', async () => {
    const user = userEvent.setup();
    const { emitted } = render(BaseSelect, { props: { options: MANY, searchable: true } });
    await user.click(screen.getByRole('combobox'));
    await user.type(screen.getByRole('textbox', { name: 'Cari…' }), 'varian 9');

    await user.keyboard('{Escape}');
    expect(screen.queryByRole('listbox')).not.toBeInTheDocument();
    expect(emitted()['update:modelValue']).toBeUndefined();

    await user.click(screen.getByRole('combobox'));
    expect(screen.getByRole('textbox', { name: 'Cari…' })).toHaveValue('');
  });

  it('hides its own placeholder row when the caller already provides an empty-value option', async () => {
    const user = userEvent.setup();
    render(BaseSelect, { props: { options: [{ value: '', label: 'Semua tipe' }, { value: 1, label: 'Penjualan' }], modelValue: '', placeholder: 'Semua tipe' } });
    await user.click(screen.getByRole('combobox'));

    expect(screen.getAllByRole('option').map((o) => o.textContent.trim())).toEqual(['Semua tipe', 'Penjualan']);
    expect(screen.getByRole('combobox')).toHaveTextContent('Semua tipe');
  });
});
