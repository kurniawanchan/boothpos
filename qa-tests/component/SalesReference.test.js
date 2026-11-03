import { describe, it, expect, vi, beforeEach } from 'vitest';
import { render, screen, waitFor } from '@testing-library/vue';
import OrderReferenceModal from '../../resources/js/components/sales/OrderReferenceModal.vue';
import { getReference, getReferenceImageUrl, saveReference, deleteReference } from '../../resources/js/api/orders';

vi.mock('../../resources/js/api/orders', () => ({
  getReference: vi.fn(), getReferenceImageUrl: vi.fn(), saveReference: vi.fn(), deleteReference: vi.fn(),
}));

const REF = (over = {}) => ({ note: 'Foto WA dari Budi', has_image: true, updated_at: '2026-11-01T10:00:00Z', updated_by_name: 'Kasir A', can_edit: true, ...over });

// 054 (US8) — lihat dan ubah referensi transaksi.
describe('OrderReferenceModal (054)', () => {
  beforeEach(() => {
    vi.clearAllMocks();
    getReference.mockResolvedValue(REF());
    getReferenceImageUrl.mockResolvedValue('blob:wa-image');
    saveReference.mockResolvedValue(REF({ note: 'revisi' }));
    deleteReference.mockResolvedValue(REF({ note: null, has_image: false }));
    globalThis.URL.revokeObjectURL = vi.fn();
  });

  async function open(props = {}) {
    const { default: userEvent } = await import('@testing-library/user-event');
    const user = userEvent.setup();
    const utils = render(OrderReferenceModal, { props: { open: true, orderId: 9, ...props } });
    await waitFor(() => expect(getReference).toHaveBeenCalledWith(9));
    return { user, ...utils };
  }

  it('shows the note and the image to a viewer who cannot edit, with no edit controls', async () => {
    getReference.mockResolvedValue(REF({ can_edit: false }));
    await open();

    expect(await screen.findByText('Foto WA dari Budi')).toBeInTheDocument();
    expect(await screen.findByAltText('Referensi transaksi')).toHaveAttribute('src', 'blob:wa-image');
    expect(screen.queryByRole('textbox')).not.toBeInTheDocument();
    expect(screen.queryByRole('button', { name: 'Simpan' })).not.toBeInTheDocument();
    expect(screen.queryByRole('button', { name: /hapus referensi/i })).not.toBeInTheDocument();
  });

  it('does not request an image when the reference has none', async () => {
    getReference.mockResolvedValue(REF({ has_image: false }));
    await open();
    expect(await screen.findByRole('textbox')).toHaveValue('Foto WA dari Budi');

    expect(getReferenceImageUrl).not.toHaveBeenCalled();
  });

  it('lets an editor change the note; Save is disabled until something changes', async () => {
    const { user, emitted } = await open();
    const box = await screen.findByRole('textbox');
    expect(box).toHaveValue('Foto WA dari Budi');
    expect(screen.getByRole('button', { name: 'Simpan' })).toBeDisabled();

    await user.clear(box);
    await user.type(box, 'revisi');
    await user.click(screen.getByRole('button', { name: 'Simpan' }));

    expect(saveReference).toHaveBeenCalledWith(9, { note: 'revisi', image: null, removeImage: false });
    await waitFor(() => expect(emitted().saved).toHaveLength(1));
  });

  it('rejects a file that is not an accepted image, or is too large, before sending anything', async () => {
    await open(); // can_edit
    await screen.findByRole('textbox');
    const input = document.querySelector('input[type="file"]');
    // `accept` menyaring di dialog berkas browser, tetapi seret-lepas bisa melewatinya — komponen harus menjaga sendiri.
    const { default: userEvent } = await import('@testing-library/user-event');
    const user = userEvent.setup({ applyAccept: false });

    await user.upload(input, new File(['x'], 'dok.pdf', { type: 'application/pdf' }));
    expect(await screen.findByRole('alert')).toHaveTextContent(/JPEG, PNG, atau WebP/i);

    const big = new File([new Uint8Array(5 * 1024 * 1024 + 1)], 'besar.png', { type: 'image/png' });
    await user.upload(input, big);
    expect(await screen.findByRole('alert')).toHaveTextContent(/5 MB/);

    expect(saveReference).not.toHaveBeenCalled();
    expect(screen.getByRole('button', { name: 'Simpan' })).toBeDisabled();
  });

  it('sends an accepted image together with the note', async () => {
    const { user } = await open({});
    await screen.findByRole('textbox');
    const file = new File(['png'], 'baru.png', { type: 'image/png' });

    await user.upload(document.querySelector('input[type="file"]'), file);
    await user.click(screen.getByRole('button', { name: 'Simpan' }));

    expect(saveReference).toHaveBeenCalledWith(9, { note: undefined, image: file, removeImage: false });
  });

  it('removes only the image when asked, keeping the note', async () => {
    const { user } = await open();
    await screen.findByRole('textbox');

    await user.click(screen.getByRole('button', { name: /hapus gambar/i }));
    await user.click(screen.getByRole('button', { name: 'Simpan' }));

    expect(saveReference).toHaveBeenCalledWith(9, { note: undefined, image: null, removeImage: true });
  });

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
    getReference.mockResolvedValue(REF({ note: null, has_image: false }));
    await open();

    expect(await screen.findByRole('textbox')).toHaveValue('');
    expect(screen.queryByRole('button', { name: /hapus referensi/i })).not.toBeInTheDocument();
  });

  it('shows a retry when loading fails instead of an empty form', async () => {
    getReference.mockRejectedValueOnce(new Error('gagal'));
    await open();

    expect(await screen.findByRole('button', { name: /coba lagi/i })).toBeInTheDocument();
    expect(screen.queryByRole('textbox')).not.toBeInTheDocument();
  });
});
