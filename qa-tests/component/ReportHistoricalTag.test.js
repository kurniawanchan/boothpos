import { describe, it, expect } from 'vitest';
import { render, screen } from '@testing-library/vue';
import { createPinia, setActivePinia } from 'pinia';
import SettlementTab from '../../resources/js/views/reports/SettlementTab.vue';
import OutletProfitTab from '../../resources/js/views/reports/OutletProfitTab.vue';

// 045 Stage 7 (T099) — baris yang memuat penjualan lama diberi tag "Riwayat"; baris baru tidak.
const ROWS = [
  { outlet_id: 1, outlet_name: 'Warkop 1', total_sales: '20000.00', total_units: 2, historical: false },
  { outlet_id: 2, outlet_name: 'Warkop 2', total_sales: '40000.00', total_units: 4, historical: true },
];

const mount = (component, props) => {
  const pinia = createPinia();
  setActivePinia(pinia);
  return render(component, { props, global: { plugins: [pinia] } });
};

describe('historical tag (045 Stage 7)', () => {
  it('marks only the rows that carry legacy sales in the recap', () => {
    mount(SettlementTab, { rows: ROWS, eventId: 5 });
    const tags = screen.getAllByTestId('historical-tag');
    expect(tags).toHaveLength(1);
    expect(tags[0]).toHaveTextContent('Riwayat');
    expect(tags[0].closest('tr')).toHaveTextContent('Warkop 2');
  });

  it('shows no tag when nothing is historical', () => {
    mount(SettlementTab, { rows: ROWS.map((r) => ({ ...r, historical: false })), eventId: 5 });
    expect(screen.queryAllByTestId('historical-tag')).toHaveLength(0);
  });

  it('marks the cost & profit rows too', () => {
    mount(OutletProfitTab, { rows: ROWS.map((r) => ({ ...r, modal: '1.00', gross_profit: '1.00', sales_pos: '1.00', sales_preorder: '0.00', modal_pos: '1.00', modal_preorder: '0.00', gross_profit_pos: '1.00', gross_profit_preorder: '0.00' })), eventId: 5 });
    expect(screen.getAllByTestId('historical-tag')).toHaveLength(1);
  });
});
