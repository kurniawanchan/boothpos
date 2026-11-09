import { describe, it, expect, vi, beforeEach } from 'vitest';
import { render, screen, fireEvent, waitFor } from '@testing-library/vue';
import { createPinia, setActivePinia } from 'pinia';
import PreorderBulkModal from '../../resources/js/components/preorder/bulk/PreorderBulkModal.vue';
import { previewBulk, applyBulk } from '../../resources/js/api/preorders';
import { ApiError } from '../../resources/js/utils/errors';

vi.mock('../../resources/js/api/preorders', () => ({ previewBulk: vi.fn(), applyBulk: vi.fn() }));

/**
 * 065 — dialog aksi massal (status, penanda progres): angka konfirmasi dari PRATINJAU, tak ada yang terkirim sebelum
 * konfirmasi, klik ganda mengirim sekali, hasil menjumlah dengan alasan per baris, "ulangi yang ini", dan hasil tak pasti saat koneksi putus.
 */
function mount(props = {}) {
  const pinia = createPinia();
  setActivePinia(pinia);
  return render(PreorderBulkModal, { props: { open: true, action: 'status', orderIds: [1, 2, 3], ...props }, global: { plugins: [pinia] } });
}

const RESULT = {
  action: 'status', bulk_ref: 'r', selected: 3, succeeded: 1, skipped: 1, failed: 1, skipped_by_reason: { invalid_transition: 1 },
  results: [
    { preorder_id: 1, preorder_number: 'PO-1', status: 'succeeded' },
    { preorder_id: 2, preorder_number: 'PO-2', status: 'skipped', reason: 'invalid_transition', message: 'Tidak bisa pindah' },
    { preorder_id: 3, preorder_number: 'PO-3', status: 'failed', reason: 'failed', message: 'Terjadi masalah' },
  ],
};

describe('PreorderBulkModal (065)', () => {
  beforeEach(() => vi.clearAllMocks());

  it('status: offers reachable targets with counts, previews, confirms once and shows a result that adds up', async () => {
    previewBulk
      .mockResolvedValueOnce({ status_options: [{ status: 'arrived', count: 2 }, { status: 'cancelled', count: 3 }] })
      .mockResolvedValueOnce({ eligible: 2, skipped: [{ preorder_id: 3, preorder_number: 'PO-3', reason: 'invalid_transition', message: 'x' }], rows: [] });
    let finish;
    applyBulk.mockReturnValue(new Promise((r) => { finish = r; }));
    const { emitted } = mount();

    await screen.findByTestId('bulk-status-form');
    expect(previewBulk).toHaveBeenCalledWith('status', [1, 2, 3], {});
    expect(screen.getByTestId('bulk-continue')).toBeDisabled(); // belum memilih status

    await fireEvent.click(await screen.findByRole('combobox', { name: /status baru/i }));
    await fireEvent.click(await screen.findByRole('option', { name: /Barang tiba \(2\)/ }));
    await fireEvent.click(screen.getByTestId('bulk-continue'));

    expect(await screen.findByTestId('bulk-confirm-body')).toHaveTextContent('2 dari 3 pre-order akan diubah. 1 akan dilewati.');
    expect(applyBulk).not.toHaveBeenCalled(); // tak ada yang terkirim sebelum konfirmasi

    await fireEvent.click(screen.getByTestId('bulk-confirm'));
    await fireEvent.click(screen.getByTestId('bulk-confirm')); // klik ganda
    expect(applyBulk).toHaveBeenCalledTimes(1);
    expect(applyBulk).toHaveBeenCalledWith('status', [1, 2, 3], { status: 'arrived' });
    expect(await screen.findByTestId('bulk-processing')).toBeInTheDocument();

    finish(RESULT);
    await screen.findByTestId('bulk-result');
    expect(screen.getByTestId('count-selected')).toHaveTextContent('3');
    expect(screen.getByTestId('count-succeeded')).toHaveTextContent('1');
    expect(screen.getByTestId('count-skipped')).toHaveTextContent('1');
    expect(screen.getByTestId('count-failed')).toHaveTextContent('1');
    expect(screen.getByTestId('bulk-not-done-list')).toHaveTextContent('Tidak bisa pindah');
    expect(screen.getByTestId('bulk-not-done-list')).toHaveTextContent('Terjadi masalah');
    expect(emitted().applied).toHaveLength(1);
  });

  it('retry emits exactly the non-successful ids', async () => {
    applyBulk.mockResolvedValue(RESULT);
    previewBulk.mockResolvedValue({ eligible: 3, skipped: [], rows: [], status_options: [] });
    const { emitted } = mount({ action: 'dispatch', dispatchTarget: 'shipping' });

    await fireEvent.click(await screen.findByTestId('bulk-continue'));
    await fireEvent.click(await screen.findByTestId('bulk-confirm'));
    await fireEvent.click(await screen.findByTestId('bulk-retry'));

    expect(emitted().retry[0][0]).toEqual({ ids: [2, 3] });
  });

  it('dispatch starts with the marker of the button that opened it and sends it', async () => {
    previewBulk.mockResolvedValue({ eligible: 1, skipped: [], rows: [] });
    mount({ action: 'dispatch', dispatchTarget: 'shipping' });

    expect(await screen.findByTestId('marker-shipping')).toBeChecked();
    await fireEvent.click(screen.getByTestId('bulk-continue'));
    await screen.findByTestId('bulk-review-step');
    expect(previewBulk).toHaveBeenCalledWith('dispatch', [1, 2, 3], { dispatch_status: 'shipping' });
  });

  it('nothing eligible: tells the user and offers no confirm button', async () => {
    previewBulk.mockResolvedValue({ eligible: 0, skipped: [{ preorder_id: 1, preorder_number: 'PO-1', reason: 'cancelled', message: 'Dibatalkan' }], rows: [] });
    mount({ action: 'dispatch', dispatchTarget: 'invoice_sent' });

    await fireEvent.click(await screen.findByTestId('bulk-continue'));
    expect(await screen.findByTestId('bulk-nothing')).toBeInTheDocument();
    expect(screen.queryByTestId('bulk-confirm')).not.toBeInTheDocument();
    expect(applyBulk).not.toHaveBeenCalled();
  });

  it('a dropped connection is reported as an unknown outcome, never as success or failure', async () => {
    previewBulk.mockResolvedValue({ eligible: 3, skipped: [], rows: [] });
    applyBulk.mockRejectedValue(new ApiError('Network Error', { status: null }));
    mount({ action: 'dispatch', dispatchTarget: 'invoice_sent' });

    await fireEvent.click(await screen.findByTestId('bulk-continue'));
    await fireEvent.click(await screen.findByTestId('bulk-confirm'));

    expect(await screen.findByTestId('bulk-unknown')).toBeInTheDocument();
    expect(screen.queryByTestId('bulk-result')).not.toBeInTheDocument();
  });

  it('refuses a selection over the limit without calling the server', async () => {
    const { useSettingsStore } = await import('../../resources/js/stores/settings');
    const pinia = createPinia();
    setActivePinia(pinia);
    useSettingsStore().preorderBulkMax = 2;
    render(PreorderBulkModal, { props: { open: true, action: 'dispatch', orderIds: [1, 2, 3] }, global: { plugins: [pinia] } });

    expect(await screen.findByTestId('bulk-limit')).toBeInTheDocument();
    expect(screen.getByTestId('bulk-continue')).toBeDisabled();
    expect(previewBulk).not.toHaveBeenCalled();
  });
});
