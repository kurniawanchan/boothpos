import { describe, it, expect, vi, beforeEach } from 'vitest';
import { render, screen, waitFor } from '@testing-library/vue';
import { listCustomers } from '../../resources/js/api/customers';
import CustomerFilterSelect from '../../resources/js/components/preorder/CustomerFilterSelect.vue';

vi.mock('../../resources/js/api/customers', () => ({ listCustomers: vi.fn() }));

// 066 (US1) — pelanggan datang dengan telepon/e-mail dari API; filter tidak boleh menampilkannya.
const FIRST_PAGE = [
  { id: 1, name: 'Siti Amalia', phone: '0811000111', email: 'siti@example.test' },
  { id: 2, name: 'Budi Santoso', phone: '0822000222', email: 'budi@example.test' },
];

async function openPanel() {
  const { default: userEvent } = await import('@testing-library/user-event');
  const user = userEvent.setup();
  await user.click(screen.getByRole('button'));

  return user;
}

describe('CustomerFilterSelect (066 US1)', () => {
  beforeEach(() => {
    listCustomers.mockReset();
    listCustomers.mockResolvedValue({ data: FIRST_PAGE });
  });

  it('shows the first customers as soon as it opens, without typing', async () => {
    render(CustomerFilterSelect, { props: { modelValue: [], allLabel: 'Semua pelanggan' } });

    await openPanel();

    expect(await screen.findByRole('option', { name: 'Siti Amalia' })).toBeInTheDocument();
    expect(screen.getByRole('option', { name: 'Budi Santoso' })).toBeInTheDocument();
    expect(listCustomers).toHaveBeenCalledWith({ search: '', per_page: 25 });
  });

  it('labels options with the name only — no phone or e-mail reaches the screen', async () => {
    render(CustomerFilterSelect, { props: { modelValue: [], allLabel: 'Semua pelanggan' } });

    await openPanel();
    await screen.findByRole('option', { name: 'Siti Amalia' });

    expect(document.body.textContent).not.toContain('0811000111');
    expect(document.body.textContent).not.toContain('siti@example.test');
  });

  it('searches the server once for the finished term (debounced)', async () => {
    const user = await (async () => {
      render(CustomerFilterSelect, { props: { modelValue: [], allLabel: 'Semua pelanggan' } });
      return openPanel();
    })();
    await screen.findByRole('option', { name: 'Siti Amalia' });
    listCustomers.mockClear();
    listCustomers.mockResolvedValue({ data: [FIRST_PAGE[0]] });

    await user.type(screen.getByPlaceholderText('Cari…'), 'sit');

    await waitFor(() => expect(listCustomers).toHaveBeenCalledWith({ search: 'sit', per_page: 25 }));
    // Tiga ketukan, satu permintaan: 's' dan 'si' tidak pernah dikirim.
    expect(listCustomers).toHaveBeenCalledTimes(1);
  });

  it('keeps the label of a selected customer after a search that no longer returns them', async () => {
    const emitted = [];
    const { rerender } = render(CustomerFilterSelect, {
      props: { modelValue: [], allLabel: 'Semua pelanggan', 'onUpdate:modelValue': (v) => emitted.push(v) },
    });

    const user = await openPanel();
    await user.click(await screen.findByRole('option', { name: 'Siti Amalia' }));
    expect(emitted.at(-1)).toEqual([1]);
    await rerender({ modelValue: [1] });

    // Pencarian berikutnya hanya mengembalikan Budi.
    listCustomers.mockResolvedValue({ data: [FIRST_PAGE[1]] });
    await user.type(screen.getByPlaceholderText('Cari…'), 'bud');
    await waitFor(() => expect(listCustomers).toHaveBeenCalledWith({ search: 'bud', per_page: 25 }));

    // Siti tetap dipilih dan tetap bernama, bukan placeholder.
    expect(await screen.findByRole('option', { name: 'Siti Amalia' })).toHaveAttribute('aria-selected', 'true');
    expect(screen.getAllByText('Siti Amalia').length).toBeGreaterThan(0);
  });
});
