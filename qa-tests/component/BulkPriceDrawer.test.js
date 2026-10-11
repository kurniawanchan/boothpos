import { describe, it, expect, vi, beforeEach } from 'vitest';
import { render, screen, waitFor, within } from '@testing-library/vue';
import { createPinia, setActivePinia } from 'pinia';
import BulkPriceDrawer from '../../resources/js/components/product/BulkPriceDrawer.vue';
import { previewPriceChange, applyPriceChange } from '../../resources/js/api/menu';
import { useSettingsStore } from '../../resources/js/stores/settings';
import { useToastStore } from '../../resources/js/stores/toast';
import { ApiError } from '../../resources/js/utils/errors';

vi.mock('../../resources/js/api/menu', () => ({ previewPriceChange: vi.fn(), applyPriceChange: vi.fn() }));

const CATEGORIES = [{ id: 3, name: 'Kopi' }, { id: 4, name: 'Teh' }];
const PREVIEW = {
  items: [
    { product_id: 1, product: 'Es Kopi', variant_id: 11, variant: 'Besar', old_price: '20000.00', new_price: '22000.00' },
    { product_id: 2, product: 'Teh Tarik', variant_id: 21, variant: 'Reguler', old_price: '10000.00', new_price: '11000.00' },
  ],
  count: 2,
  warnings: ['Kopi Hitam — Kecil: harga tidak berubah'],
};

function renderDrawer({ releaseMode = false, rounding, props = {} } = {}) {
  const pinia = createPinia();
  setActivePinia(pinia);
  useSettingsStore().menuReleaseEnabled = releaseMode;
  if (rounding) useSettingsStore().priceRoundingDefault = rounding;
  const handlers = { onClose: vi.fn(), onSaved: vi.fn() };
  const view = render(BulkPriceDrawer, { props: { open: true, categories: CATEGORIES, initialCategoryIds: [], ...handlers, ...props }, global: { plugins: [pinia] } });
  return { ...view, ...handlers };
}
async function userEv() {
  const { default: userEvent } = await import('@testing-library/user-event');
  return userEvent.setup();
}
const dialog = () => screen.getByRole('dialog');

