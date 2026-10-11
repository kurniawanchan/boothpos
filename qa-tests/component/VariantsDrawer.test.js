import { describe, it, expect, vi, beforeEach } from 'vitest';
import { render, screen, fireEvent, waitFor, within } from '@testing-library/vue';
import { createPinia, setActivePinia } from 'pinia';
import VariantsDrawer from '../../resources/js/components/product/VariantsDrawer.vue';
import { useSettingsStore } from '../../resources/js/stores/settings';
import { useToastStore } from '../../resources/js/stores/toast';
import { saveVariants } from '../../resources/js/api/products';
import { ApiError } from '../../resources/js/utils/errors';

vi.mock('../../resources/js/api/products', () => ({ saveVariants: vi.fn() }));

const PRODUCT = {
  id: 12,
  name: 'Kopi Tubruk',
  variants: [
    { id: 1, sku: 'KOP-001-1', variant_name: 'Panas', sell_price: '8000.00', is_active: true },
    { id: 2, sku: 'KOP-001-2', variant_name: 'Es', sell_price: '10000.00', is_active: true },
  ],
};

async function userEv() {
  const { default: userEvent } = await import('@testing-library/user-event');
  return userEvent.setup();
}

function renderDrawer({ release = false, product = PRODUCT } = {}) {
  const pinia = createPinia();
  setActivePinia(pinia);
  useSettingsStore().menuReleaseEnabled = release;
  return { pinia, ...render(VariantsDrawer, { props: { open: true, product }, global: { plugins: [pinia] } }) };
}

