import { describe, it, expect } from 'vitest';
import { render, screen } from '@testing-library/vue';
import ProductVariantPickerModal from '../../resources/js/components/pos/ProductVariantPickerModal.vue';

const product = (overrides = {}) => ({
  name: 'LOVE BULLET',
  home_outlet_name: 'vlaisca',
  category_name: 'KEYCHAIN',
  image_url: 'https://example.test/product.png',
  variants: [
    { id: 1, variant_name: 'Koharu', sku: 'VLC-KC-LOV-001', sell_price: '40000.00', current_stock: 20, image_url: null },
  ],
  ...overrides,
});

describe('ProductVariantPickerModal', () => {
  it('renders variant rows without throwing when the product has no own image (falls back to the product image)', async () => {
    const { default: userEvent } = await import('@testing-library/user-event');
    const user = userEvent.setup();
    render(ProductVariantPickerModal, { props: { open: true, product: product() } });

    expect(screen.getByText('Koharu')).toBeInTheDocument();
    // Falls back to the product's own image — this is the exact path that
    // previously threw "ReferenceError: product is not defined" because
    // defineProps()'s return value wasn't captured into `props`.
    const thumb = screen.getByAltText('Koharu');
    expect(thumb).toHaveAttribute('src', 'https://example.test/product.png');

    await user.click(screen.getByRole('button', { name: 'Perbesar gambar Koharu' }));
    const enlarged = await screen.findAllByAltText('Koharu');
    expect(enlarged.length).toBeGreaterThan(1);
  });

  it('shows the category name below the product name', () => {
    render(ProductVariantPickerModal, { props: { open: true, product: product() } });
    expect(screen.getByText('KEYCHAIN')).toBeInTheDocument();
  });

  it('renders with no thumbnail at all when neither the variant nor the product has an image', () => {
    render(ProductVariantPickerModal, {
      props: { open: true, product: product({ image_url: null, variants: [{ id: 1, variant_name: 'Koharu', sku: 'VLC-KC-LOV-001', sell_price: '40000.00', current_stock: 20, image_url: null }] }) },
    });
    expect(screen.queryByAltText('Koharu')).not.toBeInTheDocument();
  });

  // 087 — varian tanpa stok sendiri (sumber modifier / menu masak): selalu bisa dipilih, tanpa label stok.
  it('lets an untracked variant with zero stock be picked and shows no stock label', async () => {
    const { default: userEvent } = await import('@testing-library/user-event');
    const { emitted } = render(ProductVariantPickerModal, { props: { open: true, product: product({ image_url: null, variants: [
      { id: 7, variant_name: 'Standard', sku: 'WJ3-I1-IKU-004', sell_price: '15000.00', current_stock: 0, outlet_stock: 0, track_stock: false, image_url: null },
    ] }) } });

    expect(screen.queryByText(/habis/i)).not.toBeInTheDocument();
    await userEvent.setup().click(screen.getByRole('button', { name: /Standard/ }));
    expect(emitted().select?.[0]?.[0]?.id).toBe(7);
  });
});

