import { describe, it, expect, vi, beforeEach } from 'vitest';
import { render, screen, waitFor, within } from '@testing-library/vue';
import { createPinia, setActivePinia } from 'pinia';
import BomCopyMenu from '../../resources/js/components/product/BomCopyMenu.vue';
import { copyBomFrom, copyBomOut } from '../../resources/js/api/materials';

vi.mock('../../resources/js/api/materials', () => ({ copyBomFrom: vi.fn(), copyBomOut: vi.fn() }));

const SIBLINGS = [
  { id: 1, sku: 'KC001', variant_name: 'Red', has_bom: true },
  { id: 2, sku: 'KC002', variant_name: 'Blue', has_bom: false },
  { id: 3, sku: 'KC003', variant_name: 'Green', has_bom: true },
];

function renderMenu(props = {}) {
  const pinia = createPinia();
  setActivePinia(pinia);
  return render(BomCopyMenu, { props: { variantId: 1, siblings: SIBLINGS, hasRows: true, ...props }, global: { plugins: [pinia] } });
}

async function openPanel(user) {
  await user.click(screen.getByRole('button', { name: 'Salin BOM' }));
}

// 034-seller-po-bom (US3) — salin BOM ke semua / berikutnya / dari varian lain.
describe('BomCopyMenu (034)', () => {
  beforeEach(() => {
    vi.clearAllMocks();
    copyBomOut.mockResolvedValue({ results: [{ variant_id: 2, status: 'copied', rows: 3 }] });
    copyBomFrom.mockResolvedValue({ data: [], summary: {}, results: [{ variant_id: 1, status: 'copied', rows: 3 }] });
  });

  it('is not shown at all when the product has no other variant', () => {
    renderMenu({ siblings: [SIBLINGS[0]] });

    expect(screen.queryByRole('button', { name: 'Salin BOM' })).not.toBeInTheDocument();
  });

  it('copies to the next variant directly when that variant has no BOM yet (no confirmation needed)', async () => {
    const { default: userEvent } = await import('@testing-library/user-event');
    const user = userEvent.setup();
    const { emitted } = renderMenu();
    await openPanel(user);

    await user.click(screen.getByRole('button', { name: 'Salin ke varian berikutnya (Blue)' }));

    await waitFor(() => expect(copyBomOut).toHaveBeenCalledWith(1, 'next', false));
    expect(emitted().copied).toHaveLength(1);
  });

  it('asks for confirmation naming the variants that already have rows before copying to all', async () => {
    const { default: userEvent } = await import('@testing-library/user-event');
    const user = userEvent.setup();
    renderMenu();
    await openPanel(user);

    await user.click(screen.getByRole('button', { name: 'Salin ke semua varian' }));

    const dialog = await screen.findByRole('dialog', { name: /ganti baris bom yang ada/i });
    expect(within(dialog).getByText(/KC003 — Green/)).toBeInTheDocument();
    expect(within(dialog).queryByText(/KC002/)).not.toBeInTheDocument();
    expect(copyBomOut).not.toHaveBeenCalled();

    await user.click(within(dialog).getByRole('button', { name: 'Salin' }));

    await waitFor(() => expect(copyBomOut).toHaveBeenCalledWith(1, 'all', true));
  });

  it('copies from another variant, asking first because this variant already has rows', async () => {
    const { default: userEvent } = await import('@testing-library/user-event');
    const user = userEvent.setup();
    renderMenu({ variantId: 2, hasRows: true });
    await openPanel(user);

    await user.click(screen.getByRole('combobox'));
    await user.click(await screen.findByRole('option', { name: /KC001 — Red/ }));
    await user.click(screen.getByRole('button', { name: 'Salin' }));

    const dialog = await screen.findByRole('dialog', { name: /ganti baris bom yang ada/i });
    expect(copyBomFrom).not.toHaveBeenCalled();
    await user.click(within(dialog).getByRole('button', { name: 'Salin' }));

    await waitFor(() => expect(copyBomFrom).toHaveBeenCalledWith(2, 1, true));
  });

  it('copies from another variant without asking when this variant has no rows', async () => {
    const { default: userEvent } = await import('@testing-library/user-event');
    const user = userEvent.setup();
    renderMenu({ variantId: 2, hasRows: false });
    await openPanel(user);

    expect(screen.queryByRole('button', { name: 'Salin ke semua varian' })).not.toBeInTheDocument();
    await user.click(screen.getByRole('combobox'));
    await user.click(await screen.findByRole('option', { name: /KC001 — Red/ }));
    await user.click(screen.getByRole('button', { name: 'Salin' }));

    await waitFor(() => expect(copyBomFrom).toHaveBeenCalledWith(2, 1, false));
  });

  it('keeps the panel open and does not report success when the server refuses', async () => {
    copyBomOut.mockRejectedValue({ message: 'Tidak ada varian berikutnya' });
    const { default: userEvent } = await import('@testing-library/user-event');
    const user = userEvent.setup();
    const { emitted } = renderMenu();
    await openPanel(user);

    await user.click(screen.getByRole('button', { name: 'Salin ke varian berikutnya (Blue)' }));

    await waitFor(() => expect(copyBomOut).toHaveBeenCalled());
    expect(emitted().copied).toBeUndefined();
    expect(screen.getByRole('button', { name: 'Salin ke semua varian' })).toBeInTheDocument();
  });
});

