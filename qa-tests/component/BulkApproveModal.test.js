import { describe, it, expect, vi, beforeEach } from 'vitest';
import { render, screen, fireEvent, waitFor } from '@testing-library/vue';
import { createPinia, setActivePinia } from 'pinia';
import BulkApproveModal from '../../resources/js/components/sales/BulkApproveModal.vue';
import { previewBulkApproval, bulkApprove } from '../../resources/js/api/orderApprovals';
import { useSettingsStore } from '../../resources/js/stores/settings';
import { useAuthStore } from '../../resources/js/stores/auth';

/**
 * 064 — dialog persetujuan massal: angka konfirmasi datang dari PRATINJAU server, tidak ada yang terkirim sebelum
 * konfirmasi, klik ganda hanya mengirim sekali, dan hasil menjumlah (disetujui + dilewati) dengan alasan per baris.
 * (Menggambar tanda tangan di kanvas nyata dicek di browser sungguhan, bukan di jsdom.)
 */
const PREVIEW = {
  selected: 3, eligible_orders: 2, approvals: 3, amount: '450000.00',
  sellers: [
    { artist_id: 4, artist_name: 'Seller Alfa', approvals: 2, amount: '300000.00' },
    { artist_id: 7, artist_name: 'Seller Beta', approvals: 1, amount: '150000.00' },
  ],
  skipped: [{ order_id: 15, order_number: 'ORD-015', reason: 'already_approved', message: 'x' }],
  plan: [{ order_id: 12, sellers: [{ artist_id: 4, items_hash: 'v1:a' }] }, { order_id: 13, sellers: [{ artist_id: 4, items_hash: 'v1:b' }, { artist_id: 7, items_hash: 'v1:c' }] }],
};
const RESULT = {
  bulk_ref: 'ref-1', method: 'stamp', selected: 3, approved_orders: 2, approved_sellers: 3, skipped_total: 1, skipped_by_reason: { already_approved: 1 },
  results: [
    { order_id: 12, order_number: 'ORD-012', status: 'approved', sellers_approved: 1 },
    { order_id: 13, order_number: 'ORD-013', status: 'approved', sellers_approved: 2 },
    { order_id: 15, order_number: 'ORD-015', status: 'skipped', reason: 'already_approved', message: 'x' },
  ],
};

function mount(extra = {}) {
  const pinia = createPinia();
  setActivePinia(pinia);
  useAuthStore().user = { id: 9, name: 'Budi Penyetuju', role: 'Owner', menu_keys: ['sales'] };
  return render(BulkApproveModal, { props: { open: true, orderIds: [12, 13, 15], ...extra }, global: { plugins: [pinia] } });
}

async function toConfirm() {
  await fireEvent.click(screen.getByTestId('method-stamp'));
  await fireEvent.click(screen.getByTestId('bulk-continue'));
  await screen.findByTestId('bulk-confirm-step');
}