// 043 (T060) — laci Varian & harga.
describe('VariantsDrawer (043)', () => {
  beforeEach(() => {
    vi.clearAllMocks();
    saveVariants.mockResolvedValue({ applied: [], pending: [] });
  });

  it('lists every variant with its SKU and base price, and explains variants vs modifiers', () => {
    renderDrawer();
    expect(screen.getByRole('dialog', { name: /varian & harga/i })).toBeInTheDocument();
    expect(screen.getByLabelText('Nama varian 1')).toHaveValue('Panas');
    expect(screen.getByLabelText('Nama varian 2')).toHaveValue('Es');
    expect(screen.getByLabelText('Harga dasar Panas')).toHaveValue(8000);
    expect(screen.getByText('KOP-001-2')).toBeInTheDocument();
    expect(screen.getByTestId('vd-guidance')).toHaveTextContent(/grup modifier/i);
  }, 30000);

  it('renders ONE bordered table (VARIAN · SKU · HARGA DASAR · AKTIF) with "+ Tambah varian" inside the frame and no outlet columns', () => {
    renderDrawer();
    expect(screen.getAllByRole('table')).toHaveLength(1);
    expect(screen.getAllByRole('columnheader').map((h) => h.textContent.trim()).filter(Boolean)).toEqual(['Varian', 'SKU', 'Harga dasar', 'Aktif']);
    expect(within(screen.getByTestId('vd-table')).getByTestId('vd-add')).toHaveTextContent('+ Tambah varian');
    expect(screen.queryByText(/outlet|wjm/i)).not.toBeInTheDocument();
  }, 30000);

  it('shows the approval-threshold rule and the "Ajukan perubahan" label ONLY when menu releases are on', () => {
    const off = renderDrawer({ release: false });
    expect(screen.queryByTestId('vd-threshold-rule')).not.toBeInTheDocument();
    expect(screen.getByRole('button', { name: /^simpan$/i })).toBeInTheDocument();
    expect(screen.queryByRole('button', { name: /ajukan perubahan/i })).not.toBeInTheDocument();
    off.unmount();

    renderDrawer({ release: true });
    expect(screen.getByTestId('vd-threshold-rule')).toHaveTextContent(/ambang persetujuan/i);
    expect(screen.getByRole('button', { name: /ajukan perubahan/i })).toBeInTheDocument();
    expect(screen.queryByRole('button', { name: /^simpan$/i })).not.toBeInTheDocument();
  }, 30000);

  it('sends the whole batch: changed price, deactivated variant, a NEW variant (no id) and the effective date', async () => {
    const user = await userEv();
    const { emitted } = renderDrawer();
    useToastStore().items = [];

    const price = screen.getByLabelText('Harga dasar Panas');
    await user.clear(price);
    await user.type(price, '9000');
    await user.click(screen.getByLabelText('Aktif: Es'));
    await user.click(screen.getByTestId('vd-add'));
    await user.type(screen.getByLabelText('Nama varian 3'), 'Susu');
    await user.type(screen.getByLabelText('Harga dasar Susu'), '12000');
    await fireEvent.update(screen.getByLabelText(/berlaku mulai/i), '2099-01-05');
    await user.click(screen.getByRole('button', { name: /^simpan$/i }));

    await waitFor(() => expect(saveVariants).toHaveBeenCalledTimes(1));
    expect(saveVariants).toHaveBeenCalledWith(12, {
      variants: [
        { id: 1, variant_name: 'Panas', sell_price: 9000, is_active: true },
        { id: 2, variant_name: 'Es', sell_price: 10000, is_active: false },
        { variant_name: 'Susu', sell_price: 12000, is_active: true },
      ],
      effective_from: '2099-01-05',
    });
    await waitFor(() => expect(emitted().saved).toHaveLength(1));
  }, 30000);

  it('omits effective_from when the date is left empty', async () => {
    const user = await userEv();
    renderDrawer();
    await user.click(screen.getByRole('button', { name: /^simpan$/i }));
    await waitFor(() => expect(saveVariants).toHaveBeenCalled());
    expect(saveVariants.mock.calls[0][1]).not.toHaveProperty('effective_from');
  }, 30000);

  it('refuses a blank name or an invalid price before calling the API', async () => {
    const user = await userEv();
    renderDrawer();
    await user.clear(screen.getByLabelText('Nama varian 1'));
    await user.clear(screen.getByLabelText('Harga dasar Es'));
    await user.click(screen.getByRole('button', { name: /^simpan$/i }));

    expect(await screen.findByText(/nama varian wajib diisi/i)).toBeInTheDocument();
    expect(screen.getByText(/harga wajib berupa angka/i)).toBeInTheDocument();
    expect(saveVariants).not.toHaveBeenCalled();
  }, 30000);

  it('only a NEW row can be discarded; a saved variant has no remove button (it can only be deactivated)', async () => {
    const user = await userEv();
    renderDrawer();
    expect(screen.queryByRole('button', { name: /buang baris/i })).not.toBeInTheDocument();
    await user.click(screen.getByTestId('vd-add'));
    expect(screen.getAllByTestId('vd-row')).toHaveLength(3);
    // satu-satunya "×" milik baris baru; dua varian tersimpan tidak punya tombol buang
    expect(screen.getAllByRole('button', { name: /buang baris/i })).toHaveLength(1);
    expect(within(screen.getAllByTestId('vd-row')[0]).queryByRole('button')).not.toBeInTheDocument();
    expect(screen.getByText(/dibuat otomatis/i)).toBeInTheDocument();
    await user.click(screen.getByRole('button', { name: /buang baris varian baru 1/i }));
    expect(screen.getAllByTestId('vd-row')).toHaveLength(2);
  }, 30000);

  it('shows server 422 field errors on the offending row and keeps the drawer open', async () => {
    const user = await userEv();
    const { emitted } = renderDrawer();
    saveVariants.mockRejectedValueOnce(new ApiError('invalid', { status: 422, errors: { 'variants.1.sell_price': ['Harga tidak sah dari server.'], effective_from: ['Tanggal sudah lewat.'] } }));
    await user.click(screen.getByRole('button', { name: /^simpan$/i }));

    expect(await screen.findByText('Harga tidak sah dari server.')).toBeInTheDocument();
    expect(screen.getByText('Tanggal sudah lewat.')).toBeInTheDocument();
    expect(emitted().saved).toBeUndefined();
    expect(screen.getByRole('dialog', { name: /varian & harga/i })).toBeInTheDocument();
  }, 30000);

  it('shows a 409 as an in-drawer alert (cost price locked gets its own wording) and does not close', async () => {
    const user = await userEv();
    const { emitted } = renderDrawer();
    saveVariants.mockRejectedValueOnce(new ApiError('Konflik dari server.', { status: 409, code: 'cost_price_locked_by_bom' }));
    await user.click(screen.getByRole('button', { name: /^simpan$/i }));
    expect(await screen.findByTestId('vd-alert')).toHaveTextContent(/dikunci oleh bom atau resep/i);

    saveVariants.mockRejectedValueOnce(new ApiError('Varian dihapus orang lain.', { status: 409, code: 'something_else' }));
    await user.click(screen.getByRole('button', { name: /^simpan$/i }));
    await waitFor(() => expect(screen.getByTestId('vd-alert')).toHaveTextContent('Varian dihapus orang lain.'));
    expect(emitted().saved).toBeUndefined();
  }, 30000);

  it('tells the user when price changes are queued for release or awaiting approval', async () => {
    const user = await userEv();
    const { emitted } = renderDrawer({ release: true });
    saveVariants.mockResolvedValueOnce({
      applied: [],
      pending: [
        { variant_id: 1, status: 'pending', new_price: '9000.00' },
        { variant_id: 2, status: 'awaiting_approval', new_price: '20000.00' },
      ],
    });
    await user.click(screen.getByRole('button', { name: /ajukan perubahan/i }));
    await waitFor(() => expect(emitted().saved).toHaveLength(1));
    const messages = useToastStore().items.map((i) => i.message).join(' | ');
    expect(messages).toMatch(/2 perubahan harga menunggu rilis/i);
    expect(messages).toMatch(/1 perubahan harga di atas ambang menunggu persetujuan/i);
  }, 30000);

  it('rebuilds the form from the item each time it is opened (cancelled edits do not linger)', async () => {
    const user = await userEv();
    const view = renderDrawer();
    await user.type(screen.getByLabelText('Nama varian 1'), ' XL');
    await view.rerender({ open: false, product: PRODUCT });
    await view.rerender({ open: true, product: PRODUCT });
    expect(screen.getByLabelText('Nama varian 1')).toHaveValue('Panas');
  }, 30000);
});
