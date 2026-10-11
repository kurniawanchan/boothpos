import { describe, it, expect } from 'vitest';
import { render, screen, fireEvent, within } from '@testing-library/vue';
import SettingsLayout from '../../../resources/js/components/page/SettingsLayout.vue';

const GROUPS = [
  { key: 'org', label: 'Organisasi', items: [{ key: 'outlet', label: 'Outlet' }] },
  { key: 'fin', label: 'Keuangan', items: [{ key: 'tax', label: 'Pajak & pembulatan' }, { key: 'pay', label: 'Metode pembayaran', badge: 2, badgeTone: 'warning' }] },
  { key: 'dev', label: 'Perangkat', items: [{ key: 'pos', label: 'Perangkat POS', badge: 8 }] },
];

describe('SettingsLayout', () => {
  it('menampilkan bagian berkelompok dengan judul kelompok', () => {
    render(SettingsLayout, { props: { groups: GROUPS, modelValue: 'outlet' }, slots: { default: '<p>Isi bagian</p>' } });
    const nav = screen.getByRole('navigation');
    for (const g of ['Organisasi', 'Keuangan', 'Perangkat']) expect(within(nav).getByRole('heading', { name: g })).toBeInTheDocument();
    expect(screen.getByText('Isi bagian')).toBeInTheDocument();
  });

  it('menandai bagian terpilih dengan aria-current', () => {
    render(SettingsLayout, { props: { groups: GROUPS, modelValue: 'tax' } });
    expect(screen.getByRole('button', { name: /Pajak & pembulatan/ })).toHaveAttribute('aria-current', 'page');
    expect(screen.getByRole('button', { name: 'Outlet' })).not.toHaveAttribute('aria-current');
  });

  it('memancarkan key bagian yang dipilih', async () => {
    const { emitted } = render(SettingsLayout, { props: { groups: GROUPS, modelValue: 'outlet' } });
    await fireEvent.click(screen.getByRole('button', { name: /Perangkat POS/ }));
    expect(emitted()['update:modelValue']).toEqual([['pos']]);
  });

  it('menampilkan lencana jumlah masalah dengan nada, bawaan danger', () => {
    render(SettingsLayout, { props: { groups: GROUPS, modelValue: 'outlet' } });
    expect(within(screen.getByRole('button', { name: /Metode pembayaran/ })).getByText('2')).toHaveClass('bg-warn-bg');
    expect(within(screen.getByRole('button', { name: /Perangkat POS/ })).getByText('8')).toHaveClass('bg-danger-bg');
    expect(within(screen.getByRole('button', { name: 'Outlet' })).queryByText(/\d/)).toBeNull();
  });
});