// 036-bom-variant-stock-ux (US2) — produk dengan banyak varian: daftar sumber bisa dicari dan di-scroll.
describe('BomCopyMenu — picker with many variants (036)', () => {
  const MANY = Array.from({ length: 12 }, (_, i) => ({ id: i + 10, sku: `SPF-KC-MCY-${String(i + 1).padStart(3, '0')}`, variant_name: `Warna ${i + 1}`, has_bom: true }));

  beforeEach(() => {
    vi.clearAllMocks();
    copyBomFrom.mockResolvedValue({ data: [], summary: {}, results: [{ variant_id: 1, status: 'copied', rows: 3 }] });
  });

  it('offers every sibling that has a BOM, searchable by SKU or variant name', async () => {
    const { default: userEvent } = await import('@testing-library/user-event');
    const user = userEvent.setup();
    renderMenu({ variantId: 1, siblings: [{ id: 1, sku: 'SPF-KC-MCY-000', variant_name: 'Ini', has_bom: false }, ...MANY], hasRows: false });
    await openPanel(user);

    await user.click(screen.getByRole('combobox', { name: 'Salin dari varian lain' }));
    expect(screen.getAllByRole('option').length).toBe(13); // 12 sumber + baris placeholder

    const box = screen.getByRole('textbox', { name: 'Cari…' });
    await user.type(box, 'MCY-011');
    expect(screen.getByRole('option', { name: /MCY-011/ })).toBeInTheDocument();
    expect(screen.queryByRole('option', { name: /MCY-010/ })).not.toBeInTheDocument();

    await user.clear(box);
    await user.type(box, 'warna 7');
    expect(screen.getByRole('option', { name: /Warna 7/ })).toBeInTheDocument();
  });

  it('copies from the variant picked out of the filtered list', async () => {
    const { default: userEvent } = await import('@testing-library/user-event');
    const user = userEvent.setup();
    renderMenu({ variantId: 1, siblings: [{ id: 1, sku: 'SPF-KC-MCY-000', variant_name: 'Ini', has_bom: false }, ...MANY], hasRows: false });
    await openPanel(user);

    await user.click(screen.getByRole('combobox', { name: 'Salin dari varian lain' }));
    await user.type(screen.getByRole('textbox', { name: 'Cari…' }), 'MCY-012');
    await user.click(screen.getByRole('option', { name: /MCY-012/ }));
    await user.click(screen.getByRole('button', { name: 'Salin' }));

    await waitFor(() => expect(copyBomFrom).toHaveBeenCalledWith(1, 21, false));
  });
});

