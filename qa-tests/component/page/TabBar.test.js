import { describe, it, expect } from 'vitest';
import { render, screen, fireEvent } from '@testing-library/vue';
import { ref, h, defineComponent } from 'vue';
import TabBar from '../../../resources/js/components/page/TabBar.vue';

const TABS = [
  { key: 'all', label: 'Semua', count: 486 },
  { key: 'open', label: 'Terbuka', count: 38 },
  { key: 'late', label: 'Terlambat', count: 2, tone: 'danger' },
  { key: 'plain', label: 'Tanpa jumlah' },
];

// Pembungkus dengan v-model sungguhan supaya perilaku panah/Home/End (yang bergantung pada nilai terbaru) teruji utuh.
const Harness = defineComponent({
  props: { start: { type: String, default: 'all' } },
  setup(props) {
    const current = ref(props.start);
    return () => h('div', [h(TabBar, { tabs: TABS, modelValue: current.value, label: 'Status', 'onUpdate:modelValue': (v) => (current.value = v) }), h('output', { 'data-testid': 'cur' }, current.value)]);
  },
});

describe('TabBar', () => {
  it('memakai pola tablist dan menandai tab terpilih', () => {
    render(TabBar, { props: { tabs: TABS, modelValue: 'open', label: 'Status' } });
    expect(screen.getByRole('tablist', { name: 'Status' })).toBeInTheDocument();
    expect(screen.getByRole('tab', { name: /Terbuka/ })).toHaveAttribute('aria-selected', 'true');
    expect(screen.getByRole('tab', { name: /Semua/ })).toHaveAttribute('aria-selected', 'false');
  });

  it('menampilkan chip jumlah per tab dan tidak merender chip bila jumlah tidak diberikan', () => {
    render(TabBar, { props: { tabs: TABS, modelValue: 'all' } });
    expect(screen.getByRole('tab', { name: /Semua/ })).toHaveTextContent('486');
    expect(screen.getByRole('tab', { name: 'Tanpa jumlah' })).toBeInTheDocument();
  });

  it('jumlah 0 tetap tampil (bukan disembunyikan)', () => {
    render(TabBar, { props: { tabs: [{ key: 'a', label: 'Kosong', count: 0 }], modelValue: 'a' } });
    expect(screen.getByRole('tab', { name: /Kosong/ })).toHaveTextContent('0');
  });

  it('hanya tab terpilih yang masuk urutan Tab (roving tabindex)', () => {
    render(TabBar, { props: { tabs: TABS, modelValue: 'open' } });
    expect(screen.getByRole('tab', { name: /Terbuka/ })).toHaveAttribute('tabindex', '0');
    expect(screen.getByRole('tab', { name: /Semua/ })).toHaveAttribute('tabindex', '-1');
  });

  it('klik memancarkan update:modelValue', async () => {
    const { emitted } = render(TabBar, { props: { tabs: TABS, modelValue: 'all' } });
    await fireEvent.click(screen.getByRole('tab', { name: /Terbuka/ }));
    expect(emitted()['update:modelValue']).toEqual([['open']]);
  });

  it('panah kanan/kiri, Home, dan End memindahkan pilihan dan fokus (dengan membungkus)', async () => {
    render(Harness);
    await fireEvent.keyDown(screen.getByRole('tab', { name: /Semua/ }), { key: 'ArrowRight' });
    expect(screen.getByTestId('cur')).toHaveTextContent('open');
    expect(screen.getByRole('tab', { name: /Terbuka/ })).toHaveFocus();
    await fireEvent.keyDown(screen.getByRole('tab', { name: /Terbuka/ }), { key: 'End' });
    expect(screen.getByTestId('cur')).toHaveTextContent('plain');
    await fireEvent.keyDown(screen.getByRole('tab', { name: 'Tanpa jumlah' }), { key: 'ArrowRight' });
    expect(screen.getByTestId('cur')).toHaveTextContent('all'); // membungkus
    await fireEvent.keyDown(screen.getByRole('tab', { name: /Semua/ }), { key: 'ArrowLeft' });
    expect(screen.getByTestId('cur')).toHaveTextContent('plain');
    await fireEvent.keyDown(screen.getByRole('tab', { name: 'Tanpa jumlah' }), { key: 'Home' });
    expect(screen.getByTestId('cur')).toHaveTextContent('all');
  });
});
