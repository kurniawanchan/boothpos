import { describe, it, expect, vi } from 'vitest';
import { render, screen } from '@testing-library/vue';
import id from '../../resources/js/locales/id.json';
import ProductSkuCell from '../../resources/js/views/products/ProductSkuCell.vue';

// Dipindah dari ProductsView.test.js (fitur 043 menggantikan tabel lama): perilaku sel SKU per-varian tetap dijaga —
// 024 (SKU nyata per varian, "+N lainnya"), 038 (nama varian di tooltip), klik SKU membuka detail varian.
const V = (n, name) => ({ id: 100 + n, sku: `VLC-SK-ARE-00${n}`, variant_name: name, sell_price: '20000.00', current_stock: 5, is_active: true, image_url: null });
const PRODUCT = { id: 7, name: 'Arekku', variants: [V(1, 'Merah'), V(2, 'Biru'), V(3, 'Hijau'), V(4, 'Kuning'), V(5, 'Biru'), V(6, '')] };

async function setup(product = PRODUCT) {
  const { default: userEvent } = await import('@testing-library/user-event');
  const user = userEvent.setup();
  const onOpen = vi.fn();
  render(ProductSkuCell, { props: { product, 'onOpen-variant': onOpen } });
  return { user, onOpen };
}
const sku = (n) => screen.getByRole('button', { name: `VLC-SK-ARE-00${n}` });
const showMore = () => screen.getByRole('button', { name: new RegExp(`^${id.master_data.show_more_count.replace('{count}', '3').replace(/[+]/g, '\\+')}`) });

describe('ProductSkuCell', () => {
  it('shows only the first 3 SKUs, then expands/collapses via the "+N more" link', async () => {
    const { user } = await setup();
    expect(screen.getByText('VLC-SK-ARE-003')).toBeInTheDocument();
    expect(screen.queryByText('VLC-SK-ARE-004')).not.toBeInTheDocument();

    await user.click(showMore());
    expect(screen.getByText('VLC-SK-ARE-004')).toBeInTheDocument();
    await user.click(screen.getByText('Sembunyikan'));
    expect(screen.queryByText('VLC-SK-ARE-004')).not.toBeInTheDocument();
  });

  it('shows a dash when the product has no variants', async () => {
    await setup({ id: 1, name: 'X', variants: [] });
    expect(screen.getByText('—')).toBeInTheDocument();
  });

  it('asks to open that single variant when its SKU is clicked', async () => {
    const { user, onOpen } = await setup();
    await user.click(sku(2));
    expect(onOpen).toHaveBeenCalledWith(PRODUCT, PRODUCT.variants[1]);
  });

  it('shows the variant name when the SKU is hovered and hides it when the pointer leaves', async () => {
    const { user } = await setup();
    await user.hover(sku(2));
    expect(screen.getByRole('tooltip')).toHaveTextContent('Biru');
    await user.unhover(sku(2));
    expect(screen.queryByRole('tooltip')).not.toBeInTheDocument();
    await user.hover(sku(1));
    expect(screen.getByRole('tooltip')).toHaveTextContent('Merah');
  });

  it('shows the same tooltip on keyboard focus and Escape hides it', async () => {
    const { user } = await setup();
    sku(3).focus();
    expect(await screen.findByRole('tooltip')).toHaveTextContent('Hijau');
    await user.keyboard('{Escape}');
    expect(screen.queryByRole('tooltip')).not.toBeInTheDocument();
  });

  it('gives revealed SKUs their own variant name, and tells same-named variants apart by SKU', async () => {
    const { user } = await setup();
    await user.click(showMore());
    await user.hover(sku(4));
    expect(screen.getByRole('tooltip')).toHaveTextContent('Kuning');
    await user.unhover(sku(4));
    await user.hover(sku(5));
    expect(screen.getByRole('tooltip')).toHaveTextContent('Biru');
    expect(sku(2)).not.toBe(sku(5));
  });

  it('gives the "+N more" toggle no tooltip, and a variant without a name none either', async () => {
    const { user } = await setup();
    await user.hover(showMore());
    expect(screen.queryByRole('tooltip')).not.toBeInTheDocument();
    await user.click(showMore());
    await user.hover(sku(6));
    expect(screen.queryByRole('tooltip')).not.toBeInTheDocument();
  });
});
