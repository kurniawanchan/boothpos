import { describe, it, expect, vi, beforeEach } from 'vitest';
import { render, screen, waitFor } from '@testing-library/vue';
import { ref } from 'vue';
import RoleMenuPicker from '../../resources/js/components/settings/RoleMenuPicker.vue';

vi.mock('../../resources/js/api/roles', () => ({
  listMenuKeys: vi.fn().mockResolvedValue({
    data: [
      { key: 'dashboard', label: 'Beranda', actions: [] },
      { key: 'sales', label: 'Penjualan', actions: [
        { key: 'void', label: 'Batalkan penjualan', standard: false },
        { key: 'export', label: 'Ekspor', standard: false },
      ] },
      { key: 'customers', label: 'Pelanggan', actions: [
        { key: 'create', label: 'Tambah', standard: true },
        { key: 'edit', label: 'Ubah', standard: true },
        { key: 'delete', label: 'Hapus', standard: true },
      ] },
    ],
  }),
}));

/** 057 (US2) — grid menu × aksi pada editor peran. */
describe('RoleMenuPicker — actions grid (057)', () => {
  let state;
  beforeEach(() => { state = { menus: ref([]), actions: ref({}) }; });

  async function mountPicker(props = {}) {
    const utils = render(RoleMenuPicker, {
      props: {
        modelValue: state.menus.value,
        actions: state.actions.value,
        'onUpdate:modelValue': (v) => { state.menus.value = v; utils.rerender({ modelValue: v, actions: state.actions.value, ...props }); },
        'onUpdate:actions': (v) => { state.actions.value = v; utils.rerender({ modelValue: state.menus.value, actions: v, ...props }); },
        ...props,
      },
    });
    await screen.findByLabelText('Penjualan');
    return utils;
  }

  it('ticking a menu selects it with FULL access by default and shows its action boxes', async () => {
    const { default: userEvent } = await import('@testing-library/user-event');
    const user = userEvent.setup();
    await mountPicker();

    await user.click(screen.getByLabelText('Penjualan'));

    expect(state.menus.value).toEqual(['sales']);
    expect(state.actions.value).toEqual({ sales: ['void', 'export'] });
    expect(screen.getByLabelText('Penjualan – Lihat')).toBeChecked();
    expect(screen.getByLabelText('Penjualan – Lihat')).toBeDisabled();   // View selalu menyala
    expect(screen.getByLabelText('Penjualan – Batalkan penjualan')).toBeChecked();
  });

  it('"Hanya lihat" clears the actions; "Akses penuh" restores them', async () => {
    const { default: userEvent } = await import('@testing-library/user-event');
    const user = userEvent.setup();
    state.menus.value = ['sales'];
    state.actions.value = { sales: ['void', 'export'] };
    await mountPicker();

    await user.click(screen.getByRole('button', { name: 'Penjualan: hanya lihat' }));
    expect(state.actions.value).toEqual({ sales: [] });

    await user.click(screen.getByRole('button', { name: 'Penjualan: akses penuh' }));
    expect(state.actions.value).toEqual({ sales: ['void', 'export'] });
  });

  it('toggling a single action adds/removes exactly that action', async () => {
    const { default: userEvent } = await import('@testing-library/user-event');
    const user = userEvent.setup();
    state.menus.value = ['customers'];
    state.actions.value = { customers: ['create', 'edit', 'delete'] };
    await mountPicker();

    await user.click(screen.getByLabelText('Pelanggan – Hapus'));

    expect(state.actions.value).toEqual({ customers: ['create', 'edit'] });
  });

  it('unticking a menu removes it and its actions', async () => {
    const { default: userEvent } = await import('@testing-library/user-event');
    const user = userEvent.setup();
    state.menus.value = ['sales'];
    state.actions.value = { sales: ['void'] };
    await mountPicker();

    await user.click(screen.getByLabelText('Penjualan'));

    expect(state.menus.value).toEqual([]);
    expect(state.actions.value).toEqual({});
  });

  it('a menu with no actions shows only View (no shortcuts needed)', async () => {
    const { default: userEvent } = await import('@testing-library/user-event');
    const user = userEvent.setup();
    await mountPicker();

    await user.click(screen.getByLabelText('Beranda'));

    expect(screen.getByLabelText('Beranda – Lihat')).toBeChecked();
    expect(screen.queryByRole('button', { name: 'Beranda: hanya lihat' })).toBeNull();
  });

  it('a locked (Owner) role cannot be edited and says why', async () => {
    state.menus.value = ['sales'];
    state.actions.value = { sales: ['void', 'export'] };
    await mountPicker({ locked: true });

    expect(screen.getByLabelText('Penjualan')).toBeDisabled();
    expect(screen.getByLabelText('Penjualan – Batalkan penjualan')).toBeDisabled();
    expect(screen.getByText(/Owner/)).toBeInTheDocument();
  });
});
