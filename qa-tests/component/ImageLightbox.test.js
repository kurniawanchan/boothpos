import { describe, it, expect } from 'vitest';
import { render, screen, fireEvent } from '@testing-library/vue';
import ImageLightbox from '../../resources/js/components/ui/ImageLightbox.vue';

const IMAGES = [
  { src: 'blob:a', alt: 'A' },
  { src: 'blob:b', alt: 'B' },
  { src: 'blob:c', alt: 'C' },
];

// 055 — mode galeri opsional; pemakaian lama (`src` saja) tidak berubah.
describe('ImageLightbox', () => {
  it('with only src behaves as before: one image, no navigation', () => {
    render(ImageLightbox, { props: { open: true, src: 'blob:qr', alt: 'QR' } });

    expect(screen.getByAltText('QR')).toHaveAttribute('src', 'blob:qr');
    expect(screen.queryByRole('button', { name: 'Berikutnya' })).not.toBeInTheDocument();
    expect(screen.queryByRole('button', { name: 'Sebelumnya' })).not.toBeInTheDocument();
  });

  it('renders nothing when closed', () => {
    render(ImageLightbox, { props: { open: false, images: IMAGES, index: 0 } });
    expect(screen.queryByRole('img')).not.toBeInTheDocument();
  });

  it('shows the image at index with a counter and steps with the buttons', async () => {
    const { emitted, rerender } = render(ImageLightbox, { props: { open: true, images: IMAGES, index: 1 } });

    expect(screen.getByAltText('B')).toHaveAttribute('src', 'blob:b');
    expect(screen.getByText('2 / 3')).toBeInTheDocument();

    await fireEvent.click(screen.getByRole('button', { name: 'Berikutnya' }));
    expect(emitted()['update:index'].at(-1)).toEqual([2]);

    await fireEvent.click(screen.getByRole('button', { name: 'Sebelumnya' }));
    expect(emitted()['update:index'].at(-1)).toEqual([0]);

    await rerender({ index: 2 });
    expect(screen.getByAltText('C')).toBeInTheDocument();
  });

  it('stops at both ends instead of wrapping', async () => {
    const { emitted, rerender } = render(ImageLightbox, { props: { open: true, images: IMAGES, index: 0 } });
    expect(screen.getByRole('button', { name: 'Sebelumnya' })).toBeDisabled();

    await rerender({ index: 2 });
    expect(screen.getByRole('button', { name: 'Berikutnya' })).toBeDisabled();
    await fireEvent.keyDown(window, { key: 'ArrowRight' });
    expect(emitted()['update:index']).toBeUndefined();
  });

  it('moves with the arrow keys while open', async () => {
    const { emitted } = render(ImageLightbox, { props: { open: true, images: IMAGES, index: 1 } });

    await fireEvent.keyDown(window, { key: 'ArrowRight' });
    expect(emitted()['update:index'].at(-1)).toEqual([2]);
    await fireEvent.keyDown(window, { key: 'ArrowLeft' });
    expect(emitted()['update:index'].at(-1)).toEqual([0]);
  });

  it('a single-item gallery has no navigation', () => {
    render(ImageLightbox, { props: { open: true, images: [IMAGES[0]], index: 0 } });

    expect(screen.getByAltText('A')).toBeInTheDocument();
    expect(screen.queryByRole('button', { name: 'Berikutnya' })).not.toBeInTheDocument();
    expect(screen.queryByText('1 / 1')).not.toBeInTheDocument();
  });

  it('emits close from the close button', async () => {
    const { emitted } = render(ImageLightbox, { props: { open: true, images: IMAGES, index: 0 } });

    await fireEvent.click(screen.getByRole('button', { name: 'Tutup' }));
    expect(emitted().close).toHaveLength(1);
  });
});
