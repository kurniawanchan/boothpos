import { describe, it, expect, vi, beforeEach } from 'vitest';
import { render, screen, waitFor } from '@testing-library/vue';
import { createPinia, setActivePinia } from 'pinia';
import PosView from '../../resources/js/views/PosView.vue';
import { useAuthStore } from '../../resources/js/stores/auth';
import { usePosCartStore } from '../../resources/js/stores/posCart';
import { useToastStore } from '../../resources/js/stores/toast';
import { currentSession } from '../../resources/js/api/sessions';
import { listCategories } from '../../resources/js/api/categories';
import { listArtists } from '../../resources/js/api/artists';
import { listProducts, lookupVariants } from '../../resources/js/api/products';

vi.mock('../../resources/js/api/sessions', () => ({ currentSession: vi.fn(), openSession: vi.fn(), closeSession: vi.fn() }));
vi.mock('../../resources/js/api/categories', () => ({ listCategories: vi.fn() }));
vi.mock('../../resources/js/api/artists', () => ({ listArtists: vi.fn() }));
vi.mock('../../resources/js/api/products', () => ({ listProducts: vi.fn(), lookupVariants: vi.fn() }));
vi.mock('../../resources/js/api/orders', () => ({ createOrder: vi.fn() }));

const HIT = (id, extra = {}) => ({
  variant_id: id, sku: `SPF-KC-MCYT-${id}`, label: `MCYT — Varian ${id}`, artist_name: 'Seller A', category_name: 'Keychain',
  sell_price: '20000.00', current_stock: 4, is_preorder: false, image_url: null, ...extra,
});
const CATALOG = { 'spf-kc-mcyt-3': HIT(3), 'spf-kc-mcyt-9': HIT(9, { current_stock: 0 }) };

function renderPos() {
  const pinia = createPinia();
  setActivePinia(pinia);
  useAuthStore().user = { id: 1, role: 'Kasir', name: 'Kasir', menu_keys: ['dashboard', 'pos', 'session'] };
  const utils = render(PosView, { global: { plugins: [pinia] } });
  return { pinia, cart: usePosCartStore(pinia), toasts: useToastStore(pinia), ...utils };
}

