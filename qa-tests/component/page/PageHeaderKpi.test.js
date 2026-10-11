import { describe, it, expect, beforeEach } from 'vitest';
import { render, screen, fireEvent } from '@testing-library/vue';
import PageHeader from '../../../resources/js/components/page/PageHeader.vue';
import KpiStrip from '../../../resources/js/components/page/KpiStrip.vue';
import KpiCard from '../../../resources/js/components/page/KpiCard.vue';
import { customHeaderCount } from '../../../resources/js/composables/usePageHeader';

beforeEach(() => {
  customHeaderCount.value = 0;
});

describe('PageHeader', () => {
  it('menampilkan judul sebagai h1 #page-heading, deskripsi, dan tindakan', () => {
    render(PageHeader, {
      props: { title: 'Penjualan', description: 'Catatan penjualan immutable' },
      slots: { actions: '<button>Ekspor</button>' },
    });
    const h1 = screen.getByRole('heading', { level: 1, name: 'Penjualan' });
    expect(h1).toHaveAttribute('id', 'page-heading');
    expect(screen.getByText('Catatan penjualan immutable')).toBeInTheDocument();
    expect(screen.getByRole('button', { name: 'Ekspor' })).toBeInTheDocument();
  });

  it('mendaftar ke kerangka saat terpasang dan melepas saat dilepas (judul bawaan disembunyikan)', async () => {
    const { unmount } = render(PageHeader, { props: { title: 'A' } });
    expect(customHeaderCount.value).toBe(1);
    unmount();
    expect(customHeaderCount.value).toBe(0);
  });

  it('tanpa deskripsi dan tindakan tidak merender elemen kosong', () => {
    const { container } = render(PageHeader, { props: { title: 'A' } });
    expect(container.querySelector('p')).toBeNull();
  });
});

describe('KpiCard', () => {
  it('menampilkan label, nilai, dan catatan', () => {
    render(KpiCard, { props: { label: 'Order terbuka', value: '53', note: '38 terbuka · 12 pre-bill' } });
    expect(screen.getByText('Order terbuka')).toBeInTheDocument();
    expect(screen.getByText('53')).toBeInTheDocument();
    expect(screen.getByText('38 terbuka · 12 pre-bill')).toBeInTheDocument();
  });

  it('menampilkan "—" (bukan 0) saat nilai tidak tersedia', () => {
    render(KpiCard, { props: { label: 'Selisih kas', value: null } });
    expect(screen.getByText('—')).toBeInTheDocument();
    expect(screen.queryByText('0')).toBeNull();
    expect(screen.getByText('Tidak tersedia')).toHaveClass('sr-only');
  });

  it('tetap menampilkan angka 0 yang sah', () => {
    render(KpiCard, { props: { label: 'Meja > 45 menit', value: 0 } });
    expect(screen.getByText('0')).toBeInTheDocument();
  });

  it.each([
    ['info', 'bg-marker-info'],
    ['accent', 'bg-marker-accent'],
    ['success', 'bg-marker-success'],
    ['warning', 'bg-marker-warning'],
    ['danger', 'bg-marker-danger'],
    ['neutral', 'bg-marker-neutral'],
    ['tidak-dikenal', 'bg-marker-neutral'],
  ])('penanda nada %s memakai token %s', (tone, cls) => {
    const { container } = render(KpiCard, { props: { label: 'x', value: '1', tone } });
    expect(container.querySelector('span[aria-hidden="true"]')).toHaveClass(cls);
  });

  it('hanya menjadi tombol dan memancarkan select bila clickable', async () => {
    const { emitted, rerender } = render(KpiCard, { props: { label: 'x', value: '1' } });
    expect(screen.queryByRole('button')).toBeNull();
    await rerender({ clickable: true });
    await fireEvent.click(screen.getByRole('button'));
    expect(emitted().select).toHaveLength(1);
  });
});

describe('KpiStrip', () => {
  const items = [
    { key: 'open', label: 'Order terbuka', value: '53', tone: 'info' },
    { key: 'tables', label: 'Meja terisi', value: '20 / 38', tone: 'accent' },
    { key: 'late', label: 'Meja > 45 menit', value: '0', tone: 'neutral' },
    { key: 'value', label: 'Nilai order terbuka', value: 'Rp 1.416.800', tone: 'success', clickable: true },
  ];

  it('merender satu kartu per item (empat angka)', () => {
    render(KpiStrip, { props: { items } });
    for (const i of items) expect(screen.getByText(i.label)).toBeInTheDocument();
    expect(screen.getByText('Rp 1.416.800')).toBeInTheDocument();
  });

  it('memancarkan key item yang diklik', async () => {
    const { emitted } = render(KpiStrip, { props: { items } });
    await fireEvent.click(screen.getByRole('button', { name: /Nilai order terbuka/ }));
    expect(emitted().select).toEqual([['value']]);
  });
});
