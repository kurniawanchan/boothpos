import { describe, it, expect, vi, beforeEach } from 'vitest';
import { render, screen, waitFor, within } from '@testing-library/vue';
import { createPinia, setActivePinia } from 'pinia';
import ReasonCodesSection from '../../resources/js/views/settings/ReasonCodesSection.vue';
import { listReasonCodes, createReasonCode, updateReasonCode } from '../../resources/js/api/reasons';

vi.mock('../../resources/js/api/reasons', () => ({
  listReasonCodes: vi.fn(),
  createReasonCode: vi.fn(),
  updateReasonCode: vi.fn(),
}));

// 043 (T020) — bagian Pengaturan "Kode alasan": daftar per kategori, aktif/nonaktif, tambah/ubah.
const SOLD_OUT = [
  { id: 1, category: 'sold_out', code: 'stok_habis', label: 'Stok habis', label_id: 'Stok habis', label_en: 'Out of stock', is_active: true, sort: 10 },
  { id: 2, category: 'sold_out', code: 'rusak', label: 'Rusak', label_id: 'Bahan rusak', label_en: 'Spoiled', is_active: false, sort: 20 },
];
const DISCONTINUE = [
  { id: 7, category: 'discontinue', code: 'musiman', label: 'Musiman', label_id: 'Menu musiman selesai', label_en: 'Seasonal ended', is_active: true, sort: 10 },
];

function renderSection() {
  const pinia = createPinia();
  setActivePinia(pinia);
  return render(ReasonCodesSection, { global: { plugins: [pinia] } });
}

async function userEv() {
  const { default: userEvent } = await import('@testing-library/user-event');
  return userEvent.setup();
}

