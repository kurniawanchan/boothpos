import { describe, it, expect, vi } from 'vitest';
import { render, screen } from '@testing-library/vue';
import ProductCodeCell from '../../resources/js/views/products/ProductCodeCell.vue';

// Dipindah dari ProductsView.test.js (043): 038 (gambar 96px di atas kode, satu kolom) dan 036 (kode satu baris).
const WITH_IMG = { id: 1, name: 'Keychain A', code_prefix: 'ARTKY001', image_url: 'https://example.test/a.png' };
const NO_IMG = { id: 2, name: 'Keychain B', code_prefix: 'ARTKY002', image_url: null };

describe('ProductCodeCell', () => {
  it('renders the picture above the code in the same cell, 96px', () => {
    render(ProductCodeCell, { props: { product: WITH_IMG } });
    const img = screen.getByAltText('Keychain A');
    expect(img).toHaveAttribute('src', 'https://example.test/a.png');
    expect(img).toHaveClass('h-24', 'w-24');
    const code = screen.getByText('ARTKY001');
    expect(Boolean(img.compareDocumentPosition(code) & Node.DOCUMENT_POSITION_FOLLOWING)).toBe(true);
  });

  it('renders a same-size placeholder when there is no picture', () => {
    render(ProductCodeCell, { props: { product: NO_IMG } });
    expect(screen.queryByAltText('Keychain B')).not.toBeInTheDocument();
    expect(document.querySelector('.ph-image').parentElement).toHaveClass('h-24', 'w-24');
  });

  it('keeps the code on one line with the full code on hover', () => {
    render(ProductCodeCell, { props: { product: WITH_IMG } });
    const code = screen.getByText('ARTKY001');
    expect(code).toHaveClass('whitespace-nowrap');
    expect(code).toHaveAttribute('title', 'ARTKY001');
  });

  it('only REQUESTS an enlargement when the picture is clicked', async () => {
    const { default: userEvent } = await import('@testing-library/user-event');
    const user = userEvent.setup();
    const onEnlarge = vi.fn();
    render(ProductCodeCell, { props: { product: WITH_IMG, onEnlarge } });
    await user.click(screen.getByRole('button', { name: /keychain a/i }));
    expect(onEnlarge).toHaveBeenCalledWith(WITH_IMG);
  });
});
