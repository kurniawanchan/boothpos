import { describe, it, expect } from 'vitest';
import { render, screen, within } from '@testing-library/vue';
import CatalogPdfPage from '../../resources/js/components/catalog/CatalogPdfPage.vue';

const PATTERN = '1101001000010100011000100010110001100011101011'; // Code128::pattern('A')
const STORE = { name: 'Demo Sakana Fridge', logo_url: 'https://example.test/logo.png', address: 'Jl. Contoh No. 1', contact_person: null, contact_phone: '021-555', contact_email: null };
const VARIANT = (id, extra = {}) => ({
  variant_id: id, category_name: 'Keychain', product_name: 'MCYT', variant_name: `Slippery ${id}`, code: `SPF-KC-MCYT-${id}`,
  sell_price: '20000.00', image_url: 'https://example.test/v.png', barcode: { type: 'code128', pattern: PATTERN, text: `SPF-KC-MCYT-${id}` }, ...extra,
});
const sellerPage = (extra = {}) => ({
  type: 'seller', number: 2, total: 3, accentIndex: 0, continued: false,
  seller: { artist_id: 1, name: 'Seller A', variantCount: 2 }, variants: [VARIANT(1), VARIANT(2)], ...extra,
});
const coverPage = (extra = {}) => ({
  type: 'cover', number: 1, total: 3,
  index: [{ artist_id: 1, name: 'Seller A', count: 9, accentIndex: 0, startPage: 2 }, { artist_id: 2, name: 'セラーB', count: 2, accentIndex: 1, startPage: 4 }], ...extra,
});
const mount = (page, store = STORE) => render(CatalogPdfPage, { props: { page, store, generatedAt: '2026-10-28T11:20:00+07:00' } });

describe('CatalogPdfPage — cover (049)', () => {
  it('shows the store identity, the seller index with counts and first pages, and the footer', () => {
    mount(coverPage());

    expect(screen.getByTestId('cover-store-name')).toHaveTextContent('Demo Sakana Fridge');
    expect(screen.getByAltText('Demo Sakana Fridge')).toHaveAttribute('src', 'https://example.test/logo.png');
    const rows = screen.getAllByTestId('cover-index-row');
    expect(rows).toHaveLength(2);
    expect(within(rows[0]).getByText('Seller A')).toBeInTheDocument();
    expect(rows[0]).toHaveTextContent('9 varian');
    expect(rows[0]).toHaveTextContent('hlm 2');
    expect(within(rows[1]).getByText('セラーB')).toBeInTheDocument(); // nama non-Latin tampil apa adanya
    expect(screen.getByTestId('catalog-footer')).toHaveTextContent('Halaman 1 dari 3');
    expect(screen.getByTestId('catalog-footer')).toHaveTextContent(/Dibuat/);
  });

  it('leaves out contact lines that are not set, with no stray labels', () => {
    mount(coverPage());
    const contact = screen.getByTestId('cover-contact');

    expect(contact).toHaveTextContent('Jl. Contoh No. 1');
    expect(contact).toHaveTextContent('Telepon: 021-555');
    expect(contact).not.toHaveTextContent('Email');
    expect(contact).not.toHaveTextContent('Kontak');
  });

  it('still renders with only the store name when there is no logo and no contact details', () => {
    mount(coverPage(), { name: 'Toko Sederhana' });

    expect(screen.getByTestId('cover-store-name')).toHaveTextContent('Toko Sederhana');
    expect(screen.queryByRole('img')).not.toBeInTheDocument();
    expect(screen.queryByTestId('cover-contact')).not.toBeInTheDocument();
  });
});

describe('CatalogPdfPage — seller page (049)', () => {
  it('shows the seller header and, per card, category, product, variant, code, price and a barcode', () => {
    mount(sellerPage());

    expect(screen.getByTestId('seller-header')).toHaveTextContent('Seller A');
    expect(screen.getByTestId('seller-header')).toHaveTextContent('2 varian');
    const cards = screen.getAllByTestId('catalog-card');
    expect(cards).toHaveLength(2);
    expect(within(cards[0]).getByText('Keychain')).toBeInTheDocument();
    expect(within(cards[0]).getByText('MCYT')).toBeInTheDocument();
    expect(within(cards[0]).getByText('Slippery 1')).toBeInTheDocument();
    expect(within(cards[0]).getByText('Rp 20.000')).toBeInTheDocument();
    expect(within(cards[0]).getByText('SPF-KC-MCYT-1')).toBeInTheDocument(); // kode terbaca di bawah barcode
    expect(within(cards[0]).getByRole('img', { name: 'SPF-KC-MCYT-1' })).toBeInTheDocument(); // svg barcode
    expect(within(cards[0]).getByAltText('Slippery 1')).toHaveAttribute('src', 'https://example.test/v.png');
  });

  it('uses a same-size placeholder when a variant has no picture', () => {
    mount(sellerPage({ variants: [VARIANT(1, { image_url: null })] }));
    const card = screen.getByTestId('catalog-card');

    expect(within(card).getByTestId('card-placeholder')).toHaveTextContent('Tanpa gambar');
    expect(within(card).queryByAltText('Slippery 1')).not.toBeInTheDocument();
  });

  it('lists a variant whose code cannot be barcoded with its readable code and no bars', () => {
    mount(sellerPage({ variants: [VARIANT(1, { code: 'セラー-1', barcode: null })] }));
    const card = screen.getByTestId('catalog-card');

    expect(within(card).getByText('セラー-1')).toBeInTheDocument();
    expect(card.querySelector('svg')).toBeNull();
  });

  it('marks continuation pages and repeats the seller name', () => {
    mount(sellerPage({ continued: true, number: 3 }));

    expect(screen.getByTestId('seller-header')).toHaveTextContent('Seller A');
    expect(screen.getByTestId('seller-header')).toHaveTextContent('lanjutan');
    expect(screen.getByTestId('catalog-footer')).toHaveTextContent('Halaman 3 dari 3');
  });

  it('colours the header by the seller accent (token classes) while the barcode stays dark on white', () => {
    const { container } = mount(sellerPage({ accentIndex: 1 }));

    expect(screen.getByTestId('seller-header').className).toMatch(/\bbg-sky-bg\b/);
    expect(screen.getByTestId('seller-header').className).toMatch(/\btext-sky-text\b/);
    const barcode = container.querySelector('[data-testid="barcode"]');
    expect(barcode.className).toMatch(/\bbg-white\b/);
    expect(barcode.className).not.toMatch(/sky|violet|warn|danger|mint/);
    expect(container.innerHTML).not.toMatch(/#[0-9a-fA-F]{3,8}\b/); // tanpa hex mentah
  });

  it('never prints cost, stock or margin even if the data carried them', () => {
    mount(sellerPage({ variants: [VARIANT(1, { cost_price: '777.00', current_stock: 42, margin: '60%' })] }));
    const text = screen.getByTestId('catalog-page').textContent.toLowerCase();

    for (const forbidden of ['777', 'modal', 'stok', 'margin', '42']) {
      expect(text).not.toContain(forbidden);
    }
  });

  it('has a fixed A4 page size and fixed card height so pagination stays deterministic', () => {
    mount(sellerPage());

    expect(screen.getByTestId('catalog-page').className).toMatch(/h-\[1123px\]/);
    expect(screen.getByTestId('catalog-page').className).toMatch(/w-\[794px\]/);
    expect(screen.getAllByTestId('catalog-card')[0].className).toMatch(/h-\[184px\]/);
  });
});