// 043 (T055) — drawer Ubah harga massal.
describe('BulkPriceDrawer (043)', () => {
  beforeEach(() => {
    vi.clearAllMocks();
    previewPriceChange.mockResolvedValue(PREVIEW);
  });

  it('pre-fills the scope from the list (category filter / selection) and says so when it comes from a selection', async () => {
    renderDrawer({ props: { initialCategoryIds: [4], fromSelection: true } });
    const boxes = within(screen.getByTestId('bulk-categories')).getAllByRole('checkbox');
    expect(boxes[0]).not.toBeChecked(); // Kopi
    expect(boxes[1]).toBeChecked(); // Teh
    expect(screen.getByTestId('bulk-selection-note')).toHaveTextContent(/seluruh item aktif di kategori itu|semua item aktif di kategori itu/i);
  }, 30000);

  it('does not call the preview until a category and a non-zero value are present, then previews once after the debounce', async () => {
    const user = await userEv();
    renderDrawer({ props: { initialCategoryIds: [3] } });
    expect(screen.getByTestId('bulk-preview-empty')).toBeInTheDocument();

    await user.type(within(dialog()).getByLabelText(/^Nilai/), '12');
    expect(previewPriceChange).not.toHaveBeenCalled(); // masih dalam jeda ~400 ms

    await waitFor(() => expect(previewPriceChange).toHaveBeenCalledTimes(1), { timeout: 5000 });
    expect(previewPriceChange).toHaveBeenCalledWith(expect.objectContaining({ category_ids: [3], type: 'percent', value: 12, rounding: '500' }));
    expect(await screen.findByTestId('bulk-preview-count')).toHaveTextContent('2 harga akan berubah');
    const rows = screen.getAllByTestId('bulk-preview-row');
    expect(rows).toHaveLength(2);
    expect(rows[0]).toHaveTextContent(/Es Kopi/);
    expect(rows[0]).toHaveTextContent(/20\.000/);
    expect(rows[0]).toHaveTextContent(/22\.000/);
  }, 30000);

  it('shows the server field error under the value and no preview rows when the server answers 422', async () => {
    previewPriceChange.mockRejectedValue(new ApiError('Gagal.', { status: 422, errors: { value: ['Harga Es Kopi — Besar akan menjadi nol atau negatif.'] } }));
    const user = await userEv();
    renderDrawer({ props: { initialCategoryIds: [3] } });
    await user.type(within(dialog()).getByLabelText(/^Nilai/), '-200');
    expect(await screen.findByText('Harga Es Kopi — Besar akan menjadi nol atau negatif.', {}, { timeout: 5000 })).toBeInTheDocument();
    expect(screen.queryAllByTestId('bulk-preview-row')).toHaveLength(0);
  }, 30000);

  it('lists every server message when several variants collapse to zero', async () => {
    previewPriceChange.mockRejectedValue(new ApiError('Gagal.', { status: 422, errors: { value: ['A akan nol.', 'B akan nol.'] } }));
    const user = await userEv();
    renderDrawer({ props: { initialCategoryIds: [3] } });
    await user.type(within(dialog()).getByLabelText(/^Nilai/), '-100');
    const list = await screen.findByTestId('bulk-value-errors', {}, { timeout: 5000 });
    expect(within(list).getAllByRole('listitem')).toHaveLength(2);
  }, 30000);

  it('starts the rounding switch from the store default (price_rounding_default) and sends the matching value', async () => {
    const user = await userEv();
    const none = renderDrawer({ rounding: 'none', props: { initialCategoryIds: [3] } });
    expect(within(dialog()).getByRole('switch', { name: /Bulatkan/ })).not.toBeChecked();
    await user.type(within(dialog()).getByLabelText(/^Nilai/), '5');
    await waitFor(() => expect(previewPriceChange).toHaveBeenCalledWith(expect.objectContaining({ rounding: 'none' })), { timeout: 5000 });
    none.unmount();

    renderDrawer({ rounding: '500' });
    expect(within(screen.getByRole('dialog')).getByRole('switch', { name: /Bulatkan/ })).toBeChecked();
  }, 30000);

  it('labels the submit button by mode: "Terapkan harga" when release is off, "Ajukan perubahan" when on', async () => {
    const off = renderDrawer({ releaseMode: false });
    expect(within(screen.getByRole('dialog')).getByRole('button', { name: 'Terapkan harga' })).toBeInTheDocument();
    expect(screen.queryByTestId('bulk-release-note')).not.toBeInTheDocument();
    off.unmount();

    renderDrawer({ releaseMode: true });
    expect(within(screen.getByRole('dialog')).getByRole('button', { name: 'Ajukan perubahan' })).toBeInTheDocument();
    expect(screen.getByTestId('bulk-release-note')).toBeInTheDocument();
  }, 30000);

  it('applies, toasts the {applied,pending,awaiting_approval} result, emits saved and closes', async () => {
    applyPriceChange.mockResolvedValue({ batch_id: 9, applied: 0, pending: 3, awaiting_approval: 2 });
    const user = await userEv();
    const { onSaved, onClose } = renderDrawer({ releaseMode: true, props: { initialCategoryIds: [3, 4] } });
    await user.type(within(dialog()).getByLabelText(/^Nilai/), '10');
    await screen.findByTestId('bulk-preview-count', {}, { timeout: 5000 });

    await user.click(within(dialog()).getByRole('button', { name: 'Ajukan perubahan' }));
    await waitFor(() => expect(applyPriceChange).toHaveBeenCalledTimes(1));
    expect(applyPriceChange).toHaveBeenCalledWith(expect.objectContaining({ category_ids: [3, 4], type: 'percent', value: 10 }));
    await waitFor(() => expect(onSaved).toHaveBeenCalledWith({ batch_id: 9, applied: 0, pending: 3, awaiting_approval: 2 }));
    expect(onClose).toHaveBeenCalled();
    const message = useToastStore().items.at(-1).message;
    expect(message).toMatch(/3 menunggu rilis/);
    expect(message).toMatch(/2 menunggu persetujuan/);
    expect(message).not.toMatch(/diterapkan/);
  }, 30000);

  it('refuses to submit (no apply call) while the scope or value is incomplete, with a hint', async () => {
    const user = await userEv();
    renderDrawer();
    await user.click(within(dialog()).getByRole('button', { name: 'Terapkan harga' }));
    expect(applyPriceChange).not.toHaveBeenCalled();
    expect(await screen.findByTestId('bulk-blocked')).toBeInTheDocument();
  }, 30000);

  it('keeps the drawer open and shows the field error when the apply call answers 422', async () => {
    applyPriceChange.mockRejectedValue(new ApiError('Gagal.', { status: 422, errors: { effective_from: ['Tanggal berlaku tidak boleh di masa lalu.'] } }));
    const user = await userEv();
    const { onSaved, onClose } = renderDrawer({ props: { initialCategoryIds: [3] } });
    await user.type(within(dialog()).getByLabelText(/^Nilai/), '5');
    await screen.findByTestId('bulk-preview-count', {}, { timeout: 5000 });
    await user.click(within(dialog()).getByRole('button', { name: 'Terapkan harga' }));
    expect(await screen.findByText('Tanggal berlaku tidak boleh di masa lalu.')).toBeInTheDocument();
    expect(onSaved).not.toHaveBeenCalled();
    expect(onClose).not.toHaveBeenCalled();
  }, 30000);

  it('shows a retryable error state (not an empty preview) when the preview request fails for a non-validation reason', async () => {
    previewPriceChange.mockRejectedValueOnce(new ApiError('Server error', { status: 500 }));
    const user = await userEv();
    renderDrawer({ props: { initialCategoryIds: [3] } });
    await user.type(within(dialog()).getByLabelText(/^Nilai/), '5');
    expect(await screen.findByText('Pratinjau gagal dimuat.', {}, { timeout: 5000 })).toBeInTheDocument();
    await user.click(screen.getByRole('button', { name: 'Coba lagi' }));
    expect(await screen.findByTestId('bulk-preview-count')).toBeInTheDocument();
  }, 30000);
});
