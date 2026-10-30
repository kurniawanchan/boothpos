import { describe, it, expect, vi, beforeEach } from 'vitest';
import { render, screen, waitFor, within } from '@testing-library/vue';
import { createPinia, setActivePinia } from 'pinia';
import ModifierLinksDrawer from '../../resources/js/components/product/ModifierLinksDrawer.vue';
import { useSettingsStore } from '../../resources/js/stores/settings';
import { useToastStore } from '../../resources/js/stores/toast';
import { saveModifierLinks } from '../../resources/js/api/products';
import { listModifierGroups } from '../../resources/js/api/modifierGroups';
import { ApiError } from '../../resources/js/utils/errors';
import id from '../../resources/js/locales/id.json';

const P = id.products;

vi.mock('../../resources/js/api/products', () => ({ saveModifierLinks: vi.fn() }));
vi.mock('../../resources/js/api/modifierGroups', () => ({ listModifierGroups: vi.fn() }));

const PRODUCT = { id: 12, name: 'Kopi Tubruk' };
const LINKS = [
  { group_id: 5, name: 'Gula', source: 'product', is_required: false, min_select: 0, max_select: 1, display_order: 1, options: ['Tanpa', 'Normal'] },
  { group_id: 6, name: 'Ukuran', source: 'product', is_required: true, min_select: 1, max_select: 2, display_order: 2, options: ['S', 'L'] },
  { group_id: 9, name: 'Topping', source: 'category', is_required: false, min_select: 0, max_select: null, display_order: 3, options: ['Boba'] },
];
const GROUPS = [
  { id: 5, name: 'Gula', selection_type: 'single', is_required: false, min_select: 0, max_select: 1, is_active: true },
  { id: 6, name: 'Ukuran', selection_type: 'multiple', is_required: true, min_select: 1, max_select: 2, is_active: true },
  { id: 7, name: 'Es batu', selection_type: 'single', is_required: true, min_select: 0, max_select: 1, is_active: true },
  { id: 8, name: 'Sirup', selection_type: 'multiple', is_required: false, min_select: 0, max_select: 3, is_active: true },
  { id: 10, name: 'Lama', selection_type: 'multiple', is_required: false, min_select: 0, max_select: null, is_active: false },
];

async function userEv() {
  const { default: userEvent } = await import('@testing-library/user-event');
  return userEvent.setup();
}

function renderDrawer({ release = false, links = LINKS, excluded = [] } = {}) {
  const pinia = createPinia();
  setActivePinia(pinia);
  useSettingsStore().menuReleaseEnabled = release;
  return render(ModifierLinksDrawer, { props: { open: true, product: PRODUCT, links, excluded }, global: { plugins: [pinia] } });
}

const rows = () => screen.getAllByTestId('ml-row');

