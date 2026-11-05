import { describe, it, expect, vi, beforeEach } from 'vitest';
import { render, screen, waitFor, within } from '@testing-library/vue';
import OrderReferenceModal from '../../resources/js/components/sales/OrderReferenceModal.vue';
import { getReference, getReferenceImageUrl, saveReference, deleteReference } from '../../resources/js/api/orders';
import { createPinia, setActivePinia } from 'pinia';

vi.mock('../../resources/js/api/orders', () => ({
  getReference: vi.fn(), getReferenceImageUrl: vi.fn(), saveReference: vi.fn(), deleteReference: vi.fn(),
}));

const img = (id) => ({ id, mime: 'image/png', size_bytes: 10, position: id, created_at: null });
const REF = (over = {}) => {
  const images = over.images ?? [img(1)];
  return {
    note: 'Foto WA dari Budi', images, image_count: images.length, has_image: images.length > 0, max_images: 10,
    updated_at: '2026-11-01T10:00:00Z', updated_by_name: 'Kasir A', can_edit: true, ...over,
  };
};
const png = (name) => new File(['png'], name, { type: 'image/png' });

// 054 (US8) + 055 (US2/US3) — lihat dan ubah referensi transaksi dengan banyak gambar.
describe('OrderReferenceModal', () => {
  beforeEach(() => {
    vi.clearAllMocks();
    getReference.mockResolvedValue(REF());
    getReferenceImageUrl.mockImplementation((orderId, id) => Promise.resolve(`blob:img-${id}`));
    saveReference.mockResolvedValue(REF({ note: 'revisi' }));
    deleteReference.mockResolvedValue(REF({ note: null, images: [] }));
    globalThis.URL.revokeObjectURL = vi.fn();
    globalThis.URL.createObjectURL = vi.fn((f) => `blob:picked-${f.name}`);
  });

  async function open(props = {}, setup = {}) {
    const { default: userEvent } = await import('@testing-library/user-event');
    const user = userEvent.setup(setup);
    // 057 — OrderReferenceModal membaca izin aksi dari store auth (kosmetik), jadi butuh Pinia.
    const pinia = createPinia();
    setActivePinia(pinia);
    const utils = render(OrderReferenceModal, { props: { open: true, orderId: 9, ...props }, global: { plugins: [pinia] } });
    await waitFor(() => expect(getReference).toHaveBeenCalledWith(9));
    return { user, ...utils };
  }

  const fileInput = () => document.querySelector('input[type="file"]');

  // --- lihat ---------------------------------------------------------------------

  it('shows the note and every image to a viewer who cannot edit, with no edit controls', async () => {
    getReference.mockResolvedValue(REF({ can_edit: false, images: [img(1), img(2)] }));
    await open();

    expect(await screen.findByText('Foto WA dari Budi')).toBeInTheDocument();
    expect(await screen.findAllByTestId('reference-thumbnail')).toHaveLength(2);
    expect(getReferenceImageUrl).toHaveBeenCalledWith(9, 2);
    expect(screen.queryByRole('textbox')).not.toBeInTheDocument();
    expect(fileInput()).toBeNull();
    expect(screen.queryByRole('button', { name: 'Simpan' })).not.toBeInTheDocument();
    expect(screen.queryByRole('button', { name: /hapus gambar/i })).not.toBeInTheDocument();
  });

  it('does not request any image when the reference has none', async () => {
    getReference.mockResolvedValue(REF({ images: [] }));
    await open();
    expect(await screen.findByRole('textbox')).toHaveValue('Foto WA dari Budi');

    expect(getReferenceImageUrl).not.toHaveBeenCalled();
  });

  it('lets an editor change the note; Save is disabled until something changes', async () => {
    const { user, emitted } = await open();
    const box = await screen.findByRole('textbox');
    expect(screen.getByRole('button', { name: 'Simpan' })).toBeDisabled();

    await user.clear(box);
    await user.type(box, 'revisi');
    await user.click(screen.getByRole('button', { name: 'Simpan' }));

    expect(saveReference).toHaveBeenCalledWith(9, { note: 'revisi', images: [], removeImageIds: [] });
    await waitFor(() => expect(emitted().saved).toHaveLength(1));
  });

  // --- US2: banyak gambar sekaligus ---------------------------------------------

  it('offers a multiple-file picker limited to the accepted types', async () => {
    await open();
    await screen.findByRole('textbox');

    expect(fileInput()).toHaveAttribute('multiple');
    expect(fileInput()).toHaveAttribute('accept', 'image/jpeg,image/png,image/webp');
    expect(screen.getByText('Masih bisa menambah 9 gambar.')).toBeInTheDocument();
  });

  it('sends several picked images in ONE save, after previewing them', async () => {
    const { user } = await open();
    await screen.findByRole('textbox');
    const files = [png('a.png'), png('b.png'), png('c.png')];

    await user.upload(fileInput(), files);
    expect(screen.getAllByTestId('picked-image')).toHaveLength(3);
    expect(screen.getByText('Masih bisa menambah 6 gambar.')).toBeInTheDocument();
    await user.click(screen.getByRole('button', { name: 'Simpan' }));

    expect(saveReference).toHaveBeenCalledTimes(1);
    expect(saveReference).toHaveBeenCalledWith(9, { note: undefined, images: files, removeImageIds: [] });
  });

  it('names refused files with the reason and keeps the valid ones from the same selection', async () => {
    // `accept` menyaring di dialog berkas browser, tetapi seret-lepas bisa melewatinya — komponen harus menjaga sendiri.
    const { user } = await open({}, { applyAccept: false });
    await screen.findByRole('textbox');
    const ok = png('ok.png');

    await user.upload(fileInput(), [
      new File(['x'], 'dok.pdf', { type: 'application/pdf' }),
      ok,
      new File([new Uint8Array(5 * 1024 * 1024 + 1)], 'besar.png', { type: 'image/png' }),
    ]);

    const alert = await screen.findByRole('alert');
    expect(alert).toHaveTextContent('dok.pdf');
    expect(alert).toHaveTextContent(/JPEG, PNG, atau WebP/);
    expect(alert).toHaveTextContent('besar.png');
    expect(alert).toHaveTextContent(/5 MB/);
    expect(screen.getAllByTestId('picked-image')).toHaveLength(1);

    await user.click(screen.getByRole('button', { name: 'Simpan' }));
    expect(saveReference).toHaveBeenCalledWith(9, { note: undefined, images: [ok], removeImageIds: [] });
  });

  it('keeps only what fits under max_images and says the limit was reached', async () => {
    getReference.mockResolvedValue(REF({ images: [1, 2, 3, 4, 5, 6, 7, 8].map(img) }));
    const { user } = await open();
    await screen.findByRole('textbox');

    await user.upload(fileInput(), [png('1.png'), png('2.png'), png('3.png')]);

    expect(screen.getAllByTestId('picked-image')).toHaveLength(2);
    expect(screen.getByRole('alert')).toHaveTextContent('3.png');
    expect(screen.getByText('Penjualan ini sudah punya 10 gambar (batas maksimal).')).toBeInTheDocument();
    expect(fileInput()).toBeDisabled();
  });

  it('a picked image can be dropped from the selection before saving', async () => {
    const { user } = await open();
    await screen.findByRole('textbox');
    await user.upload(fileInput(), [png('a.png'), png('b.png')]);

    await user.click(within(screen.getAllByTestId('picked-image')[0]).getByRole('button', { name: 'Batalkan pilihan' }));

    expect(screen.getAllByTestId('picked-image')).toHaveLength(1);
    expect(URL.revokeObjectURL).toHaveBeenCalledWith('blob:picked-a.png');
  });

  it('on a limit conflict (409) keeps the dialog open with the picks and reloads the stored count', async () => {
    const { user, emitted } = await open();
    await screen.findByRole('textbox');
    await user.upload(fileInput(), [png('a.png')]);
    saveReference.mockRejectedValueOnce({ status: 409, isConflict: true, data: { code: 'reference_image_limit', remaining: 0 } });
    getReference.mockResolvedValueOnce(REF({ images: [1, 2, 3, 4, 5, 6, 7, 8, 9, 10].map(img) }));

    await user.click(screen.getByRole('button', { name: 'Simpan' }));

    await waitFor(() => expect(getReference).toHaveBeenCalledTimes(2));
    expect(emitted().saved).toBeUndefined();
    expect(screen.getAllByTestId('picked-image')).toHaveLength(1);
    expect(await screen.findByText('Penjualan ini sudah punya 10 gambar (batas maksimal).')).toBeInTheDocument();
  });

  it('shows a server-side file error (422) inline', async () => {
    const { user } = await open();
    await screen.findByRole('textbox');
    await user.upload(fileInput(), [png('a.png')]);
    saveReference.mockRejectedValueOnce({ status: 422, isValidation: true, errors: { 'images.0': ['Berkas harus berupa gambar JPEG, PNG, atau WebP.'] } });

    await user.click(screen.getByRole('button', { name: 'Simpan' }));

    expect(await screen.findByRole('alert')).toHaveTextContent('Berkas harus berupa gambar JPEG, PNG, atau WebP.');
  });

  // --- US3: hapus satu gambar ----------------------------------------------------

  it('marks one stored image for removal (undoable) and sends only its id', async () => {
    getReference.mockResolvedValue(REF({ images: [img(1), img(2), img(3)] }));
    const { user } = await open();
    await screen.findByRole('textbox');
    const thumbs = await screen.findAllByTestId('reference-thumbnail');

    await user.click(within(thumbs[1]).getByRole('button', { name: 'Hapus gambar' }));
    expect(within(thumbs[1]).getByText('Akan dihapus saat disimpan')).toBeInTheDocument();
    expect(screen.getByText('Masih bisa menambah 8 gambar.')).toBeInTheDocument();
    await user.click(screen.getByRole('button', { name: 'Simpan' }));

    expect(saveReference).toHaveBeenCalledWith(9, { note: undefined, images: [], removeImageIds: [2] });
  });

  it('undoing a mark leaves nothing to save', async () => {
    const { user } = await open();
    await screen.findByRole('textbox');
    const [thumb] = await screen.findAllByTestId('reference-thumbnail');

    await user.click(within(thumb).getByRole('button', { name: 'Hapus gambar' }));
    await user.click(within(thumb).getByRole('button', { name: 'Pertahankan gambar' }));

    expect(screen.getByRole('button', { name: 'Simpan' })).toBeDisabled();
  });

  it('adds and removes in the same single save', async () => {
    getReference.mockResolvedValue(REF({ images: [img(1), img(2)] }));
    const { user } = await open();
    await screen.findByRole('textbox');
    const thumbs = await screen.findAllByTestId('reference-thumbnail');
    const file = png('baru.png');

    await user.click(within(thumbs[0]).getByRole('button', { name: 'Hapus gambar' }));
    await user.upload(fileInput(), [file]);
    await user.click(screen.getByRole('button', { name: 'Simpan' }));

    expect(saveReference).toHaveBeenCalledTimes(1);
    expect(saveReference).toHaveBeenCalledWith(9, { note: undefined, images: [file], removeImageIds: [1] });
  });

  it('closing without saving discards the marks', async () => {
    const { user, rerender } = await open();
    await screen.findByRole('textbox');
    const [thumb] = await screen.findAllByTestId('reference-thumbnail');
    await user.click(within(thumb).getByRole('button', { name: 'Hapus gambar' }));

    await rerender({ open: false });
    await rerender({ open: true });
    const [reopened] = await screen.findAllByTestId('reference-thumbnail');

    expect(within(reopened).getByRole('button', { name: 'Hapus gambar' })).toBeInTheDocument();
    expect(saveReference).not.toHaveBeenCalled();
  });

  it('removing the last image of a note-less reference asks to delete the whole reference instead', async () => {
    getReference.mockResolvedValue(REF({ note: null, images: [img(1)] }));
    const { user, emitted } = await open();
    await screen.findByRole('textbox');
    const [thumb] = await screen.findAllByTestId('reference-thumbnail');

    await user.click(within(thumb).getByRole('button', { name: 'Hapus gambar' }));
    await user.click(screen.getByRole('button', { name: 'Simpan' }));

    expect(saveReference).not.toHaveBeenCalled();
    await user.click(await screen.findByRole('button', { name: /^hapus$/i }));
    await waitFor(() => expect(deleteReference).toHaveBeenCalledWith(9));
    await waitFor(() => expect(emitted().saved).toHaveLength(1));
  });

  // --- lainnya -------------------------------------------------------------------

  it('asks for confirmation before deleting the whole reference', async () => {
    const { user, emitted } = await open();
    await screen.findByRole('textbox');

    await user.click(screen.getByRole('button', { name: /hapus referensi/i }));
    expect(deleteReference).not.toHaveBeenCalled();

    await user.click(await screen.findByRole('button', { name: /^hapus$/i }));
    await waitFor(() => expect(deleteReference).toHaveBeenCalledWith(9));
    await waitFor(() => expect(emitted().saved).toHaveLength(1));
  });

  it('offers an empty form to an editor when the sale has no reference yet', async () => {
    getReference.mockResolvedValue(REF({ note: null, images: [] }));
    await open();

    expect(await screen.findByRole('textbox')).toHaveValue('');
    expect(screen.queryByRole('button', { name: /hapus referensi/i })).not.toBeInTheDocument();
    expect(screen.getByText('Masih bisa menambah 10 gambar.')).toBeInTheDocument();
  });

  it('shows a retry when loading fails instead of an empty form', async () => {
    getReference.mockRejectedValueOnce(new Error('gagal'));
    await open();

    expect(await screen.findByRole('button', { name: /coba lagi/i })).toBeInTheDocument();
    expect(screen.queryByRole('textbox')).not.toBeInTheDocument();
  });
});
