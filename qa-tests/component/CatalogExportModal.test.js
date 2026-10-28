import { describe, it, expect, vi, beforeEach } from 'vitest';
import { render, screen, waitFor } from '@testing-library/vue';
import { createPinia, setActivePinia } from 'pinia';
import CatalogExportModal from '../../resources/js/components/catalog/CatalogExportModal.vue';
import { useToastStore } from '../../resources/js/stores/toast';
import { getCatalog, downloadCatalogExcel } from '../../resources/js/api/catalog';
import { generateCatalogPdf } from '../../resources/js/utils/catalogPdf';

vi.mock('../../resources/js/api/catalog', () => ({ getCatalog: vi.fn(), downloadCatalogExcel: vi.fn() }));
vi.mock('../../resources/js/utils/catalogPdf', () => ({ generateCatalogPdf: vi.fn() }));

const CATALOG = (variants = 12, sellers = 3) => ({ generated_at: '2026-10-28T11:20:00+07:00', store: { name: 'Toko' }, totals: { sellers, variants }, sellers: variants ? [{ artist_id: 1, name: 'A', variants: [] }] : [] });

function mount(props = {}) {
  const pinia = createPinia();
  setActivePinia(pinia);
  const utils = render(CatalogExportModal, { props: { open: true, filters: {}, ...props }, global: { plugins: [pinia] } });
  return { pinia, ...utils };
}