describe('BulkApproveModal (064)', () => {
  beforeEach(() => {
    vi.clearAllMocks();
    previewBulkApproval.mockResolvedValue(PREVIEW);
    bulkApprove.mockResolvedValue(RESULT);
  });

  it('offers the three methods and sends nothing when cancelled at the first step', async () => {
    const { emitted } = mount();
    ['draw', 'upload', 'stamp'].forEach((m) => expect(screen.getByTestId(`method-${m}`)).toBeInTheDocument());

    await fireEvent.click(screen.getByRole('button', { name: /^batal$/i }));

    expect(emitted().close).toHaveLength(1);
    expect(previewBulkApproval).not.toHaveBeenCalled();
    expect(bulkApprove).not.toHaveBeenCalled();
  });

  it('stamp: previews the selected ids, shows the server numbers, and approves nothing before the confirm click', async () => {
    mount();
    await toConfirm();

    expect(previewBulkApproval).toHaveBeenCalledWith([12, 13, 15]);
    const step = screen.getByTestId('bulk-confirm-step');
    expect(step).toHaveTextContent('3 persetujuan seller');
    expect(step).toHaveTextContent('2 transaksi');
    expect(step).toHaveTextContent(/450\.000/);
    expect(screen.getByTestId('bulk-sellers')).toHaveTextContent('Seller Alfa');
    expect(screen.getByTestId('bulk-sellers')).toHaveTextContent('Seller Beta');
    expect(screen.getByTestId('bulk-skipped-note')).toHaveTextContent('1 transaksi');
    expect(bulkApprove).not.toHaveBeenCalled();
  });

  it('back from the confirmation and cancel never approve anything', async () => {
    const { emitted } = mount();
    await toConfirm();

    await fireEvent.click(screen.getByRole('button', { name: /kembali/i }));
    expect(screen.queryByTestId('bulk-confirm-step')).toBeNull();
    expect(bulkApprove).not.toHaveBeenCalled();
    expect(emitted().applied).toBeUndefined();
  });

  it('confirm sends ids + the previewed plan + method, then shows a result whose counts add up', async () => {
    const { emitted } = mount();
    await toConfirm();

    await fireEvent.click(screen.getByTestId('bulk-confirm'));

    await screen.findByTestId('bulk-result');
    expect(bulkApprove).toHaveBeenCalledWith({ orderIds: [12, 13, 15], plan: PREVIEW.plan, method: 'stamp', image: null });
    expect(screen.getByTestId('bulk-result-head')).toHaveTextContent('2 transaksi disetujui (3 persetujuan seller)');
    expect(screen.getByTestId('bulk-result-head')).toHaveTextContent('1 transaksi dilewati');
    const list = screen.getByTestId('bulk-skipped-list');
    expect(list).toHaveTextContent('ORD-015');
    expect(list).toHaveTextContent('Sudah disetujui');
    expect(list).not.toHaveTextContent('ORD-012'); // yang disetujui tak masuk daftar dilewati
    expect(emitted().applied).toHaveLength(1);
  });

  it('a double click on confirm sends exactly one request', async () => {
    let release;
    bulkApprove.mockReturnValue(new Promise((r) => { release = r; }));
    mount();
    await toConfirm();

    const btn = screen.getByTestId('bulk-confirm');
    await fireEvent.click(btn);
    await fireEvent.click(btn);
    release(RESULT);

    await screen.findByTestId('bulk-result');
    expect(bulkApprove).toHaveBeenCalledTimes(1);
  });

  it('says there is nothing to approve and offers no confirm button when the preview is empty', async () => {
    previewBulkApproval.mockResolvedValue({ ...PREVIEW, eligible_orders: 0, approvals: 0, amount: '0.00', sellers: [], plan: [] });
    mount();
    await toConfirm();

    expect(screen.getByTestId('bulk-nothing')).toBeInTheDocument();
    expect(screen.queryByTestId('bulk-confirm')).toBeNull();
    expect(bulkApprove).not.toHaveBeenCalled();
  });

  it('draw: refuses to continue without a signature and does not even preview', async () => {
    mount();
    await fireEvent.click(screen.getByTestId('bulk-continue'));
    expect(screen.getByTestId('bulk-error')).toHaveTextContent(/gambar tanda tangan/i);
    expect(previewBulkApproval).not.toHaveBeenCalled();
  });

  it('upload: sends the chosen file once, for the whole batch', async () => {
    mount();
    await fireEvent.click(screen.getByTestId('method-upload'));
    const input = screen.getByTestId('approval-file');
    const good = new File(['png'], 'ttd.png', { type: 'image/png' });
    Object.defineProperty(input, 'files', { value: [good], configurable: true });
    await fireEvent.change(input);
    await fireEvent.click(screen.getByTestId('bulk-continue'));
    await screen.findByTestId('bulk-confirm-step');

    await fireEvent.click(screen.getByTestId('bulk-confirm'));

    await screen.findByTestId('bulk-result');
    expect(bulkApprove.mock.calls[0][0]).toMatchObject({ method: 'upload', image: good });
  });

  it('blocks selections over the limit before sending anything', async () => {
    mount();
    useSettingsStore().bulkApproveMax = 2;
    await fireEvent.click(screen.getByTestId('method-stamp'));

    await waitFor(() => expect(screen.getByTestId('bulk-limit')).toHaveTextContent('Maksimal 2'));
    expect(screen.getByTestId('bulk-continue')).toBeDisabled();
    expect(previewBulkApproval).not.toHaveBeenCalled();
  });

  it('a failed apply is never reported as success and can be retried safely', async () => {
    bulkApprove.mockRejectedValueOnce(Object.assign(new Error('jaringan putus'), { errors: {} }));
    const { emitted } = mount();
    await toConfirm();

    await fireEvent.click(screen.getByTestId('bulk-confirm'));

    await waitFor(() => expect(screen.getByTestId('bulk-error')).toHaveTextContent(/tidak bisa diselesaikan/i));
    expect(screen.queryByTestId('bulk-result')).toBeNull();
    expect(emitted().applied).toBeUndefined();

    await fireEvent.click(screen.getByTestId('bulk-confirm')); // mengulang aman: server melewati yang sudah disetujui
    await screen.findByTestId('bulk-result');
    expect(bulkApprove).toHaveBeenCalledTimes(2);
  });

  it('shows a failed preview as an error on the first step', async () => {
    previewBulkApproval.mockRejectedValueOnce(Object.assign(new Error('x'), { errors: {} }));
    mount();
    await fireEvent.click(screen.getByTestId('method-stamp'));
    await fireEvent.click(screen.getByTestId('bulk-continue'));

    await waitFor(() => expect(screen.getByTestId('bulk-error')).toHaveTextContent(/tidak bisa memeriksa/i));
    expect(screen.queryByTestId('bulk-confirm-step')).toBeNull();
  });
});