// 049 (US5) — pindai barcode: Enter di kotak pencarian menambahkan varian dengan SKU persis sama.
describe('PosView — scan-to-add (049)', () => {
  beforeEach(() => {
    vi.clearAllMocks();
    currentSession.mockResolvedValue(null); // tanpa sesi terbuka: keranjang tetap bisa diisi (sama seperti mengetuk kartu)
    listCategories.mockResolvedValue({ data: [] });
    listArtists.mockResolvedValue({ data: [] });
    listProducts.mockResolvedValue({ data: [] });
    lookupVariants.mockImplementation(async (q, limit, opts) => {
      if (opts?.exact) {
        const hit = CATALOG[q.trim().toLowerCase()];
        return { data: hit ? [hit] : [] };
      }
      return { data: q.toLowerCase() === 'mcyt' ? [HIT(3), HIT(4)] : [] };
    });
  });

  async function scan(text) {
    const { default: userEvent } = await import('@testing-library/user-event');
    const user = userEvent.setup();
    const box = await screen.findByPlaceholderText('Cari nama produk atau SKU…');
    await user.click(box);
    await user.type(box, `${text}{Enter}`);
    return { user, box };
  }

  it('adds exactly one unit of the variant whose SKU matches, clears the box and keeps it focused', async () => {
    const { cart } = renderPos();

    const { box } = await scan('SPF-KC-MCYT-3');

    await waitFor(() => expect(cart.items).toHaveLength(1));
    expect(cart.items[0]).toMatchObject({ variant_id: 3, sku: 'SPF-KC-MCYT-3', qty: 1, sell_price: '20000.00' });
    expect(lookupVariants).toHaveBeenCalledWith('SPF-KC-MCYT-3', 1, { exact: true });
    await waitFor(() => expect(box).toHaveValue(''));
    expect(box).toHaveFocus();
  });

  it('adds one more unit when the same code is scanned again', async () => {
    const { cart } = renderPos();
    const { user, box } = await scan('SPF-KC-MCYT-3');
    await waitFor(() => expect(cart.items[0]?.qty).toBe(1));

    await user.type(box, 'SPF-KC-MCYT-3{Enter}');

    await waitFor(() => expect(cart.items[0].qty).toBe(2));
    expect(cart.items).toHaveLength(1);
  });

  it('adds nothing and shows a clear message for an unknown code, and clears the box for the next scan', async () => {
    const { cart, toasts } = renderPos();

    const { box } = await scan('NOPE-123');

    await waitFor(() => expect(toasts.items.some((i) => /NOPE-123.*tidak ditemukan/.test(i.message))).toBe(true));
    expect(cart.items).toHaveLength(0);
    await waitFor(() => expect(box).toHaveValue(''));
  });

  it('does not add a variant that is out of stock and says so', async () => {
    const { cart, toasts } = renderPos();

    await scan('SPF-KC-MCYT-9');

    await waitFor(() => expect(toasts.items.some((i) => /stoknya habis/.test(i.message))).toBe(true));
    expect(cart.items).toHaveLength(0);
  });

  it('adds nothing for partial text that matches several variants and lists them as a normal search does', async () => {
    const { cart, toasts } = renderPos();

    const { box } = await scan('mcyt');

    expect(await screen.findByText('MCYT — Varian 3')).toBeInTheDocument();
    expect(screen.getByText('MCYT — Varian 4')).toBeInTheDocument();
    expect(cart.items).toHaveLength(0);
    expect(box).toHaveValue('mcyt'); // teks dipertahankan agar kasir bisa memilih/mengubahnya
    expect(toasts.items).toHaveLength(0);
  });

  it('ignores Enter on an empty box', async () => {
    const { cart } = renderPos();
    const { default: userEvent } = await import('@testing-library/user-event');
    const user = userEvent.setup();
    const box = await screen.findByPlaceholderText('Cari nama produk atau SKU…');

    await user.type(box, '{Enter}');

    expect(lookupVariants).not.toHaveBeenCalled();
    expect(cart.items).toHaveLength(0);
  });

  it('still adds when no cashier session is open (tap parity); payment stays gated elsewhere', async () => {
    const { cart } = renderPos();

    await scan('SPF-KC-MCYT-3');

    await waitFor(() => expect(cart.items).toHaveLength(1));
    expect(screen.getByText(/Buka sesi kasir/i)).toBeInTheDocument(); // banner tetap tampil
  });

  it('is not confused by the pending debounced search: the box stays empty and no stale results come back', async () => {
    renderPos();

    const { box } = await scan('SPF-KC-MCYT-3');
    await new Promise((resolve) => setTimeout(resolve, 450)); // lewati jeda debounce 300 ms

    expect(box).toHaveValue('');
    // Label itu hanya boleh muncul SEKALI (baris keranjang); dua kali berarti kartu hasil pencarian lama muncul kembali.
    expect(screen.queryAllByText('MCYT — Varian 3')).toHaveLength(1);
  });

  it('reports a failed lookup instead of failing silently', async () => {
    lookupVariants.mockRejectedValueOnce(new Error('Server error'));
    const { toasts, cart } = renderPos();

    await scan('SPF-KC-MCYT-3');

    await waitFor(() => expect(toasts.items.some((i) => /Server error/.test(i.message))).toBe(true));
    expect(cart.items).toHaveLength(0);
  });

  // --- Regresi dari verifikasi browser sungguhan: jaringan lebih lambat dari debounce 300 ms ---------------------

  describe('when the lookup is slower than the 300 ms search debounce', () => {
    const sleep = (ms) => new Promise((resolve) => setTimeout(resolve, ms));

    beforeEach(() => {
      lookupVariants.mockImplementation(async (q, limit, opts) => {
        await sleep(450); // lebih lambat dari debounce: pencarian biasa akan menyala SEBELUM jawaban kembali
        if (opts?.exact) {
          const hit = CATALOG[q.trim().toLowerCase()];
          return { data: hit ? [hit] : [] };
        }
        return { data: q.toLowerCase() === 'mcyt' ? [HIT(3), HIT(4)] : [] };
      });
    });

    it('still adds the scanned item (the pending debounced search must not cancel the scan)', async () => {
      const { cart } = renderPos();

      const { box } = await scan('SPF-KC-MCYT-3');

      await waitFor(() => expect(cart.items).toHaveLength(1), { timeout: 3000 });
      expect(cart.items[0]).toMatchObject({ variant_id: 3, qty: 1 });
      expect(box).toHaveValue('');
    });

    it('still reports an unknown code', async () => {
      const { cart, toasts } = renderPos();

      await scan('NOPE-123');

      await waitFor(() => expect(toasts.items.some((i) => /NOPE-123.*tidak ditemukan/.test(i.message))).toBe(true), { timeout: 3000 });
      expect(cart.items).toHaveLength(0);
    });

    it('keeps BOTH items when two scans arrive back to back while the first lookup is still in flight', async () => {
      const { cart } = renderPos();
      const { default: userEvent } = await import('@testing-library/user-event');
      const user = userEvent.setup();
      const box = await screen.findByPlaceholderText('Cari nama produk atau SKU…');
      await user.click(box);

      await user.type(box, 'SPF-KC-MCYT-3{Enter}SPF-KC-MCYT-3{Enter}'); // pemindai cepat: dua kode tanpa jeda

      await waitFor(() => expect(cart.items[0]?.qty).toBe(2), { timeout: 3000 });
      expect(cart.items).toHaveLength(1);
      expect(box).toHaveValue('');
    });

    it('does not glue the next scan onto the previous code', async () => {
      const { cart } = renderPos();
      const { default: userEvent } = await import('@testing-library/user-event');
      const user = userEvent.setup();
      const box = await screen.findByPlaceholderText('Cari nama produk atau SKU…');
      await user.click(box);

      await user.type(box, 'SPF-KC-MCYT-3{Enter}NOPE-1{Enter}');

      await waitFor(() => expect(lookupVariants).toHaveBeenCalledWith('NOPE-1', 1, { exact: true }), { timeout: 3000 }); // bukan "SPF-KC-MCYT-3NOPE-1"
      await waitFor(() => expect(cart.items).toHaveLength(1), { timeout: 3000 });
    });

    it('restores partial text and its results only if the cashier has not typed anything since', async () => {
      renderPos();
      const { box, user } = await scan('mcyt');

      expect(await screen.findByText('MCYT — Varian 3', {}, { timeout: 3000 })).toBeInTheDocument();
      expect(box).toHaveValue('mcyt');

      await user.clear(box);
      await user.type(box, 'mcyt{Enter}x'); // mengetik lagi tepat setelah Enter, sebelum jawaban kembali
      await sleep(900);

      expect(box).toHaveValue('x'); // jawaban lambat tidak menimpa ketikan baru
    });
  });
});

