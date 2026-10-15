import { describe, it, expect } from 'vitest';
import { render, screen, within } from '@testing-library/vue';
import userEvent from '@testing-library/user-event';
import VariantPickList from '../../resources/js/components/product/VariantPickList.vue';

const VARIANTS = [
  { id: 10, sku: 'SPF-KC-MCY-001', variant_name: 'Red', image_url: 'https://example.test/red.png', is_active: true, has_bom: true },
  { id: 11, sku: 'SPF-KC-MCY-002', variant_name: 'Blue', image_url: null, is_active: false, has_bom: false },
  { id: 12, sku: 'SPF-KC-MCY-003', variant_name: 'Green', image_url: null, is_active: true, has_bom: false },
];

function renderList(modelValue = []) {
  return render(VariantPickList, { props: { variants: VARIANTS, modelValue } });
}
const last = (emitted) => emitted()['update:modelValue'].at(-1)[0];

// 037 (US3) — daftar centang varian tujuan salin, dengan gambar.
describe('VariantPickList (037)', () => {
  it('shows each variant as a labelled checkbox with its picture (or a placeholder) and markers', () => {
    renderList();

    expect(screen.getAllByRole('checkbox')).toHaveLength(3);
    const red = screen.getByRole('checkbox', { name: /SPF-KC-MCY-001.*Red/ });
    const row = red.closest('label');
    expect(row.querySelector('img')).toHaveAttribute('src', 'https://example.test/red.png');
    expect(within(row).getByText('Punya BOM')).toBeInTheDocument();

    const blue = screen.getByRole('checkbox', { name: /SPF-KC-MCY-002.*Blue/ }).closest('label');
    expect(blue.querySelector('.ph-image')).not.toBeNull();
    expect(within(blue).getByText('Nonaktif')).toBeInTheDocument();
  });

  it('emits the ticked ids and shows how many are selected', async () => {
    const user = userEvent.setup();
    const { emitted, rerender } = renderList();

    await user.click(screen.getByRole('checkbox', { name: /Red/ }));
    expect(last(emitted)).toEqual([10]);

    await rerender({ modelValue: [10, 12] });
    expect(screen.getByText('2 dipilih')).toBeInTheDocument();
    expect(screen.getByRole('checkbox', { name: /Red/ })).toBeChecked();
    await user.click(screen.getByRole('checkbox', { name: /Red/ }));
    expect(last(emitted)).toEqual([12]);
  });

  it('selects all / clears', async () => {
    const user = userEvent.setup();
    const { emitted } = renderList([10]);

    await user.click(screen.getByRole('button', { name: 'Pilih semua' }));
    expect([...last(emitted)].sort()).toEqual([10, 11, 12]);

    await user.click(screen.getByRole('button', { name: 'Kosongkan' }));
    expect(last(emitted)).toEqual([]);
  });

  it('filters by SKU or name (case-insensitive), says so when nothing matches, and select-all only adds the visible rows', async () => {
    const user = userEvent.setup();
    const { emitted } = renderList([10]);
    const box = screen.getByRole('textbox', { name: 'Cari…' });

    await user.type(box, 'bLuE');
    expect(screen.getAllByRole('checkbox')).toHaveLength(1);
    await user.click(screen.getByRole('button', { name: 'Pilih semua' }));
    expect([...last(emitted)].sort()).toEqual([10, 11]); // sudah terpilih + yang tampil saja; Green tidak ikut

    await user.clear(box);
    await user.type(box, 'mcy-003');
    expect(screen.getByRole('checkbox', { name: /Green/ })).toBeInTheDocument();

    await user.clear(box);
    await user.type(box, 'zzz');
    expect(screen.queryAllByRole('checkbox')).toHaveLength(0);
    expect(screen.getByText('Tidak ada varian yang cocok.')).toBeInTheDocument();
  });

  it('keeps the rows in their own scroll container so a long list never overflows the dialog', () => {
    renderList();

    const scroller = screen.getAllByRole('checkbox')[0].closest('[data-testid="pick-scroll"]');
    expect(scroller).toHaveClass('max-h-[260px]', 'overflow-y-auto');
  });
});
