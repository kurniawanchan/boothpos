import { describe, it, expect, vi } from 'vitest';
import { render, screen, fireEvent, within } from '@testing-library/vue';
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

// 037-variant-drawer-bom-ui (US3) — panel harus selalu muat di layar: AKAR MASALAH "tidak bisa di-scroll sampai
// bawah" adalah panel fixed yang SELALU terbuka di bawah pemicu dengan tinggi tetap, sehingga di dekat tepi bawah
// layar daftarnya keluar layar dan tidak ada yang bisa menggulungnya.
function placeTrigger(top, height = 46, innerHeight = 800) {
  Object.defineProperty(window, 'innerHeight', { value: innerHeight, configurable: true });
  const trigger = screen.getByRole('combobox');
  trigger.getBoundingClientRect = () => ({ top, bottom: top + height, left: 20, right: 320, width: 300, height, x: 20, y: top });
}

describe('BaseSelect — fits the screen (037)', () => {
  it('opens downward below the trigger when there is room', async () => {
    const user = userEvent.setup();
    render(BaseSelect, { props: { options: MANY } });
    placeTrigger(100);
    await user.click(screen.getByRole('combobox'));

    const style = screen.getByRole('listbox').style;
    expect(style.top).toBe('152px'); // bottom 146 + 6
    expect(style.bottom).toBe('');
    expect(style.maxHeight).toBe('320px');
  });

  it('opens UPWARD when the trigger is near the bottom edge and there is more room above', async () => {
    const user = userEvent.setup();
    render(BaseSelect, { props: { options: MANY } });
    placeTrigger(700);
    await user.click(screen.getByRole('combobox'));

    const style = screen.getByRole('listbox').style;
    expect(style.bottom).toBe('106px'); // innerHeight 800 - trigger.top 700 + 6
    expect(style.top).toBe('');
    expect(style.maxHeight).toBe('320px');
  });

  it('caps the height to the space actually available (never taller than the screen allows)', async () => {
    const user = userEvent.setup();
    render(BaseSelect, { props: { options: MANY } });
    placeTrigger(500); // bottom 546 -> 800 - 546 - 12 = 242 below (>= 220: stays downward)
    await user.click(screen.getByRole('combobox'));
    expect(screen.getByRole('listbox').style.maxHeight).toBe('242px');
  });

  it('caps an upward panel to the space above in a short viewport', async () => {
    const user = userEvent.setup();
    render(BaseSelect, { props: { options: MANY } });
    placeTrigger(200, 46, 300); // below 300-246-12 = 42, above 200-12 = 188
    await user.click(screen.getByRole('combobox'));

    const style = screen.getByRole('listbox').style;
    expect(style.bottom).toBe('106px'); // 300 - 200 + 6
    expect(style.maxHeight).toBe('188px');
  });

  it('shows option thumbnails (image or placeholder) in the list and beside the selected label, only when options opt in', async () => {
    const user = userEvent.setup();
    const options = [
      { value: 1, label: 'SPF-001 — Red', thumb: 'https://example.test/red.png' },
      { value: 2, label: 'SPF-002 — Blue', thumb: null },
    ];
    render(BaseSelect, { props: { options, modelValue: 1 } });

    expect(screen.getByRole('combobox').querySelector('img')).toHaveAttribute('src', 'https://example.test/red.png');
    await user.click(screen.getByRole('combobox'));
    const list = screen.getByRole('listbox');
    expect(list.querySelectorAll('img')).toHaveLength(1);
    expect(list.querySelectorAll('.ph-image')).toHaveLength(1);
  });

  it('renders no thumbnail element when no option has a thumb key', async () => {
    const user = userEvent.setup();
    render(BaseSelect, { props: { options: OPTIONS, modelValue: 1 } });
    await user.click(screen.getByRole('combobox'));

    expect(screen.getByRole('listbox').querySelector('img, .ph-image')).toBeNull();
  });
});

