import { describe, it, expect, vi, beforeEach } from 'vitest';
import { render, screen, waitFor, within } from '@testing-library/vue';
import userEvent from '@testing-library/user-event';
import { createPinia, setActivePinia } from 'pinia';
import SoldOutDialog from '../../resources/js/components/product/SoldOutDialog.vue';
import { soldOut } from '../../resources/js/api/products';
import { listReasonCodes } from '../../resources/js/api/reasons';
import { useOutletStore } from '../../resources/js/stores/outlet';

// 045 Stage 3 — 86 per outlet: checkbox outlet di dialog Tandai 86.
vi.mock('../../resources/js/api/products', () => ({ soldOut: vi.fn(), discontinueProduct: vi.fn() }));
vi.mock('../../resources/js/api/reasons', () => ({ listReasonCodes: vi.fn() }));

const OUTLETS = [{ id: 1, code: 'WJ1', name: 'Warkop 1' }, { id: 2, code: 'WJ2', name: 'Warkop 2' }];

function renderDialog({ multi = true, selected = null } = {}) {
  const pinia = createPinia();
  setActivePinia(pinia);
  Object.assign(useOutletStore(), { outlets: OUTLETS, multi, selected });
  return render(SoldOutDialog, { props: { open: true, product: { id: 12, name: 'Es Jeruk' } }, global: { plugins: [pinia] } });
}

describe('SoldOutDialog outlets (045)', () => {
  beforeEach(() => {
    vi.clearAllMocks();
    listReasonCodes.mockResolvedValue({ data: [{ id: 3, code: 'out_of_stock', label: 'Bahan habis' }] });
    soldOut.mockResolvedValue({});
  });

  it('shows no outlet choice at one outlet and sends no outlet_ids', async () => {
    const user = userEvent.setup();
    renderDialog({ multi: false });
    await user.selectOptions(await screen.findByRole('combobox'), '3');
    expect(screen.queryByTestId('sold-out-outlets')).toBeNull();
    await user.click(within(screen.getByRole('dialog')).getByRole('button', { name: /Tandai 86/ }));
    await waitFor(() => expect(soldOut).toHaveBeenCalledWith(12, { reason_code_id: 3, note: null }));
  });

  it('defaults to the selected outlet and sends the chosen outlet ids', async () => {
    const user = userEvent.setup();
    renderDialog({ selected: 2 });
    await user.selectOptions(await screen.findByRole('combobox'), '3');
    const boxes = within(screen.getByTestId('sold-out-outlets')).getAllByRole('checkbox');
    expect(boxes.map((b) => b.checked)).toEqual([false, true]);
    await user.click(boxes[0]);
    await user.click(within(screen.getByRole('dialog')).getByRole('button', { name: /Tandai 86/ }));
    await waitFor(() => expect(soldOut).toHaveBeenCalledWith(12, { reason_code_id: 3, note: null, outlet_ids: [1, 2] }));
  });

  it('keeps the button disabled when no outlet is ticked', async () => {
    const user = userEvent.setup();
    renderDialog({ selected: 1 });
    await user.selectOptions(await screen.findByRole('combobox'), '3');
    await user.click(within(screen.getByTestId('sold-out-outlets')).getAllByRole('checkbox')[0]);
    expect(within(screen.getByRole('dialog')).getByRole('button', { name: /Tandai 86/ })).toBeDisabled();
  });
});
