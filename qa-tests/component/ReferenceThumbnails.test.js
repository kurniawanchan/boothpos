import { describe, it, expect, vi, beforeEach } from 'vitest';
import { render, screen, fireEvent, waitFor } from '@testing-library/vue';
import ReferenceThumbnails from '../../resources/js/components/sales/ReferenceThumbnails.vue';
import { getReferenceImageUrl } from '../../resources/js/api/orders';

vi.mock('../../resources/js/api/orders', () => ({ getReferenceImageUrl: vi.fn() }));

const img = (id) => ({ id, mime: 'image/png', size_bytes: 10, position: id, created_at: null });

function deferred() {
  let resolve;
  let reject;
  const promise = new Promise((res, rej) => { resolve = res; reject = rej; });
  return { promise, resolve, reject };
}

// 055 (US1) — gambar referensi sebagai thumbnail, diambil per id dengan token (blob URL).
describe('ReferenceThumbnails', () => {
  beforeEach(() => {
    vi.clearAllMocks();
    getReferenceImageUrl.mockImplementation((orderId, id) => Promise.resolve(`blob:${id}`));
    globalThis.URL.revokeObjectURL = vi.fn();
  });

  it('renders one thumbnail per image in order', async () => {
    render(ReferenceThumbnails, { props: { orderId: 9, images: [img(1), img(2), img(3)] } });

    const thumbs = await screen.findAllByTestId('reference-thumbnail');
    expect(thumbs).toHaveLength(3);
    await waitFor(() => expect(thumbs[2].querySelector('img')).toHaveAttribute('src', 'blob:3'));
    expect(thumbs[0].querySelector('img')).toHaveAttribute('src', 'blob:1');
    expect(getReferenceImageUrl).toHaveBeenCalledWith(9, 1);
  });

  it('never has more than three image requests in flight', async () => {
    const pending = [];
    getReferenceImageUrl.mockImplementation(() => { const d = deferred(); pending.push(d); return d.promise; });
    render(ReferenceThumbnails, { props: { orderId: 9, images: [1, 2, 3, 4, 5].map(img) } });

    await waitFor(() => expect(getReferenceImageUrl).toHaveBeenCalledTimes(3));
    pending[0].resolve('blob:1');
    await waitFor(() => expect(getReferenceImageUrl).toHaveBeenCalledTimes(4));
    expect(getReferenceImageUrl).not.toHaveBeenCalledTimes(5);
  });

  it('shows a placeholder for an image that fails and still shows the others', async () => {
    getReferenceImageUrl.mockImplementation((o, id) => (id === 2 ? Promise.reject(new Error('404')) : Promise.resolve(`blob:${id}`)));
    render(ReferenceThumbnails, { props: { orderId: 9, images: [img(1), img(2), img(3)] } });

    expect(await screen.findByText('Gambar tidak tersedia')).toBeInTheDocument();
    const thumbs = screen.getAllByTestId('reference-thumbnail');
    await waitFor(() => expect(thumbs[2].querySelector('img')).toHaveAttribute('src', 'blob:3'));
    expect(thumbs[1].querySelector('img')).toBeNull();
  });

  it('opens the full-size gallery at the clicked image', async () => {
    render(ReferenceThumbnails, { props: { orderId: 9, images: [img(1), img(2)] } });
    const thumbs = await screen.findAllByTestId('reference-thumbnail');
    await waitFor(() => expect(thumbs[1].querySelector('img')).toHaveAttribute('src', 'blob:2'));

    await fireEvent.click(thumbs[1].querySelector('button'));

    expect(await screen.findByText('2 / 2')).toBeInTheDocument();
  });

  it('revokes blob URLs of images that disappear and on unmount', async () => {
    const { rerender, unmount } = render(ReferenceThumbnails, { props: { orderId: 9, images: [img(1), img(2)] } });
    const thumbs = await screen.findAllByTestId('reference-thumbnail');
    await waitFor(() => expect(thumbs[1].querySelector('img')).toHaveAttribute('src', 'blob:2'));

    await rerender({ images: [img(1)] });
    expect(URL.revokeObjectURL).toHaveBeenCalledWith('blob:2');
    expect(URL.revokeObjectURL).not.toHaveBeenCalledWith('blob:1');

    unmount();
    expect(URL.revokeObjectURL).toHaveBeenCalledWith('blob:1');
  });

  it('read-only by default; removable mode offers a toggle per image and shows marked ones', async () => {
    const { emitted, rerender } = render(ReferenceThumbnails, { props: { orderId: 9, images: [img(1), img(2)] } });
    await screen.findAllByTestId('reference-thumbnail');
    expect(screen.queryByRole('button', { name: /hapus gambar/i })).not.toBeInTheDocument();

    await rerender({ removable: true, marked: [] });
    const removeButtons = screen.getAllByRole('button', { name: /hapus gambar/i });
    expect(removeButtons).toHaveLength(2);
    await fireEvent.click(removeButtons[1]);
    expect(emitted()['toggle-remove'].at(-1)).toEqual([2]);

    await rerender({ marked: [2] });
    expect(screen.getByRole('button', { name: /pertahankan gambar/i })).toBeInTheDocument();
    expect(screen.getByText('Akan dihapus saat disimpan')).toBeInTheDocument();
  });
});
