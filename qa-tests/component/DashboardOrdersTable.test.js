import { describe, it, expect, vi, beforeEach } from 'vitest';
import { render, screen, waitFor, within } from '@testing-library/vue';
import { mount, flushPromises } from '@vue/test-utils';
import userEvent from '@testing-library/user-event';
import { createPinia, setActivePinia } from 'pinia';
import { createRouter, createMemoryHistory } from 'vue-router';
import { defineComponent, h } from 'vue';
import DashboardOrdersTable from '../../resources/js/components/dashboard/DashboardOrdersTable.vue';
import { orders, downloadOrdersCsv } from '../../resources/js/api/dashboard';
import { useToastStore } from '../../resources/js/stores/toast';

vi.mock('../../resources/js/api/dashboard', () => ({ orders: vi.fn(), downloadOrdersCsv: vi.fn() }));
// Detail order = modal Sales yang sama; di sini cukup bukti ia dibuka untuk order yang benar.
vi.mock('../../resources/js/components/sales/TransactionItemsModal.vue', () => ({
  __esModule: true, // defineAsyncComponent hanya membuka bungkus modul yang bertanda ini
  default: defineComponent({
    props: { open: Boolean, orderId: { type: [Number, String, null], default: null } },
    emits: ['close', 'changed'],
    render() { return this.open ? h('div', { 'data-testid': 'detail-modal' }, `detail ${this.orderId}`) : null; },
  }),
}));

const ROW = (id, extra = {}) => ({
  id, order_number: `A00${id}`, type: 'dine_in', table: 'T02', items_summary: 'Kopi susu ×2, Roti bakar', status: 'completed', tab: 'paid',
  payment_state: 'paid', methods: ['qr_ewallet'], total_amount: '75900.00', paid_amount: '75900.00', business_date: '2026-10-11',
  at: '2026-10-11T12:21:09+07:00', outlet_id: null, ...extra,
});
const RESPONSE = (rows = [ROW(141), ROW(142, { type: 'takeaway', table: null, tab: 'open', payment_state: 'unpaid', methods: [], total_amount: '30000.00' })], extra = {}) => ({
  data: rows,
  meta: { current_page: 1, per_page: 25, total: 486, last_page: 20 },
  counts: { all: 486, open: 12, partial: 9, paid: 458, cancelled: 7 },
  hidden_tabs: ['bill', 'closed'],
  ...extra,
});

let router;
async function makeRouter(url) {
  router = createRouter({ history: createMemoryHistory(), routes: [{ path: '/dashboard', name: 'dashboard', component: { template: '<div />' } }] });
  router.push(url);
  await router.isReady();
  return router;
}

async function renderTable(url = '/dashboard', props = {}) {
  const pinia = createPinia();
  setActivePinia(pinia);
  await makeRouter(url);
  const user = userEvent.setup();
  const view = render(DashboardOrdersTable, { props: { period: 'today', running: false, ...props }, global: { plugins: [pinia, router] } });
  await screen.findByText('A00141');
  return { ...view, user, pinia };
}

const lastParams = () => orders.mock.calls.at(-1)[0];
const query = () => router.currentRoute.value.query;

