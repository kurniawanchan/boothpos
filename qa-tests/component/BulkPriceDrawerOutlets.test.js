import { describe, it, expect, vi, beforeEach } from 'vitest';
import { render, screen, waitFor, within } from '@testing-library/vue';
import userEvent from '@testing-library/user-event';
import { createPinia, setActivePinia } from 'pinia';
import BulkPriceDrawer from '../../resources/js/components/product/BulkPriceDrawer.vue';
import { previewPriceChange } from '../../resources/js/api/menu';
import { useSettingsStore } from '../../resources/js/stores/settings';
import { useOutletStore } from '../../resources/js/stores/outlet';

// 045 Stage 3 (T062) — cakupan harga (dasar / semua outlet / outlet tertentu) di drawer ubah harga massal.
vi.mock('../../resources/js/api/menu', () => ({ previewPriceChange: vi.fn(), applyPriceChange: vi.fn() }));

const OUTLETS = [{ id: 1, code: 'WJ1', name: 'Warkop 1' }, { id: 2, code: 'WJ2', name: 'Warkop 2' }];

function renderDrawer({ multi = true, releaseMode = false } = {}) {
  const pinia = createPinia();
  setActivePinia(pinia);
  useSettingsStore().menuReleaseEnabled = releaseMode;
  Object.assign(useOutletStore(), { outlets: OUTLETS, multi });
  return render(BulkPriceDrawer, { props: { open: true, categories: [{ id: 3, name: 'Kopi' }], initialCategoryIds: [3] }, global: { plugins: [pinia] } });
}

describe('BulkPriceDrawer price scope (045)', () => {
  beforeEach(() => {
    vi.clearAllMocks();
    previewPriceChange.mockResolvedValue({ items: [{ product_id: 1, product: 'Kopi', variant_id: 11, variant: 'Reguler', outlet_id: 2, outlet_code: 'WJ2', old_price: '20000.00', new_price: '22000.00' }], count: 1, warnings: [] });
  });

  it('hides the price scope at one outlet and while menu releases are on', () => {
    renderDrawer({ multi: false });
    expect(screen.queryByTestId('bulk-price-scope')).toBeNull();
  });

  it('still offers it while menu releases are on (outlet prices become pending changes)', () => {
    renderDrawer({ releaseMode: true });
    expect(screen.getByTestId('bulk-price-scope')).toBeInTheDocument();
  });

  it('sends the scope and chosen outlets, and shows the outlet on each preview row', async () => {
    const user = userEvent.setup();
    renderDrawer();
    await user.click(within(screen.getByTestId('bulk-price-scope')).getByRole('radio', { name: /outlet tertentu/i }));
    await user.click(within(screen.getByTestId('bulk-outlets')).getByRole('checkbox', { name: /WJ2/ }));
    await user.type(within(screen.getByRole('dialog')).getByLabelText(/^Nilai/), '10');

    await waitFor(() => expect(previewPriceChange).toHaveBeenCalled(), { timeout: 5000 });
    expect(previewPriceChange).toHaveBeenLastCalledWith(expect.objectContaining({ price_scope: 'outlets', outlet_ids: [2] }));
    expect(await screen.findByTestId('bulk-preview-outlet')).toHaveTextContent('WJ2');
  }, 30000);

  it('keeps the legacy payload (no scope keys) for the base price', async () => {
    const user = userEvent.setup();
    renderDrawer();
    await user.type(within(screen.getByRole('dialog')).getByLabelText(/^Nilai/), '10');

    await waitFor(() => expect(previewPriceChange).toHaveBeenCalled(), { timeout: 5000 });
    expect(previewPriceChange.mock.calls[0][0]).not.toHaveProperty('price_scope');
  }, 30000);
});