// 043 (T071) — laci Grup modifier.
describe('ModifierLinksDrawer (043)', () => {
  beforeEach(() => {
    vi.clearAllMocks();
    listModifierGroups.mockResolvedValue({ data: GROUPS });
    saveModifierLinks.mockResolvedValue({ applied: true, change: null });
  });

  it('lists the item\'s own links with their rules, keeps category groups read-only, and says contents are edited on Setup', async () => {
    renderDrawer();
    expect(rows()).toHaveLength(2);
    expect(screen.getByLabelText('Minimum Ukuran')).toHaveValue(1);
    expect(screen.getByLabelText('Maksimum Ukuran')).toHaveValue(2);
    expect(screen.getByLabelText('Wajib: Ukuran')).toBeChecked();
    expect(screen.getByLabelText('Wajib: Gula')).not.toBeChecked();
    expect(screen.getByTestId('ml-hint')).toHaveTextContent(/layar setup restoran/i);
    const inherited = screen.getByTestId('ml-inherited');
    expect(inherited).toHaveTextContent('Topping');
    expect(inherited).toHaveTextContent('Opsional · 0+');
    expect(within(inherited).queryByRole('checkbox')).not.toBeInTheDocument();
  }, 30000);

  it('saves ALL product links in row order with display_order, null max for "no limit", and never the category group', async () => {
    const user = await userEv();
    const view = renderDrawer();
    // urutkan ulang: Ukuran naik ke atas
    await user.click(screen.getByRole('button', { name: /naikkan ukuran/i }));
    // Gula: jadikan wajib (minimum harus ikut naik ke 1); Ukuran: tanpa batas atas
    await user.click(screen.getByLabelText('Wajib: Gula'));
    expect(screen.getByLabelText('Minimum Gula')).toHaveValue(1);
    await user.clear(screen.getByLabelText('Maksimum Ukuran'));
    await user.click(screen.getByRole('button', { name: /^simpan modifier$/i }));

    await waitFor(() => expect(saveModifierLinks).toHaveBeenCalledTimes(1));
    expect(saveModifierLinks).toHaveBeenCalledWith(12, [
      { group_id: 6, is_required: true, min_select: 1, max_select: null, display_order: 1 },
      { group_id: 5, is_required: true, min_select: 1, max_select: 1, display_order: 2 },
    ]);
    await waitFor(() => expect(view.emitted().saved).toHaveLength(1));
  }, 30000);

  it('moving a row down / removing a link changes what is sent', async () => {
    const user = await userEv();
    renderDrawer();
    await user.click(screen.getByRole('button', { name: /turunkan gula/i }));
    await user.click(screen.getByRole('button', { name: /lepas tautan ukuran/i }));
    expect(rows()).toHaveLength(1);
    await user.click(screen.getByRole('button', { name: /^simpan modifier$/i }));
    await waitFor(() => expect(saveModifierLinks).toHaveBeenCalled());
    expect(saveModifierLinks.mock.calls[0][1]).toEqual([{ group_id: 5, is_required: false, min_select: 0, max_select: 1, display_order: 1 }]);
  }, 30000);

  it('can send an empty list (unlink everything)', async () => {
    const user = await userEv();
    renderDrawer({ links: [] });
    expect(screen.getByTestId('ml-empty')).toBeInTheDocument();
    await user.click(screen.getByRole('button', { name: /^simpan modifier$/i }));
    await waitFor(() => expect(saveModifierLinks).toHaveBeenCalledWith(12, []));
  }, 30000);

  it('links a new group from the list of unlinked, active groups, starting from that group\'s own rule', async () => {
    const user = await userEv();
    renderDrawer();
    await user.click(await screen.findByRole('combobox', { name: /tautkan grup/i }));
    const options = screen.getAllByRole('option').map((o) => o.textContent.trim());
    expect(options).toEqual(expect.arrayContaining(['Es batu', 'Sirup']));
    expect(options).not.toContain('Gula'); // sudah tertaut
    expect(options).not.toContain('Lama'); // grup nonaktif
    await user.click(screen.getByRole('option', { name: 'Sirup' }));
    expect(rows()).toHaveLength(3);
    expect(screen.getByLabelText('Maksimum Sirup')).toHaveValue(3);

    await user.click(screen.getByRole('combobox', { name: /tautkan grup/i }));
    await user.click(screen.getByRole('option', { name: 'Es batu' }));
    // grup PILIHAN TUNGGAL: maksimum 1, minimum mengikuti wajib
    expect(screen.getByLabelText('Maksimum Es batu')).toHaveValue(1);
    expect(screen.getByLabelText('Minimum Es batu')).toHaveValue(1);

    await user.click(screen.getByRole('button', { name: /^simpan modifier$/i }));
    await waitFor(() => expect(saveModifierLinks).toHaveBeenCalled());
    const sent = saveModifierLinks.mock.calls[0][1];
    expect(sent.map((l) => l.group_id)).toEqual([5, 6, 8, 7]);
    expect(sent[3]).toEqual({ group_id: 7, is_required: true, min_select: 1, max_select: 1, display_order: 4 });
  }, 30000);

  it('mirrors the server rules client-side: min ≥ 0, max empty or ≥ 1 and ≥ min, single-choice groups max 1', async () => {
    const user = await userEv();
    renderDrawer();
    // Ukuran: min 3 > max 2
    const min = screen.getByLabelText('Minimum Ukuran');
    await user.clear(min);
    await user.type(min, '3');
    // Gula (grup tunggal): maksimum 4 tidak boleh
    const gulaMax = screen.getByLabelText('Maksimum Gula');
    await user.clear(gulaMax);
    await user.type(gulaMax, '4');
    await user.click(screen.getByRole('button', { name: /^simpan modifier$/i }));

    expect(await screen.findByText(/tidak kurang dari minimum/i)).toBeInTheDocument();
    expect(screen.getByText(/grup pilihan tunggal: maksimum 1/i)).toBeInTheDocument();
    expect(saveModifierLinks).not.toHaveBeenCalled();

    const maxUkuran = screen.getByLabelText('Maksimum Ukuran');
    await user.clear(maxUkuran);
    await user.type(maxUkuran, '0');
    await user.click(screen.getByRole('button', { name: /^simpan modifier$/i }));
    // maks 0 tidak sah (≥ 1 atau kosong): pesan tetap tampil di baris Ukuran dan tak ada permintaan
    expect(await within(rows()[1]).findByText(/bilangan bulat 1 atau lebih/i)).toBeInTheDocument();
    expect(saveModifierLinks).not.toHaveBeenCalled();

    // memperbaiki ketiganya membuat simpan lolos
    await user.clear(maxUkuran);
    await user.clear(screen.getByLabelText('Minimum Ukuran'));
    await user.type(screen.getByLabelText('Minimum Ukuran'), '1');
    await user.clear(gulaMax);
    await user.type(gulaMax, '1');
    await user.click(screen.getByRole('button', { name: /^simpan modifier$/i }));
    await waitFor(() => expect(saveModifierLinks).toHaveBeenCalledTimes(1));
  }, 30000);

  it('shows server 422 field errors on the right row (links.N.max_select) and does not close', async () => {
    const user = await userEv();
    const view = renderDrawer();
    saveModifierLinks.mockRejectedValueOnce(new ApiError('invalid', { status: 422, errors: { 'links.1.max_select': ['Maks tidak valid (server).'] } }));
    await user.click(screen.getByRole('button', { name: /^simpan modifier$/i }));
    const error = await screen.findByText('Maks tidak valid (server).');
    expect(error.closest('[data-testid="ml-row"]')).toHaveTextContent('Ukuran');
    expect(view.emitted().saved).toBeUndefined();
  }, 30000);

  it('with releases on: the label is "Ajukan perubahan" and an {applied:false} answer says it is waiting for the release', async () => {
    const user = await userEv();
    const view = renderDrawer({ release: true });
    saveModifierLinks.mockResolvedValueOnce({ applied: false, change: { id: 3, kind: 'modifier_link', status: 'pending' } });
    await user.click(screen.getByRole('button', { name: /ajukan perubahan/i }));
    await waitFor(() => expect(view.emitted().saved).toHaveLength(1));
    expect(view.emitted().saved[0][0]).toMatchObject({ applied: false });
    expect(useToastStore().items.map((i) => i.message).join(' ')).toMatch(/menunggu rilis menu berikutnya/i);
  }, 30000);

  it('a failed group list is an error with Retry — existing links stay editable, linking a new one waits for the retry', async () => {
    const user = await userEv();
    listModifierGroups.mockRejectedValueOnce(new ApiError('Forbidden', { status: 403 }));
    renderDrawer();
    expect(await screen.findByTestId('ml-groups-error')).toBeInTheDocument();
    expect(screen.queryByRole('combobox', { name: /tautkan grup/i })).not.toBeInTheDocument();
    expect(screen.getByLabelText('Minimum Ukuran')).toBeEnabled();

    await user.click(within(screen.getByTestId('ml-groups-error')).getByRole('button', { name: /coba lagi/i }));
    expect(await screen.findByRole('combobox', { name: /tautkan grup/i })).toBeInTheDocument();
    expect(screen.queryByTestId('ml-groups-error')).not.toBeInTheDocument();
  }, 30000);

  // 086 — melepas / memulihkan grup warisan kategori di laci.
  it('releases a category group: moves it to "dilepas" and saves excluded_group_ids as the third argument', async () => {
    renderDrawer();
    const user = await userEv();

    await user.click(within(screen.getByTestId('ml-inherited')).getByRole('button', { name: P.ml_exclude_aria.replace('{name}', 'Topping') }));
    expect(within(screen.getByTestId('im-excluded')).getByText('Topping')).toBeInTheDocument();
    await user.click(screen.getByRole('button', { name: P.ml_save }));

    await waitFor(() => expect(saveModifierLinks).toHaveBeenCalledTimes(1));
    const [id, links, excluded] = saveModifierLinks.mock.calls[0];
    expect(id).toBe(12);
    expect(links.map((l) => l.group_id)).toEqual([5, 6]);
    expect(excluded).toEqual([9]);
  });

  it('does NOT send the exclusion key when nothing about it changed (a plain rule edit keeps exclusions)', async () => {
    renderDrawer({ excluded: [LINKS[2]], links: LINKS.slice(0, 2) });
    const user = await userEv();
    await user.click(screen.getByLabelText('Wajib: Gula'));
    await user.click(screen.getByRole('button', { name: P.ml_save }));

    await waitFor(() => expect(saveModifierLinks).toHaveBeenCalledTimes(1));
    expect(saveModifierLinks.mock.calls[0]).toHaveLength(2);
  });

  it('restores a released group and sends an empty list so the server forgets the exclusion', async () => {
    renderDrawer({ excluded: [LINKS[2]], links: LINKS.slice(0, 2) });
    const user = await userEv();

    await user.click(within(screen.getByTestId('im-excluded')).getByRole('button', { name: P.ml_restore_aria.replace('{name}', 'Topping') }));
    expect(within(screen.getByTestId('im-inherited')).getByText('Topping')).toBeInTheDocument();
    await user.click(screen.getByRole('button', { name: P.ml_save }));

    await waitFor(() => expect(saveModifierLinks).toHaveBeenCalledTimes(1));
    expect(saveModifierLinks.mock.calls[0][2]).toEqual([]);
  });
});
