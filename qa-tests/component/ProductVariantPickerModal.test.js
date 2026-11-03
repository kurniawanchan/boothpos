import { describe, it, expect } from 'vitest';
import { render, screen } from '@testing-library/vue';
import ProductVariantPickerModal from '../../resources/js/components/pos/ProductVariantPickerModal.vue';

const product = (overrides = {}) => ({
  name: 'LOVE BULLET',
  artist_name: 'vlaisca',
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

  // 054 (US1) — pemilih tetap terbuka supaya beberapa varian bisa dipilih sekaligus.
  describe('multi-pick (054)', () => {
    const many = () =>
      product({
        variants: [
          { id: 1, variant_name: 'Orange cat', sku: 'VLC-PI-V-10', sell_price: '15000.00', current_stock: 7, image_url: null },
          { id: 2, variant_name: 'Black cat', sku: 'VLC-PI-V-11', sell_price: '15000.00', current_stock: 7, image_url: null },
          { id: 3, variant_name: 'Alien cat', sku: 'VLC-PI-V-17', sell_price: '15000.00', current_stock: 0, image_url: null },
        ],
      });

    it('emits select but never close when a variant is tapped', async () => {
      const { default: userEvent } = await import('@testing-library/user-event');
      const user = userEvent.setup();
      const { emitted } = render(ProductVariantPickerModal, { props: { open: true, product: many() } });

      await user.click(screen.getByText('Orange cat'));
      await user.click(screen.getByText('Black cat'));

      expect(emitted().select).toHaveLength(2);
      expect(emitted().close).toBeUndefined();
    });

    it('shows how many of each variant are already in the cart', () => {
      render(ProductVariantPickerModal, { props: { open: true, product: many(), cartQtyByVariant: { 1: 3 } } });
      expect(screen.getByText('3 di keranjang')).toBeInTheDocument();
      expect(screen.queryByText('0 di keranjang')).not.toBeInTheDocument();
    });

    it('has a bottom Close button that emits close', async () => {
      const { default: userEvent } = await import('@testing-library/user-event');
      const user = userEvent.setup();
      const { emitted } = render(ProductVariantPickerModal, { props: { open: true, product: many() } });

      await user.click(screen.getByTestId('picker-close'));
      expect(emitted().close).toHaveLength(1);
    });

    it('does not select an out-of-stock variant', async () => {
      const { default: userEvent } = await import('@testing-library/user-event');
      const user = userEvent.setup();
      const { emitted } = render(ProductVariantPickerModal, { props: { open: true, product: many() } });

      await user.click(screen.getByText('Alien cat'));
      expect(emitted().select).toBeUndefined();
    });

    it('stops accepting taps once the cart holds all the stock of a variant', async () => {
      const { default: userEvent } = await import('@testing-library/user-event');
      const user = userEvent.setup();
      const { emitted } = render(ProductVariantPickerModal, { props: { open: true, product: many(), cartQtyByVariant: { 2: 7 } } });

      await user.click(screen.getByText('Black cat'));
      expect(emitted().select).toBeUndefined();
    });
  });
});
