import { describe, it, expect, vi, beforeEach } from 'vitest';
import { render, screen, waitFor } from '@testing-library/vue';
import { createPinia, setActivePinia } from 'pinia';
import PillFilterRow from '../../resources/js/components/pos/PillFilterRow.vue';
import PosView from '../../resources/js/views/PosView.vue';
import { useAuthStore } from '../../resources/js/stores/auth';
import { currentSession } from '../../resources/js/api/sessions';
import { listCategories } from '../../resources/js/api/categories';
import { listArtists } from '../../resources/js/api/artists';
import { listProducts, lookupVariants } from '../../resources/js/api/products';

vi.mock('../../resources/js/api/sessions', () => ({ currentSession: vi.fn(), openSession: vi.fn(), closeSession: vi.fn() }));
vi.mock('../../resources/js/api/categories', () => ({ listCategories: vi.fn() }));
vi.mock('../../resources/js/api/artists', () => ({ listArtists: vi.fn() }));
vi.mock('../../resources/js/api/products', () => ({ listProducts: vi.fn(), lookupVariants: vi.fn() }));
vi.mock('../../resources/js/api/orders', () => ({ createOrder: vi.fn() }));

const OPTIONS = [{ value: 1, label: 'vlaisca' }, { value: 2, label: 'Nekoyama' }, { value: 3, label: 'Hoshizora' }];

// 054 (US3/US4) — baris pil filter.
describe('PillFilterRow', () => {
  const props = (over = {}) => ({ modelValue: [], options: OPTIONS, allLabel: 'Semua penjual', ...over });

  it('renders the All pill first and selected when nothing is chosen', () => {
    render(PillFilterRow, { props: props() });
    const pills = screen.getAllByRole('button');
    expect(pills.map((b) => b.textContent.trim())).toEqual(['Semua penjual', 'vlaisca', 'Nekoyama', 'Hoshizora']);
    expect(pills[0]).toHaveAttribute('aria-pressed', 'true');
    expect(pills[1]).toHaveAttribute('aria-pressed', 'false');
  });

  it('toggles an option in and out of the selection (multi-select)', async () => {
    const { default: userEvent } = await import('@testing-library/user-event');
    const user = userEvent.setup();
    const { emitted, rerender } = render(PillFilterRow, { props: props() });

    await user.click(screen.getByRole('button', { name: 'vlaisca' }));
    expect(emitted()['update:modelValue'].at(-1)).toEqual([[1]]);

    await rerender(props({ modelValue: [1] }));
    await user.click(screen.getByRole('button', { name: 'Nekoyama' }));
    expect(emitted()['update:modelValue'].at(-1)).toEqual([[1, 2]]);

    await rerender(props({ modelValue: [1, 2] }));
    await user.click(screen.getByRole('button', { name: 'vlaisca' }));
    expect(emitted()['update:modelValue'].at(-1)).toEqual([[2]]);
  });

  it('clears the selection when the All pill is tapped, and marks the chosen pills', async () => {
    const { default: userEvent } = await import('@testing-library/user-event');
    const user = userEvent.setup();
    const { emitted } = render(PillFilterRow, { props: props({ modelValue: [2] }) });

    expect(screen.getByRole('button', { name: 'Nekoyama' })).toHaveAttribute('aria-pressed', 'true');
    expect(screen.getByRole('button', { name: 'Semua penjual' })).toHaveAttribute('aria-pressed', 'false');

    await user.click(screen.getByRole('button', { name: 'Semua penjual' }));
    expect(emitted()['update:modelValue'].at(-1)).toEqual([[]]);
  });

  it('drops ids that no longer exist in the options', async () => {
    const { emitted } = render(PillFilterRow, { props: props({ modelValue: [2, 99] }) });
    await waitFor(() => expect(emitted()['update:modelValue']?.at(-1)).toEqual([[2]]));
  });

  it('is one horizontally scrollable, non-wrapping group', () => {
    render(PillFilterRow, { props: props(), attrs: { 'aria-label': 'Penjual' } });
    const group = screen.getByRole('group', { name: 'Penjual' });
    expect(group.className).toMatch(/overflow-x-auto/);
    expect(group.className).toMatch(/flex-nowrap/);
  });
});

describe('PosView — seller and category pills (054)', () => {
  beforeEach(() => {
    vi.clearAllMocks();
    currentSession.mockResolvedValue(null);
    listCategories.mockResolvedValue({ data: [{ id: 5, name: 'Pins', code: 'PI' }, { id: 6, name: 'Stiker', code: 'ST' }] });
    listArtists.mockResolvedValue({ data: [{ id: 1, name: 'vlaisca' }, { id: 2, name: 'Nekoyama' }] });
    listProducts.mockResolvedValue({ data: [] });
    lookupVariants.mockResolvedValue({ data: [] });
  });

  function renderPos() {
    const pinia = createPinia();
    setActivePinia(pinia);
    useAuthStore().user = { id: 1, role: 'Kasir', name: 'Kasir', menu_keys: ['dashboard', 'pos', 'session'] };
    return render(PosView, { global: { plugins: [pinia] } });
  }

  it('puts the seller pills on the search row and the category pills on a row below it', async () => {
    renderPos();
    const search = await screen.findByPlaceholderText('Cari nama produk atau SKU…');
    const sellers = await screen.findByRole('group', { name: 'Penjual' });
    const categories = await screen.findByRole('group', { name: 'Kategori' });

    expect(search.closest('[data-testid="search-row"]')).toBe(sellers.closest('[data-testid="search-row"]'));
    expect(categories.closest('[data-testid="search-row"]')).toBeNull();
    expect(search.compareDocumentPosition(categories) & Node.DOCUMENT_POSITION_FOLLOWING).toBeTruthy();
  });

  it('combines a seller and a category into one product request', async () => {
    const { default: userEvent } = await import('@testing-library/user-event');
    const user = userEvent.setup();
    renderPos();

    await user.click(await screen.findByRole('button', { name: 'vlaisca' }));
    await user.click(await screen.findByRole('button', { name: 'Pins' }));

    await waitFor(() => expect(listProducts).toHaveBeenLastCalledWith(expect.objectContaining({ artist_id: [1], category_id: [5] })));

    await user.click(screen.getByRole('button', { name: 'Semua penjual' }));
    await waitFor(() => expect(listProducts).toHaveBeenLastCalledWith(expect.not.objectContaining({ artist_id: expect.anything() })));
  });
});