describe('DashboardOrdersTable (042 T038)', () => {
  beforeEach(() => {
    vi.clearAllMocks();
    orders.mockImplementation(() => Promise.resolve(RESPONSE()));
    downloadOrdersCsv.mockResolvedValue();
  });

  describe('header, tabs and hidden tabs', () => {
    it('shows the title, the subtitle with the total and the period, and the Ekspor CSV button', async () => {
      await renderTable();
      expect(screen.getByRole('heading', { level: 2, name: /Order terbaru/ })).toBeInTheDocument();
      expect(screen.getByTestId('orders-subtitle')).toHaveTextContent('486 order hari ini · Seluruh toko');
      expect(screen.getByTestId('export-orders')).toHaveTextContent('Ekspor CSV');
    });

    it('words the subtitle for the 7-day and 30-day periods', async () => {
      const { unmount } = await renderTable('/dashboard', { period: '7d' });
      expect(screen.getByTestId('orders-subtitle')).toHaveTextContent('486 order 7 hari terakhir');
      unmount();
      await renderTable('/dashboard', { period: '30d' });
      expect(screen.getByTestId('orders-subtitle')).toHaveTextContent('486 order 30 hari terakhir');
    });

    it('renders the five tabs with counts from the response and NO tab for hidden ones', async () => {
      await renderTable();
      const tabs = screen.getAllByRole('tab');
      expect(tabs.map((t) => t.textContent.replace(/\s+/g, ' ').trim())).toEqual([
        'Semua 486', 'Terbuka 12', 'Dibayar sebagian 9', 'Lunas 458', 'Dibatalkan 7',
      ]);
      expect(screen.queryByRole('tab', { name: /pre-bill|ditutup/i })).toBeNull();
      expect(screen.getByRole('tab', { name: /Semua/ })).toHaveAttribute('aria-selected', 'true');
    });

    it('omits any tab the server lists in hidden_tabs', async () => {
      orders.mockImplementation(() => Promise.resolve(RESPONSE(undefined, { hidden_tabs: ['bill', 'closed', 'cancelled'] })));
      await renderTable();
      expect(screen.getAllByRole('tab')).toHaveLength(4);
      expect(screen.queryByRole('tab', { name: /Dibatalkan/ })).toBeNull();
    });

    it('selecting a tab requests it from page 1 and writes it to the address', async () => {
      const { user } = await renderTable('/dashboard?page=3');
      await user.click(screen.getByRole('tab', { name: /Dibayar sebagian/ }));
      await waitFor(() => expect(lastParams()).toMatchObject({ tab: 'partial', page: 1 }));
      await waitFor(() => expect(query().tab).toBe('partial'));
      await waitFor(() => expect(query().page).toBeUndefined());
    });
  });

  describe('rows', () => {
    it('renders number, store-timezone time, type/table, items, status pill, payment state + method, and total', async () => {
      await renderTable();
      const row = screen.getByText('A00141').closest('tr');
      expect(within(row).getByText('12.21')).toBeInTheDocument();
      expect(within(row).getByText('Dine-in · T02')).toBeInTheDocument();
      expect(within(row).getByText('Kopi susu ×2, Roti bakar')).toHaveAttribute('title', 'Kopi susu ×2, Roti bakar');
      expect(within(row).getByText('Lunas')).toBeInTheDocument();
      expect(within(row).getByText('Berhasil')).toBeInTheDocument();
      expect(within(row).getByText('QRIS / e-wallet')).toBeInTheDocument();
      expect(within(row).getByText('Rp 75.900')).toBeInTheDocument();

      const open = screen.getByText('A00142').closest('tr');
      expect(within(open).getByText('Takeaway')).toBeInTheDocument();
      expect(within(open).getByText('Terbuka', { selector: 'span' })).toBeInTheDocument();
      expect(within(open).getByText('Belum dibayar')).toBeInTheDocument();
    });

    it('clicking the order number opens the detail for that order', async () => {
      const { user } = await renderTable();
      await user.click(screen.getByRole('button', { name: 'Buka detail order A00141' }));
      expect(await screen.findByTestId('detail-modal')).toHaveTextContent('detail 141');
    });

    it('clicking anywhere on the row opens the detail for THAT row', async () => {
      const { user } = await renderTable();
      await user.click(within(screen.getByText('A00142').closest('tr')).getByText('Takeaway'));
      expect(await screen.findByTestId('detail-modal')).toHaveTextContent('detail 142');
    });

    it('shows the empty state and hides the pager when there are no orders', async () => {
      orders.mockImplementation(() => Promise.resolve(RESPONSE([], { meta: { current_page: 1, per_page: 25, total: 0, last_page: 1 }, counts: { all: 0, open: 0, partial: 0, paid: 0, cancelled: 0 } })));
      const pinia = createPinia();
      await makeRouter('/dashboard');
      render(DashboardOrdersTable, { props: { period: 'today' }, global: { plugins: [pinia, router] } });
      expect(await screen.findByText('Tidak ada order untuk tampilan ini.')).toBeInTheDocument();
      expect(screen.queryByLabelText('Halaman berikutnya')).toBeNull();
      expect(screen.getByTestId('orders-subtitle')).toHaveTextContent('0 order');
    });

    it('shows an error with Retry in the table when loading fails, and Retry reloads', async () => {
      orders.mockRejectedValueOnce(new Error('down'));
      const pinia = createPinia();
      await makeRouter('/dashboard');
      const user = userEvent.setup();
      render(DashboardOrdersTable, { props: { period: 'today' }, global: { plugins: [pinia, router] } });
      expect(await screen.findByRole('alert')).toHaveTextContent('Gagal memuat order terbaru');
      await user.click(screen.getByRole('button', { name: /coba lagi/i }));
      expect(await screen.findByText('A00141')).toBeInTheDocument();
      expect(screen.queryByRole('alert')).toBeNull();
    });

    it('pages through the results via the pager and the address', async () => {
      const { user } = await renderTable();
      await user.click(screen.getByRole('button', { name: 'Halaman berikutnya' }));
      await waitFor(() => expect(lastParams()).toMatchObject({ page: 2 }));
      await waitFor(() => expect(query().page).toBe('2'));
    });
  });

  describe('search, filter menus, chips and reset', () => {
    it('debounces the search, sends q, shows a removable chip and the result count', async () => {
      const { user } = await renderTable();
      expect(screen.queryByRole('button', { name: /atur ulang filter/i })).toBeNull(); // tidak ada filter -> tidak ada Reset

      await user.type(screen.getByRole('searchbox'), 'kopi');
      await waitFor(() => expect(lastParams()).toMatchObject({ q: 'kopi', page: 1 }));
      await waitFor(() => expect(query().q).toBe('kopi'));
      expect(screen.getByRole('button', { name: /Hapus filter Cari: kopi/ })).toBeInTheDocument();
      expect(screen.getByTestId('data-count')).toHaveTextContent('486 data');

      await user.click(screen.getByRole('button', { name: /Hapus filter Cari: kopi/ }));
      await waitFor(() => expect(lastParams().q).toBe(''));
      await waitFor(() => expect(query().q).toBeUndefined());
      expect(screen.getByRole('searchbox')).toHaveValue('');
    });

    it('order-type menu: picking Dine-in requests it, adds a chip; removing the chip clears it', async () => {
      const { user } = await renderTable();
      await user.click(screen.getByRole('button', { name: 'Semua tipe order' }));
      await user.click(screen.getByRole('option', { name: 'Dine-in' }));
      await waitFor(() => expect(lastParams().type).toEqual(['dine_in']));
      await waitFor(() => expect(query().type).toBe('dine_in'));

      await user.click(screen.getByRole('button', { name: 'Hapus filter Dine-in' }));
      await waitFor(() => expect(lastParams().type).toEqual([]));
      await waitFor(() => expect(query().type).toBeUndefined());
    });

    it('payment menus: method and payment status are sent as arrays', async () => {
      const { user } = await renderTable();
      await user.click(screen.getByRole('button', { name: 'Semua metode bayar' }));
      await user.click(screen.getByRole('option', { name: 'QRIS / e-wallet' }));
      await waitFor(() => expect(lastParams().method).toEqual(['qr_ewallet']));
      await user.keyboard('{Escape}');

      await user.click(screen.getByRole('button', { name: 'Semua status pembayaran' }));
      await user.click(screen.getByRole('option', { name: 'Menunggu verifikasi' }));
      await waitFor(() => expect(lastParams().pstat).toEqual(['pending']));
      expect(lastParams().method).toEqual(['qr_ewallet']);
    });

    it('business-date range inputs are sent as from/to and shown as chips', async () => {
      const { user } = await renderTable();
      await user.type(screen.getByTestId('orders-from'), '2026-10-01');
      await waitFor(() => expect(lastParams().from).toBe('2026-10-01'));
      expect(screen.getByRole('button', { name: /Hapus filter Dari 2026-10-01/ })).toBeInTheDocument();
      expect(screen.getByTestId('orders-subtitle')).toHaveTextContent('2026-10-01 s.d. …');
    });

    it('"Atur ulang filter" clears every filter but keeps the tab', async () => {
      const { user } = await renderTable('/dashboard?tab=open&q=kopi&type=dine_in&method=cash&pstat=unpaid&from=2026-10-01&to=2026-10-02');
      expect(screen.getAllByRole('button', { name: /^Hapus filter/ })).toHaveLength(6);
      await user.click(screen.getByRole('button', { name: /atur ulang filter/i }));
      await waitFor(() => expect(lastParams()).toMatchObject({ tab: 'open', q: '', type: [], method: [], pstat: [], from: '', to: '' }));
      expect(screen.queryByTestId('orders-chips')).toBeNull();
      await waitFor(() => expect(query().tab).toBe('open'));
      await waitFor(() => expect(query().q).toBeUndefined());
    });
  });

  describe('URL round-trip', () => {
    it('restores tab, search, filters, dates and page from the address on load', async () => {
      await renderTable('/dashboard?tab=partial&q=kopi&type=dine_in,takeaway&method=qr_ewallet&pstat=pending&from=2026-10-01&to=2026-10-11&page=2');
      expect(orders.mock.calls[0][0]).toEqual({
        period: 'today', tab: 'partial', q: 'kopi', type: ['dine_in', 'takeaway'], method: ['qr_ewallet'], pstat: ['pending'],
        from: '2026-10-01', to: '2026-10-11', page: 2, per_page: 25,
      });
      expect(screen.getByRole('tab', { name: /Dibayar sebagian/ })).toHaveAttribute('aria-selected', 'true');
      expect(screen.getByRole('searchbox')).toHaveValue('kopi');
    });

    it('ignores unknown or invalid values instead of sending them to the server', async () => {
      await renderTable('/dashboard?tab=bill&type=foo,retail&method=bitcoin&pstat=refund&from=yesterday&to=2026-10-11&page=-4');
      expect(orders.mock.calls[0][0]).toEqual({
        period: 'today', tab: 'all', q: '', type: ['retail'], method: [], pstat: [], from: '', to: '2026-10-11', page: 1, per_page: 25,
      });
    });

    it('drops a "to" that is earlier than "from" (the server would answer 422)', async () => {
      await renderTable('/dashboard?from=2026-10-10&to=2026-10-01');
      expect(orders.mock.calls[0][0]).toMatchObject({ from: '2026-10-10', to: '' });
    });

    it('re-reads the address when it changes from outside (back/forward, shared link)', async () => {
      await renderTable();
      await router.push('/dashboard?tab=cancelled');
      await waitFor(() => expect(lastParams().tab).toBe('cancelled'));
      expect(screen.getByRole('tab', { name: /Dibatalkan/ })).toHaveAttribute('aria-selected', 'true');
    });
  });

  describe('period, real-time marker and export', () => {
    it('follows the Dashboard period and goes back to page 1 when it changes', async () => {
      const pinia = createPinia();
      await makeRouter('/dashboard?page=3');
      const view = render(DashboardOrdersTable, { props: { period: 'today' }, global: { plugins: [pinia, router] } });
      await screen.findByText('A00141');
      expect(lastParams()).toMatchObject({ period: 'today', page: 3 });
      await view.rerender({ period: '7d' });
      await waitFor(() => expect(lastParams()).toMatchObject({ period: '7d', page: 1 }));
    });

    it('shows the Real-time marker ONLY while the refresh timer is running', async () => {
      const { rerender } = await renderTable('/dashboard', { running: false });
      expect(screen.queryByTestId('realtime-marker')).toBeNull();
      await rerender({ running: true });
      expect(screen.getByTestId('realtime-marker')).toHaveTextContent('Real-time');
      await rerender({ running: false });
      expect(screen.queryByTestId('realtime-marker')).toBeNull();
    });

    it('Ekspor CSV downloads with the CURRENT filters and no paging', async () => {
      const { user } = await renderTable('/dashboard?tab=paid&q=kopi&type=dine_in&page=4');
      await user.click(screen.getByTestId('export-orders'));
      expect(downloadOrdersCsv).toHaveBeenCalledWith({
        period: 'today', tab: 'paid', q: 'kopi', type: ['dine_in'], method: [], pstat: [], from: '', to: '',
      });
    });

    it('toasts an error when the export fails and re-enables the button', async () => {
      downloadOrdersCsv.mockRejectedValue(new Error('403'));
      const { user, pinia } = await renderTable();
      await user.click(screen.getByTestId('export-orders'));
      await waitFor(() => expect(useToastStore(pinia).items.some((i) => i.variant === 'danger')).toBe(true));
      expect(screen.getByTestId('export-orders')).not.toBeDisabled();
    });
  });

  describe('refresh()', () => {
    async function mountExposed(url) {
      const pinia = createPinia();
      setActivePinia(pinia);
      await makeRouter(url);
      const wrapper = mount(DashboardOrdersTable, { props: { period: 'today' }, global: { plugins: [pinia, router] }, attachTo: document.body });
      await flushPromises();
      return wrapper;
    }

    it('re-fetches the CURRENT page with the same filters (no reset), silently and without a loading skeleton', async () => {
      const wrapper = await mountExposed('/dashboard?tab=partial&q=kopi&type=dine_in&page=2');
      const before = orders.mock.calls.length;
      const first = orders.mock.calls[0][0];

      let resolveNext;
      orders.mockImplementationOnce(() => new Promise((res) => { resolveNext = () => res(RESPONSE([ROW(143)])); }));
      wrapper.vm.refresh();
      await flushPromises();

      expect(orders.mock.calls.length).toBe(before + 1);
      expect(orders.mock.calls.at(-1)[0]).toEqual(first);
      expect(wrapper.text()).toContain('A00141'); // sem sebelumnya tetap tampil selagi menunggu
      expect(wrapper.find('[aria-busy="true"]').exists()).toBe(false);
      resolveNext();
      await flushPromises();
      expect(wrapper.text()).toContain('A00143');
      expect(router.currentRoute.value.query).toMatchObject({ tab: 'partial', q: 'kopi', type: 'dine_in', page: '2' });
      wrapper.unmount();
    });

    it('keeps an open detail while the rows are replaced', async () => {
      const wrapper = await mountExposed('/dashboard');
      await wrapper.find('button[aria-label="Buka detail order A00141"]').trigger('click');
      await flushPromises();
      expect(document.body.textContent).toContain('detail 141');

      orders.mockImplementationOnce(() => Promise.resolve(RESPONSE([ROW(150)])));
      await wrapper.vm.refresh();
      await flushPromises();
      expect(wrapper.text()).toContain('A00150');
      expect(document.body.textContent).toContain('detail 141'); // detail tidak tertutup / tidak berganti
      wrapper.unmount();
    });

    it('a failed refresh keeps the rows already on screen and shows no error', async () => {
      const wrapper = await mountExposed('/dashboard');
      orders.mockRejectedValueOnce(new Error('down'));
      await wrapper.vm.refresh();
      await flushPromises();
      expect(wrapper.text()).toContain('A00141');
      expect(wrapper.find('[role="alert"]').exists()).toBe(false);
      wrapper.unmount();
    });
  });

  it('ignores a stale response from a tab the user already left', async () => {
    let resolveAll;
    orders.mockImplementationOnce(() => Promise.resolve(RESPONSE()));
    const { user } = await renderTable();
    orders.mockImplementationOnce(() => new Promise((res) => { resolveAll = () => res(RESPONSE([ROW(900)])); }));
    await user.click(screen.getByRole('tab', { name: /Terbuka/ }));
    orders.mockImplementationOnce(() => Promise.resolve(RESPONSE([ROW(901)])));
    await user.click(screen.getByRole('tab', { name: /Lunas/ }));
    await screen.findByText('A00901');
    resolveAll();
    await new Promise((r) => setTimeout(r, 0));
    expect(screen.queryByText('A00900')).toBeNull();
    expect(screen.getByText('A00901')).toBeInTheDocument();
  });
});
