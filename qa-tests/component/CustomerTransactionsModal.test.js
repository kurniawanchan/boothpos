import { describe, it, expect, vi, beforeEach } from 'vitest';
import { mount, flushPromises } from '@vue/test-utils';
import { createPinia, setActivePinia } from 'pinia';
import { createRouter, createMemoryHistory } from 'vue-router';
import CustomerTransactionsModal from '../../resources/js/components/customer/CustomerTransactionsModal.vue';
import TransactionItemsModal from '../../resources/js/components/sales/TransactionItemsModal.vue';
import { customerTransactions } from '../../resources/js/api/customers';

vi.mock('../../resources/js/api/customers', () => ({ customerTransactions: vi.fn() }));
vi.mock('../../resources/js/api/orders', () => ({ getOrder: vi.fn().mockResolvedValue(null), getReceipt: vi.fn(), voidOrder: vi.fn() }));
vi.mock('../../resources/js/api/products', () => ({ getProduct: vi.fn() }));

/**
 * Modal detail transaksi dipakai ulang di sini. Sejak pemilik bisa MEMBATALKAN transaksi
 * dari modal itu, daftar riwayat pelanggan di belakangnya harus ikut dimuat ulang —
 * kalau tidak, transaksi yang baru dibatalkan tetap tampil seolah masih sah.
 */
describe('CustomerTransactionsModal', () => {
  beforeEach(() => {
    vi.clearAllMocks();
    customerTransactions.mockResolvedValue({ data: [] });
  });

  async function mountModal() {
    const pinia = createPinia();
    setActivePinia(pinia);
    const router = createRouter({ history: createMemoryHistory(), routes: [{ path: '/', component: { template: '<div />' } }] });
    const wrapper = mount(CustomerTransactionsModal, { props: { open: true, customerId: 5 }, global: { plugins: [pinia, router] } });
    await flushPromises();

    return wrapper;
  }

  it('loads the customer history when opened', async () => {
    await mountModal();

    expect(customerTransactions).toHaveBeenCalledTimes(1);
    expect(customerTransactions).toHaveBeenCalledWith(5);
  });

  it('reloads the history after a transaction is voided from the detail modal', async () => {
    const wrapper = await mountModal();

    wrapper.findComponent(TransactionItemsModal).vm.$emit('changed');
    await flushPromises();

    expect(customerTransactions).toHaveBeenCalledTimes(2);
  });
});