describe('ReasonCodesSection', () => {
  beforeEach(() => {
    vi.clearAllMocks();
    listReasonCodes.mockImplementation(async ({ category }) => ({ data: category === 'discontinue' ? DISCONTINUE : category === 'sold_out' ? SOLD_OUT : [] }));
    createReasonCode.mockResolvedValue({ id: 9 });
    updateReasonCode.mockResolvedValue({ id: 1 });
  });

  it('loads the first category with inactive codes included and shows both labels and the code', async () => {
    renderSection();
    expect(await screen.findByText('Stok habis')).toBeInTheDocument();
    expect(listReasonCodes).toHaveBeenCalledWith({ category: 'sold_out', include_inactive: 1 });
    expect(screen.getByText('Out of stock')).toBeInTheDocument();
    expect(screen.getByText('stok_habis')).toBeInTheDocument();
    // yang nonaktif tetap tampil supaya admin bisa mengaktifkannya lagi
    expect(screen.getByText('Bahan rusak')).toBeInTheDocument();
  });

  it('has one tab per category and loads that category when switched', async () => {
    const user = await userEv();
    renderSection();
    await screen.findByText('Stok habis');

    expect(screen.getAllByRole('tab').map((t) => t.textContent.trim())).toEqual(['Habis (86)', 'Diskontinu', 'Tolak persetujuan']);
    await user.click(screen.getByRole('tab', { name: 'Diskontinu' }));

    expect(await screen.findByText('Menu musiman selesai')).toBeInTheDocument();
    expect(listReasonCodes).toHaveBeenLastCalledWith({ category: 'discontinue', include_inactive: 1 });
    expect(screen.queryByText('Stok habis')).not.toBeInTheDocument();
  });

  it('shows an empty message for a category without reasons', async () => {
    const user = await userEv();
    renderSection();
    await screen.findByText('Stok habis');
    await user.click(screen.getByRole('tab', { name: 'Tolak persetujuan' }));
    expect(await screen.findByText(/belum ada alasan di kategori ini/i)).toBeInTheDocument();
  });

  it('toggles active through PUT with only is_active, then reloads', async () => {
    const user = await userEv();
    renderSection();
    await screen.findByText('Stok habis');

    const active = screen.getByRole('switch', { name: /aktifkan alasan stok habis/i });
    expect(active).toBeChecked();
    await user.click(active);

    await waitFor(() => expect(updateReasonCode).toHaveBeenCalledWith(1, { is_active: false }));
    await waitFor(() => expect(listReasonCodes).toHaveBeenCalledTimes(2));
    expect(screen.getByRole('switch', { name: /aktifkan alasan bahan rusak/i })).not.toBeChecked();
  });

  it('adds a reason in the current category and suggests the next sort order', async () => {
    const user = await userEv();
    renderSection();
    await screen.findByText('Stok habis');

    await user.click(screen.getByRole('button', { name: /tambah alasan/i }));
    const form = screen.getByTestId('reason-form');
    expect(within(form).getByLabelText(/^urutan/i)).toHaveValue(30); // setelah urutan terakhir (20) + 10

    await user.type(within(form).getByLabelText(/^kode/i), 'tutup_awal');
    await user.type(within(form).getByLabelText(/label \(indonesia\)/i), 'Tutup lebih awal');
    await user.type(within(form).getByLabelText(/label \(inggris\)/i), 'Closing early');
    await user.click(within(form).getByRole('button', { name: /^simpan$/i }));

    await waitFor(() =>
      expect(createReasonCode).toHaveBeenCalledWith({ category: 'sold_out', code: 'tutup_awal', label_id: 'Tutup lebih awal', label_en: 'Closing early', sort: 30 })
    );
    await waitFor(() => expect(screen.queryByTestId('reason-form')).not.toBeInTheDocument());
  });

  it('refuses an invalid code or empty labels before calling the server', async () => {
    const user = await userEv();
    renderSection();
    await screen.findByText('Stok habis');

    await user.click(screen.getByRole('button', { name: /tambah alasan/i }));
    const form = screen.getByTestId('reason-form');
    await user.type(within(form).getByLabelText(/^kode/i), 'Tidak Valid!');
    await user.click(within(form).getByRole('button', { name: /^simpan$/i }));

    expect(await within(form).findByText(/huruf kecil, angka, dan garis bawah\./i)).toBeInTheDocument();
    expect(within(form).getAllByText('Wajib diisi.')).toHaveLength(2);
    expect(createReasonCode).not.toHaveBeenCalled();
  });

  it('edits labels and order but never the code, and does not send category/code', async () => {
    const user = await userEv();
    renderSection();
    await screen.findByText('Stok habis');

    await user.click(screen.getByRole('button', { name: /ubah alasan stok habis/i }));
    const form = screen.getByTestId('reason-form');
    expect(within(form).getByLabelText(/^kode/i)).toBeDisabled();
    const labelId = within(form).getByLabelText(/label \(indonesia\)/i);
    await user.clear(labelId);
    await user.type(labelId, 'Habis terjual');
    await user.click(within(form).getByRole('button', { name: /^simpan$/i }));

    await waitFor(() => expect(updateReasonCode).toHaveBeenCalledWith(1, { label_id: 'Habis terjual', label_en: 'Out of stock', sort: 10 }));
    expect(createReasonCode).not.toHaveBeenCalled();
  });

  it('maps a server validation error (duplicate code) onto the field', async () => {
    const user = await userEv();
    createReasonCode.mockRejectedValueOnce(Object.assign(new Error('invalid'), { isValidation: true, errors: { code: ['Kode sudah dipakai.'] } }));
    renderSection();
    await screen.findByText('Stok habis');

    await user.click(screen.getByRole('button', { name: /tambah alasan/i }));
    const form = screen.getByTestId('reason-form');
    await user.type(within(form).getByLabelText(/^kode/i), 'stok_habis');
    await user.type(within(form).getByLabelText(/label \(indonesia\)/i), 'A');
    await user.type(within(form).getByLabelText(/label \(inggris\)/i), 'B');
    await user.click(within(form).getByRole('button', { name: /^simpan$/i }));

    expect(await within(form).findByText('Kode sudah dipakai.')).toBeInTheDocument();
  });

  it('shows an error state with retry when loading fails', async () => {
    const user = await userEv();
    listReasonCodes.mockRejectedValueOnce(new Error('network'));
    renderSection();

    const alert = await screen.findByRole('alert');
    expect(alert).toHaveTextContent('Gagal memuat data');
    await user.click(within(alert).getByRole('button', { name: /coba lagi/i }));
    expect(await screen.findByText('Stok habis')).toBeInTheDocument();
  });
});
