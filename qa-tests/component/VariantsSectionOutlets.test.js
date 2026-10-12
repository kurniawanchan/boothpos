import { describe, it, expect, vi, beforeEach } from 'vitest';
import { render, screen, waitFor } from '@testing-library/vue';
import userEvent from '@testing-library/user-event';
import { createPinia, setActivePinia } from 'pinia';
import VariantsSection from '../../resources/js/components/product/VariantsSection.vue';
import { setVariantOutletPrice } from '../../resources/js/api/products';

// 045 Stage 3 (T060) — kolom harga per outlet di tabel varian.
vi.mock('../../resources/js/api/products', () => ({ setVariantOutletPrice: vi.fn() }));

const variants = [{ id: 7, variant_name: 'Regular', sku: 'K-1', sell_price: '20000.00', is_active: true }];
const outletPrices = { outlets: [{ id: 1, code: 'WJ1', name: 'Warkop 1' }, { id: 2, code: 'WJ2', name: 'Warkop 2' }], by_variant: { 7: { 2: '25000.00' } } };

describe('VariantsSection per-outlet prices', () => {
  beforeEach(() => { vi.clearAllMocks(); setActivePinia(createPinia()); setVariantOutletPrice.mockResolvedValue({}); });

  it('shows no outlet columns at one outlet', () => {
    render(VariantsSection, { props: { variants } });
    expect(screen.queryAllByTestId('outlet-price-head')).toHaveLength(0);
  });

  it('shows an override and a follows-base cell (read-only without edit rights)', () => {
    render(VariantsSection, { props: { variants, outletPrices } });
    expect(screen.getAllByTestId('outlet-price-head').map((h) => h.textContent)).toEqual(['WJ1', 'WJ2']);
    const cells = screen.getAllByTestId('outlet-price-cell');
    expect(cells[0].querySelector('span')).toHaveClass('text-muted-3'); // mengikuti dasar
    expect(cells[1].querySelector('span')).toHaveClass('font-bold');
    expect(screen.queryAllByRole('spinbutton')).toHaveLength(0);
  });

  it('keeps the inputs while menu releases are on and says the change is pending', async () => {
    setVariantOutletPrice.mockResolvedValue({ pending: true });
    const user = userEvent.setup();
    render(VariantsSection, { props: { variants, outletPrices, canEdit: true, releaseOn: true } });
    const [first] = screen.getAllByRole('spinbutton');
    await user.type(first, '22000');
    await user.tab();
    await waitFor(() => expect(setVariantOutletPrice).toHaveBeenCalledWith(7, 1, 22000));
  });

  it('saves a new outlet price on blur and clears it with an empty value', async () => {
    const user = userEvent.setup();
    const { emitted } = render(VariantsSection, { props: { variants, outletPrices, canEdit: true } });
    const [first, second] = screen.getAllByRole('spinbutton');

    await user.type(first, '22000');
    await user.tab();
    await waitFor(() => expect(setVariantOutletPrice).toHaveBeenCalledWith(7, 1, 22000));

    await user.clear(second);
    await user.tab();
    await waitFor(() => expect(setVariantOutletPrice).toHaveBeenCalledWith(7, 2, null));
    expect(emitted().changed).toBeTruthy();
  });
});