// 049 (US1/US4) — dialog Katalog: ruang lingkup, keadaan kosong/gagal, dan unduh PDF.
describe('CatalogExportModal (049)', () => {
  beforeEach(() => {
    vi.clearAllMocks();
    getCatalog.mockResolvedValue(CATALOG());
    generateCatalogPdf.mockResolvedValue(3);
    downloadCatalogExcel.mockResolvedValue(undefined);
  });

  it('loads the catalog with the filters it was opened with and summarises the scope', async () => {
    mount({ filters: { search: 'slip', artist_id: [5] } });

    expect(await screen.findByTestId('catalog-summary')).toHaveTextContent('3 penjual · 12 varian');
    expect(getCatalog).toHaveBeenCalledWith({ search: 'slip', artist_id: [5] });
    expect(screen.getByTestId('catalog-summary')).toHaveTextContent('Mengikuti filter yang sedang aktif');
  });

  it('says "all active products" when no filter is applied', async () => {
    mount({ filters: { search: undefined, artist_id: undefined } });

    expect(await screen.findByTestId('catalog-summary')).toHaveTextContent('Semua produk aktif.');
  });

  it('shows the "nothing to export" message and offers no download when the scope is empty', async () => {
    getCatalog.mockResolvedValue(CATALOG(0, 0));
    mount();

    expect(await screen.findByTestId('catalog-empty')).toHaveTextContent('Tidak ada varian aktif untuk diekspor');
    expect(screen.getByRole('button', { name: /Unduh PDF/ })).toBeDisabled();
  });

  it('shows an error with a working Retry when the catalog fails to load', async () => {
    const { default: userEvent } = await import('@testing-library/user-event');
    const user = userEvent.setup();
    getCatalog.mockRejectedValueOnce(new Error('Server error')).mockResolvedValueOnce(CATALOG());
    mount();

    expect(await screen.findByRole('alert')).toHaveTextContent('Server error');
    await user.click(screen.getByRole('button', { name: 'Coba lagi' }));

    expect(await screen.findByTestId('catalog-summary')).toBeInTheDocument();
    expect(getCatalog).toHaveBeenCalledTimes(2);
  });

  it('keeps the download disabled until the catalog has loaded', async () => {
    getCatalog.mockReturnValue(new Promise(() => {}));
    mount();

    expect(screen.getByRole('button', { name: /Unduh PDF/ })).toBeDisabled();
  });

  it('generates the PDF from the loaded catalog and shows page progress while it works', async () => {
    const { default: userEvent } = await import('@testing-library/user-event');
    const user = userEvent.setup();
    let release;
    generateCatalogPdf.mockImplementation(async (catalog, { onProgress }) => {
      onProgress(2, 5);
      await new Promise((resolve) => { release = resolve; });
      return 5;
    });
    mount();
    await screen.findByTestId('catalog-summary');

    await user.click(screen.getByRole('button', { name: /Unduh PDF/ }));

    expect(await screen.findByTestId('catalog-progress')).toHaveTextContent('Menyiapkan halaman 2 dari 5');
    expect(generateCatalogPdf).toHaveBeenCalledWith(expect.objectContaining({ totals: { sellers: 3, variants: 12 } }), expect.objectContaining({ signal: expect.any(AbortSignal) }));
    expect(screen.getByRole('button', { name: /Unduh PDF/ })).toBeDisabled(); // tidak bisa memicu dua kali
    release();
    await waitFor(() => expect(screen.queryByTestId('catalog-progress')).not.toBeInTheDocument());
  });

  it('reports a failed PDF with a toast and lets the user try again', async () => {
    const { default: userEvent } = await import('@testing-library/user-event');
    const user = userEvent.setup();
    generateCatalogPdf.mockRejectedValueOnce(new Error('boom'));
    const { pinia } = mount();
    await screen.findByTestId('catalog-summary');

    await user.click(screen.getByRole('button', { name: /Unduh PDF/ }));

    await waitFor(() => expect(useToastStore(pinia).items.some((i) => /Gagal membuat katalog PDF/.test(i.message))).toBe(true));
    expect(screen.getByRole('button', { name: /Unduh PDF/ })).toBeEnabled();
  });

  it('does not toast when the user cancels by closing the dialog', async () => {
    const { default: userEvent } = await import('@testing-library/user-event');
    const user = userEvent.setup();
    generateCatalogPdf.mockRejectedValueOnce(Object.assign(new Error('x'), { name: 'AbortError' }));
    const { pinia } = mount();
    await screen.findByTestId('catalog-summary');

    await user.click(screen.getByRole('button', { name: /Unduh PDF/ }));

    await waitFor(() => expect(generateCatalogPdf).toHaveBeenCalled());
    expect(useToastStore(pinia).items).toHaveLength(0);
  });

  it('does not load anything while closed', () => {
    mount({ open: false });

    expect(getCatalog).not.toHaveBeenCalled();
  });

  // --- Excel (US2) ---
  it('downloads the Excel catalog with the same scope the dialog summarised', async () => {
    const { default: userEvent } = await import('@testing-library/user-event');
    const user = userEvent.setup();
    mount({ filters: { search: 'slip', artist_id: [5] } });
    await screen.findByTestId('catalog-summary');

    await user.click(screen.getByRole('button', { name: /Unduh Excel/ }));

    await waitFor(() => expect(downloadCatalogExcel).toHaveBeenCalledWith({ search: 'slip', artist_id: [5] }));
    expect(generateCatalogPdf).not.toHaveBeenCalled();
  });

  it('offers no Excel download for an empty scope or before the catalog has loaded', async () => {
    getCatalog.mockResolvedValue(CATALOG(0, 0));
    mount();

    await screen.findByTestId('catalog-empty');
    expect(screen.getByRole('button', { name: /Unduh Excel/ })).toBeDisabled();
  });

  it('reports a failed Excel download with a toast and re-enables both buttons', async () => {
    const { default: userEvent } = await import('@testing-library/user-event');
    const user = userEvent.setup();
    downloadCatalogExcel.mockRejectedValueOnce(new Error('boom'));
    const { pinia } = mount();
    await screen.findByTestId('catalog-summary');

    await user.click(screen.getByRole('button', { name: /Unduh Excel/ }));

    await waitFor(() => expect(useToastStore(pinia).items.some((i) => /Gagal membuat katalog Excel/.test(i.message))).toBe(true));
    expect(screen.getByRole('button', { name: /Unduh Excel/ })).toBeEnabled();
    expect(screen.getByRole('button', { name: /Unduh PDF/ })).toBeEnabled();
  });

  it('blocks a second download while the Excel file is being prepared', async () => {
    const { default: userEvent } = await import('@testing-library/user-event');
    const user = userEvent.setup();
    let release;
    downloadCatalogExcel.mockImplementation(() => new Promise((resolve) => { release = resolve; }));
    mount();
    await screen.findByTestId('catalog-summary');

    await user.click(screen.getByRole('button', { name: /Unduh Excel/ }));

    expect(screen.getByRole('button', { name: /Unduh PDF/ })).toBeDisabled();
    release();
    await waitFor(() => expect(screen.getByRole('button', { name: /Unduh PDF/ })).toBeEnabled());
  });
});
