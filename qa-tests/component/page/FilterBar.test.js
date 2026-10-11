import { describe, it, expect } from 'vitest';
import { render, screen, fireEvent } from '@testing-library/vue';
import FilterBar from '../../../resources/js/components/page/FilterBar.vue';

describe('FilterBar', () => {
  it('menampilkan kolom cari dengan placeholder dan memancarkan update:search saat mengetik', async () => {
    const { emitted } = render(FilterBar, { props: { search: '', placeholder: 'Cari no. struk atau kasir…' } });
    const box = screen.getByPlaceholderText('Cari no. struk atau kasir…');
    await fireEvent.update(box, 'WJM3');
    expect(emitted()['update:search']).toEqual([['WJM3']]);
  });

  it('mencerminkan nilai pencarian yang diberikan', () => {
    render(FilterBar, { props: { search: 'meja 4' } });
    expect(screen.getByRole('searchbox')).toHaveValue('meja 4');
  });

  it('menampilkan penghitung "N data" untuk hasil terfilter dengan titik ribuan', () => {
    render(FilterBar, { props: { count: 1284 } });
    expect(screen.getByTestId('data-count')).toHaveTextContent('1.284 data');
  });

  it('penghitung 0 tetap tampil, dan tidak ada penghitung bila count null', () => {
    const { rerender } = render(FilterBar, { props: { count: 0 } });
    expect(screen.getByTestId('data-count')).toHaveTextContent('0 data');
    return rerender({ count: null }).then(() => expect(screen.queryByTestId('data-count')).toBeNull());
  });

  it('tombol atur ulang hanya muncul saat ada filter aktif dan memancarkan reset', async () => {
    const { emitted, rerender } = render(FilterBar, { props: { active: false } });
    expect(screen.queryByRole('button', { name: /atur ulang/i })).toBeNull();
    await rerender({ active: true });
    await fireEvent.click(screen.getByRole('button', { name: /atur ulang/i }));
    expect(emitted().reset).toHaveLength(1);
  });

  it('merender filter tambahan lewat slot', () => {
    render(FilterBar, { slots: { default: '<select aria-label="Outlet"><option>Semua</option></select>' } });
    expect(screen.getByRole('combobox', { name: 'Outlet' })).toBeInTheDocument();
  });

  it('searchable=false menyembunyikan kolom cari tetapi tetap menampilkan penghitung dan reset', () => {
    render(FilterBar, { props: { searchable: false, count: 5, active: true } });
    expect(screen.queryByRole('searchbox')).toBeNull();
    expect(screen.getByTestId('data-count')).toHaveTextContent('5 data');
    expect(screen.getByRole('button', { name: /atur ulang/i })).toBeInTheDocument();
  });
});
