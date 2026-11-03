import { describe, it, expect, vi, beforeEach } from 'vitest';
import { render, screen, waitFor } from '@testing-library/vue';
import userEvent from '@testing-library/user-event';
import { createPinia, setActivePinia } from 'pinia';
import TableQrSheet from '../../resources/js/components/restaurant/TableQrSheet.vue';
import TableQrBulkSheet from '../../resources/js/components/restaurant/TableQrBulkSheet.vue';
import { downloadElementAsPng } from '../../resources/js/utils/pdfCapture';
import { downloadQrSheetPdf, printQrSheet } from '../../resources/js/utils/qrSheetPdf';

// `qrcode` dimuat dinamis oleh utils/qrImage; di tes diganti pembuat data URL palsu yang memuat URL-nya.
const toDataURL = vi.hoisted(() => vi.fn(async (url) => `data:image/png;base64,${btoa(url)}`));
vi.mock('qrcode', () => ({ default: { toDataURL } }));
vi.mock('../../resources/js/utils/pdfCapture', () => ({ downloadElementAsPng: vi.fn() }));
vi.mock('../../resources/js/utils/qrSheetPdf', async (orig) => ({ ...(await orig()), downloadQrSheetPdf: vi.fn(), printQrSheet: vi.fn(() => true) }));

const item = (over = {}) => ({ table_id: 5, outlet_name: 'Warkop WJM 3', table_name: 'Meja 5', area: 'Indoor', url: 'http://192.168.1.10:8010/pesan/abc.def', ...over });

const mount = (component, props) => {
  setActivePinia(createPinia());
  return render(component, { props, global: { plugins: [createPinia()] } });
};

describe('TableQrSheet', () => {
  beforeEach(() => vi.clearAllMocks());

  it('renders the QR of the signed URL with outlet and table labels', async () => {
    mount(TableQrSheet, { open: true, item: item() });

    const img = await screen.findByAltText('Kode QR meja Meja 5');
    await waitFor(() => expect(img).toHaveAttribute('src', expect.stringContaining('data:image/png')));
    expect(toDataURL).toHaveBeenCalledWith('http://192.168.1.10:8010/pesan/abc.def', expect.objectContaining({ errorCorrectionLevel: 'M' }));
    expect(screen.getByText('Warkop WJM 3')).toBeInTheDocument();
    expect(screen.getByText('Meja 5', { selector: 'p' })).toBeInTheDocument();
    expect(screen.getByText('Pindai untuk memesan')).toBeInTheDocument();
  });

  it('downloads the labelled card as a PNG and prints it', async () => {
    const user = userEvent.setup();
    mount(TableQrSheet, { open: true, item: item() });
    await screen.findByAltText('Kode QR meja Meja 5');

    await waitFor(() => expect(screen.getByRole('button', { name: 'Unduh PNG' })).toBeEnabled());
    await user.click(screen.getByRole('button', { name: 'Unduh PNG' }));
    expect(downloadElementAsPng).toHaveBeenCalledWith(expect.anything(), 'warkop-wjm-3-meja-5.png');

    await user.click(screen.getByRole('button', { name: 'Cetak' }));
    expect(printQrSheet).toHaveBeenCalledWith([expect.objectContaining({ table_name: 'Meja 5', qr: expect.stringContaining('data:image/png') })], 'Pindai untuk memesan', 'QR pesan mandiri');
  });

  it('asks to remake the QR and says the old one stops working', async () => {
    const user = userEvent.setup();
    const { emitted } = mount(TableQrSheet, { open: true, item: item() });

    expect(screen.getByText(/QR lama berhenti berlaku/)).toBeInTheDocument();
    await user.click(screen.getByRole('button', { name: 'Buat ulang QR' }));

    expect(emitted().rotate).toHaveLength(1);
  });

  it('shows a message instead of a broken image when the QR cannot be rendered', async () => {
    toDataURL.mockRejectedValueOnce(new Error('boom'));
    mount(TableQrSheet, { open: true, item: item() });

    expect(await screen.findByRole('alert')).toHaveTextContent('Gambar QR tidak dapat dibuat');
    expect(screen.getByRole('button', { name: 'Cetak' })).toBeDisabled();
  });
});

describe('TableQrBulkSheet', () => {
  beforeEach(() => vi.clearAllMocks());

  const many = (n) => Array.from({ length: n }, (_, i) => item({ table_id: i + 1, table_name: `Meja ${i + 1}`, url: `http://x/pesan/t${i}` }));

  it('summarises the count and pages, notes skipped inactive tables, and previews only a few', async () => {
    mount(TableQrBulkSheet, { open: true, items: many(14), skipped: 2 });

    expect(await screen.findByTestId('qr-bulk-count')).toHaveTextContent('14 QR meja · 3 halaman A4');
    expect(screen.getByTestId('qr-skipped')).toHaveTextContent('2 meja nonaktif dilewati.');
    await waitFor(() => expect(screen.getAllByTestId('qr-card')).toHaveLength(6));
    expect(screen.getByText('…dan 8 QR lainnya ada di berkas.')).toBeInTheDocument();
    expect(toDataURL).toHaveBeenCalledTimes(14); // semua QR dibuat untuk berkas, bukan hanya pratinjau
  });

  it('hands every table to the PDF and the print window', async () => {
    const user = userEvent.setup();
    mount(TableQrBulkSheet, { open: true, items: many(8), skipped: 0 });
    await waitFor(() => expect(screen.getByRole('button', { name: 'Unduh PDF' })).toBeEnabled());

    await user.click(screen.getByRole('button', { name: 'Unduh PDF' }));
    expect(downloadQrSheetPdf).toHaveBeenCalledTimes(1);
    expect(downloadQrSheetPdf.mock.calls[0][0]).toHaveLength(8);
    expect(downloadQrSheetPdf.mock.calls[0][1]).toBe('qr-meja-8.pdf');

    await user.click(screen.getByRole('button', { name: 'Cetak' }));
    expect(printQrSheet.mock.calls[0][0]).toHaveLength(8);
  });
});
