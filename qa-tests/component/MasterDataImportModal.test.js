import { describe, it, expect, vi, beforeEach } from 'vitest';
import { render, screen, fireEvent, waitFor } from '@testing-library/vue';
import { createPinia, setActivePinia } from 'pinia';
import MasterDataImportModal from '../../resources/js/components/masterData/MasterDataImportModal.vue';
import { importMasterData } from '../../resources/js/api/masterData';
import { ApiError } from '../../resources/js/utils/errors';

vi.mock('../../resources/js/api/masterData', () => ({
  importMasterData: vi.fn(),
  downloadImportTemplate: vi.fn(),
}));

function xlsxFile(name = 'master-data.xlsx') {
  return new File(['dummy'], name, { type: 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet' });
}

function oversizedXlsxFile() {
  const file = xlsxFile('besar.xlsx');
  Object.defineProperty(file, 'size', { value: 11 * 1024 * 1024 });
  return file;
}

async function chooseFile(file) {
  const input = screen.getByLabelText(/berkas \.xlsx/i);
  // fireEvent.change (not .update) — this is a file input driven by a
  // native `change` event, not a v-model text field.
  await fireEvent.change(input, { target: { files: [file] } });
  return input;
}

function renderModal() {
  const pinia = createPinia();
  setActivePinia(pinia);
  return render(MasterDataImportModal, { props: { open: true }, global: { plugins: [pinia] } });
}

describe('MasterDataImportModal', () => {
  beforeEach(() => {
    vi.clearAllMocks();
  });

  it('disables Pratinjau until a file is chosen', () => {
    renderModal();
    expect(screen.getByRole('button', { name: /pratinjau/i })).toBeDisabled();
  });

  it('rejects a non-.xlsx file client-side without calling the API', async () => {
    renderModal();
    await chooseFile(new File(['x'], 'data.txt', { type: 'text/plain' }));
    expect(screen.getByText(/harus berformat \.xlsx/i)).toBeInTheDocument();
    expect(screen.getByRole('button', { name: /pratinjau/i })).toBeDisabled();
    expect(importMasterData).not.toHaveBeenCalled();
  });

  it('rejects an oversized file client-side without calling the API', async () => {
    renderModal();
    await chooseFile(oversizedXlsxFile());
    expect(screen.getByText(/maksimal 10 mb/i)).toBeInTheDocument();
    expect(importMasterData).not.toHaveBeenCalled();
  });

  it('renders shape (a) field validation errors from the `errors.file` array', async () => {
    importMasterData.mockRejectedValue(
      new ApiError('Validasi gagal', { status: 422, errors: { file: ['Ukuran berkas impor maksimal 10 MB.'] } })
    );
    renderModal();
    await chooseFile(xlsxFile());
    await fireEvent.click(screen.getByRole('button', { name: /pratinjau/i }));
    expect(await screen.findByText('Ukuran berkas impor maksimal 10 MB.')).toBeInTheDocument();
  });

  it('renders shape (b) defensive rejection as a plain message with no errors key', async () => {
    importMasterData.mockRejectedValue(new ApiError('Tipe berkas tidak didukung. Hanya .xlsx.', { status: 422, errors: null }));
    renderModal();
    await chooseFile(xlsxFile());
    await fireEvent.click(screen.getByRole('button', { name: /pratinjau/i }));
    expect(await screen.findByText('Tipe berkas tidak didukung. Hanya .xlsx.')).toBeInTheDocument();
  });

  it('renders shape (c) row-level errors as a scannable table and states nothing changed', async () => {
    const body = {
      message: 'Impor dibatalkan: ada baris yang tidak valid. Tidak ada data yang diubah.',
      applied: false,
      dry_run: false,
      sheets: {},
      ignored_sheets: [],
      errors: [
        { sheet: 'products', row: 12, column: 'category_code', message: "Kategori dengan kode 'ZZ' tidak ditemukan." },
        { sheet: null, row: null, column: null, message: 'Berkas tidak berisi sheet yang dikenali.' },
      ],
    };
    importMasterData.mockRejectedValue(new ApiError(body.message, { status: 422, errors: body.errors, data: body }));
    renderModal();
    await chooseFile(xlsxFile());
    await fireEvent.click(screen.getByRole('button', { name: /pratinjau/i }));

    expect(await screen.findByText(body.message)).toBeInTheDocument();
    expect(screen.getByText(/perbaiki baris di bawah/i)).toBeInTheDocument();
    expect(screen.getByText("Kategori dengan kode 'ZZ' tidak ditemukan.")).toBeInTheDocument();
    expect(screen.getByText('products')).toBeInTheDocument();
    expect(screen.getByText('12')).toBeInTheDocument();
    expect(screen.getByText('category_code')).toBeInTheDocument();
    // null sheet/row/column render gracefully, not the literal word "null".
    expect(screen.getByText('Berkas tidak berisi sheet yang dikenali.')).toBeInTheDocument();
    expect(screen.queryByText(/^null$/)).not.toBeInTheDocument();
    expect(screen.getAllByText('—').length).toBeGreaterThanOrEqual(3);
  });

  it('shows a dry-run preview summary and offers to apply for real', async () => {
    importMasterData.mockResolvedValue({
      message: 'Pratinjau impor: tidak ada galat. Belum ada data yang diubah.',
      applied: false,
      dry_run: true,
      sheets: { artists: { rows: 2, created: 1, updated: 1, unchanged: 0 } },
      ignored_sheets: ['Catatan Toko'],
      errors: [],
    });
    renderModal();
    await chooseFile(xlsxFile());
    await fireEvent.click(screen.getByRole('button', { name: /pratinjau/i }));

    expect(await screen.findByText(/belum ada data yang diubah/i)).toBeInTheDocument();
    expect(screen.getByText('Penjual')).toBeInTheDocument();
    expect(screen.getByText('+1 baru')).toBeInTheDocument();
    expect(screen.getByText(/catatan toko/i)).toBeInTheDocument();
    expect(screen.getByRole('button', { name: /terapkan impor/i })).toBeInTheDocument();
  });

  it('applies for real after a clean preview and emits `imported`', async () => {
    importMasterData
      .mockResolvedValueOnce({
        message: 'Pratinjau impor: tidak ada galat. Belum ada data yang diubah.',
        applied: false,
        dry_run: true,
        sheets: { artists: { rows: 1, created: 1, updated: 0, unchanged: 0 } },
        ignored_sheets: [],
        errors: [],
      })
      .mockResolvedValueOnce({
        message: 'Impor berhasil.',
        applied: true,
        dry_run: false,
        sheets: { artists: { rows: 1, created: 1, updated: 0, unchanged: 0 } },
        ignored_sheets: [],
        errors: [],
      });

    const { emitted } = renderModal();
    await chooseFile(xlsxFile());
    await fireEvent.click(screen.getByRole('button', { name: /pratinjau/i }));
    await screen.findByRole('button', { name: /terapkan impor/i });

    await fireEvent.click(screen.getByRole('button', { name: /terapkan impor/i }));

    await waitFor(() => expect(emitted().imported).toBeTruthy());
    expect(await screen.findByText(/impor diterapkan/i)).toBeInTheDocument();
    expect(importMasterData).toHaveBeenCalledTimes(2);
    expect(importMasterData).toHaveBeenNthCalledWith(1, expect.any(File), { dryRun: true, images: [] });
    expect(importMasterData).toHaveBeenNthCalledWith(2, expect.any(File), { dryRun: false, images: [] });
  });
});