// 037-variant-drawer-bom-ui (US3) — gambar di daftar sumber + "Salin ke varian pilihan".
describe('BomCopyMenu — pictures and copy to chosen variants (037)', () => {
  const WITH_IMAGES = [
    { id: 1, sku: 'KC001', variant_name: 'Red', has_bom: true, image_url: 'https://example.test/red.png', is_active: true },
    { id: 2, sku: 'KC002', variant_name: 'Blue', has_bom: false, image_url: null, is_active: true },
    { id: 3, sku: 'KC003', variant_name: 'Green', has_bom: true, image_url: 'https://example.test/green.png', is_active: true },
    { id: 4, sku: 'KC004', variant_name: 'Pink', has_bom: false, image_url: null, is_active: false },
  ];

  beforeEach(() => {
    vi.clearAllMocks();
    copyBomOut.mockResolvedValue({ results: [{ variant_id: 2, status: 'copied', rows: 3 }] });
  });

  it('shows each source variant with its picture (or a placeholder) in "Copy from another variant"', async () => {
    const { default: userEvent } = await import('@testing-library/user-event');
    const user = userEvent.setup();
    renderMenu({ siblings: WITH_IMAGES });
    await openPanel(user);

    await user.click(screen.getByRole('combobox', { name: 'Salin dari varian lain' }));

    const list = screen.getByRole('listbox');
    expect(list.querySelectorAll('img')).toHaveLength(1); // hanya sumber ber-BOM: Green punya gambar
    expect(list.querySelector('img')).toHaveAttribute('src', 'https://example.test/green.png');
  });

  it('reveals a pick list of the OTHER variants and keeps Copy disabled until at least one is ticked', async () => {
    const { default: userEvent } = await import('@testing-library/user-event');
    const user = userEvent.setup();
    renderMenu({ siblings: WITH_IMAGES });
    await openPanel(user);

    await user.click(screen.getByRole('button', { name: 'Salin ke varian pilihan' }));

    expect(screen.getAllByRole('checkbox')).toHaveLength(3); // tanpa varian sumber sendiri
    expect(screen.queryByRole('checkbox', { name: /KC001/ })).not.toBeInTheDocument();
    expect(screen.getByRole('button', { name: 'Salin ke 0 varian' })).toBeDisabled();
  });

  it('copies to the ticked variants with one request when none of them has rows', async () => {
    const { default: userEvent } = await import('@testing-library/user-event');
    const user = userEvent.setup();
    const { emitted } = renderMenu({ siblings: WITH_IMAGES });
    await openPanel(user);
    await user.click(screen.getByRole('button', { name: 'Salin ke varian pilihan' }));

    await user.click(screen.getByRole('checkbox', { name: /KC002/ }));
    await user.click(screen.getByRole('checkbox', { name: /KC004/ }));
    await user.click(screen.getByRole('button', { name: 'Salin ke 2 varian' }));

    await waitFor(() => expect(copyBomOut).toHaveBeenCalledWith(1, 'selected', false, [2, 4]));
    expect(emitted().copied).toHaveLength(1);
  });

  it('asks first, naming exactly the ticked variants that already have rows, then copies with confirm_replace', async () => {
    const { default: userEvent } = await import('@testing-library/user-event');
    const user = userEvent.setup();
    renderMenu({ siblings: WITH_IMAGES });
    await openPanel(user);
    await user.click(screen.getByRole('button', { name: 'Salin ke varian pilihan' }));

    await user.click(screen.getByRole('checkbox', { name: /KC002/ }));
    await user.click(screen.getByRole('checkbox', { name: /KC003/ })); // Green sudah punya BOM
    await user.click(screen.getByRole('button', { name: 'Salin ke 2 varian' }));

    const dialog = await screen.findByRole('dialog', { name: /ganti baris bom yang ada/i });
    expect(within(dialog).getByText(/KC003 — Green/)).toBeInTheDocument();
    expect(within(dialog).queryByText(/KC002/)).not.toBeInTheDocument();
    expect(copyBomOut).not.toHaveBeenCalled();

    await user.click(within(dialog).getByRole('button', { name: 'Salin' }));
    await waitFor(() => expect(copyBomOut).toHaveBeenCalledWith(1, 'selected', true, [2, 3]));
  });

  it('goes through the parent guard (unsaved-changes check) before copying to the chosen variants', async () => {
    const { default: userEvent } = await import('@testing-library/user-event');
    const user = userEvent.setup();
    const guard = vi.fn((action) => action());
    renderMenu({ siblings: WITH_IMAGES, guard });
    await openPanel(user);
    await user.click(screen.getByRole('button', { name: 'Salin ke varian pilihan' }));
    await user.click(screen.getByRole('checkbox', { name: /KC002/ }));

    await user.click(screen.getByRole('button', { name: 'Salin ke 1 varian' }));

    expect(guard).toHaveBeenCalledTimes(1);
    await waitFor(() => expect(copyBomOut).toHaveBeenCalledWith(1, 'selected', false, [2]));
  });

  it('clears the selection after a successful copy', async () => {
    const { default: userEvent } = await import('@testing-library/user-event');
    const user = userEvent.setup();
    renderMenu({ siblings: WITH_IMAGES });
    await openPanel(user);
    await user.click(screen.getByRole('button', { name: 'Salin ke varian pilihan' }));
    await user.click(screen.getByRole('checkbox', { name: /KC002/ }));
    await user.click(screen.getByRole('button', { name: 'Salin ke 1 varian' }));

    await waitFor(() => expect(copyBomOut).toHaveBeenCalled());
    await waitFor(() => expect(screen.queryByRole('checkbox', { name: /KC002/ })).not.toBeInTheDocument());
  });
});

