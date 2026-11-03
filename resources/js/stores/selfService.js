import { defineStore } from 'pinia';
import { computed, ref, watch } from 'vue';
import { getContext, getMenu, submitOrder, uploadProof } from '../api/selfService';
import { addToCart, buildOrderPayload, buildSelfServiceCards, cartTotals, parseDraft, removeLine, serializeDraft, setLineQty } from '../utils/selfServiceCart';
import { prepareProofImage } from '../utils/imageDownscale';
import { customerPayload, emptyCustomer, validateCustomer } from '../utils/selfServiceCustomer';

// 090 — satu-satunya penyimpan keadaan halaman pesan mandiri pelanggan. Hanya `localStorage` untuk draf keranjang (FR-013);
// semuanya dibungkus try/catch karena akses bisa gagal (mode privat, data situs diblokir) dan halaman harus tetap jalan.

const DRAFT_PREFIX = 'ss-draft:';
export const LAST_RECEIPT_KEY = 'ss-last-receipt';
// Token QR meja terakhir dibuka: dipakai halaman struk untuk tombol "Pesan lagi" ke meja yang sama.
export const LAST_TABLE_KEY = 'ss-last-table';

const safeGet = (key) => {
  try {
    return localStorage.getItem(key);
  } catch {
    return null;
  }
};
const safeSet = (key, value) => {
  try {
    localStorage.setItem(key, value);
  } catch {
    /* draf tidak tersimpan; halaman tetap berfungsi */
  }
};
const safeRemove = (key) => {
  try {
    localStorage.removeItem(key);
  } catch {
    /* sama */
  }
};

/**
 * UUID v4 untuk `local_ref`. `crypto.randomUUID` hanya ada di konteks aman (HTTPS/localhost), padahal QR meja biasanya
 * membuka `http://<IP-LAN>` — jadi dipakai `getRandomValues`, yang tersedia di semua konteks.
 */
export function newLocalRef() {
  const b = new Uint8Array(16);
  crypto.getRandomValues(b);
  b[6] = (b[6] & 0x0f) | 0x40;
  b[8] = (b[8] & 0x3f) | 0x80;
  const h = Array.from(b, (x) => x.toString(16).padStart(2, '0')).join('');
  return `${h.slice(0, 8)}-${h.slice(8, 12)}-${h.slice(12, 16)}-${h.slice(16, 20)}-${h.slice(20)}`;
}

/** Galat yang menghentikan halaman (QR/meja/koneksi), dibedakan agar pesannya tepat. */
function fatalFrom(error) {
  if (error.status === 404 && error.code === 'qr_invalid') return { kind: 'invalid', message: error.message };
  if (error.status === 409 && error.code === 'table_unavailable') return { kind: 'table', message: error.message };
  if (error.status === 423) return { kind: 'locked', message: error.message };
  if (error.status === null) return { kind: 'network', message: error.message };
  return { kind: 'error', message: error.message };
}

export const useSelfServiceStore = defineStore('selfService', () => {
  const token = ref('');
  const status = ref('idle'); // idle | loading | ready | error
  const fatal = ref(null); // { kind, message }
  const context = ref(null);
  const categories = ref([]);
  const cards = ref([]);
  const menuLoading = ref(false);
  const query = ref('');
  const categoryId = ref(null);

  const lines = ref([]);
  const serviceType = ref('dine_in');
  const localRef = ref(newLocalRef());

  // Bukti bayar: berkas hanya di memori (tidak ikut draf). `token` terisi setelah unggah berhasil dan dipakai ulang
  // bila kirim gagal karena item tidak tersedia, supaya foto tidak diunggah dua kali.
  const proof = ref({ file: null, previewUrl: null, via: null, token: null });
  const proofBusy = ref(false);
  const proofError = ref('');

  // Data diri: hanya di memori (privasi) — tidak ikut draf localStorage.
  const customer = ref(emptyCustomer());
  const customerErrors = ref({});

  const submitting = ref(false);
  const submitError = ref('');
  const unavailable = ref([]); // [{ variant_id, reason }]

  let menuSeq = 0;

  const taxRate = computed(() => (context.value?.tax?.show ? Number(context.value.tax.rate) || 0 : 0));
  const totals = computed(() => cartTotals(lines.value, taxRate.value));
  const available = computed(() => context.value?.available === true);

  function loadDraft() {
    const draft = parseDraft(safeGet(DRAFT_PREFIX + token.value) ?? '');
    if (draft) {
      lines.value = draft.lines;
      serviceType.value = draft.serviceType;
      localRef.value = draft.localRef;
    }
  }

  function saveDraft() {
    if (!token.value) return;
    if (lines.value.length === 0) {
      safeRemove(DRAFT_PREFIX + token.value);
      return;
    }
    safeSet(DRAFT_PREFIX + token.value, serializeDraft({ lines: lines.value, serviceType: serviceType.value, localRef: localRef.value }));
  }

  watch([lines, serviceType, localRef], saveDraft, { deep: true });

  async function init(newToken) {
    token.value = newToken;
    safeSet(LAST_TABLE_KEY, newToken);
    status.value = 'loading';
    fatal.value = null;
    lines.value = [];
    serviceType.value = 'dine_in';
    localRef.value = newLocalRef();
    customer.value = emptyCustomer();
    customerErrors.value = {};
    clearProof();
    loadDraft();

    try {
      context.value = await getContext(newToken);
      await loadMenu();
      status.value = 'ready';
    } catch (e) {
      fatal.value = fatalFrom(e);
      status.value = 'error';
    }
  }

  async function loadMenu() {
    const seq = ++menuSeq;
    menuLoading.value = true;
    try {
      const params = {};
      if (query.value.trim()) params.q = query.value.trim();
      if (categoryId.value) params.category_id = categoryId.value;
      const data = await getMenu(token.value, params);
      if (seq !== menuSeq) return; // jawaban lama dari pencarian sebelumnya
      // Server menghitung kategori dari menu TANPA filter pencarian/kategori, jadi chip tidak hilang saat memfilter.
      categories.value = data.categories;
      cards.value = buildSelfServiceCards(data.products);
    } finally {
      if (seq === menuSeq) menuLoading.value = false;
    }
  }

  function addLine(line) {
    lines.value = addToCart(lines.value, line);
    unavailable.value = unavailable.value.filter((u) => u.variant_id !== line.variant_id);
  }
  const changeQty = (key, qty) => (lines.value = setLineQty(lines.value, key, qty));
  const dropLine = (key) => (lines.value = removeLine(lines.value, key));

  function clearProof() {
    if (proof.value.previewUrl) URL.revokeObjectURL(proof.value.previewUrl);
    proof.value = { file: null, previewUrl: null, via: null, token: null };
    proofError.value = '';
  }

  /** Foto dari kamera (`webcam`) atau galeri (`upload`); dikecilkan bila perlu. Gagal → pesan, tanpa mengganti foto lama. */
  async function attachProof(file, via, messages) {
    proofBusy.value = true;
    proofError.value = '';
    try {
      const ready = await prepareProofImage(file);
      if (proof.value.previewUrl) URL.revokeObjectURL(proof.value.previewUrl);
      proof.value = { file: ready, previewUrl: URL.createObjectURL(ready), via, token: null };
    } catch {
      proofError.value = messages?.unsupported ?? 'Foto tidak dapat diproses.';
    } finally {
      proofBusy.value = false;
    }
  }

  /** @returns {Promise<object|null>} data pesanan bila berhasil, null bila gagal (galat ada di `submitError`/`unavailable`). */
  async function submit(messages) {
    submitError.value = '';
    unavailable.value = [];
    customerErrors.value = {};
    if (!lines.value.length) return null;
    // Validasi cepat di klien; server tetap memvalidasi ulang.
    customerErrors.value = validateCustomer(customer.value);
    if (Object.keys(customerErrors.value).length) return null;
    if (!proof.value.file && !proof.value.token) {
      submitError.value = messages?.proofRequired ?? 'Lampirkan foto bukti pembayaran.';
      return null;
    }

    submitting.value = true;
    try {
      if (!proof.value.token) {
        const uploaded = await uploadProof(token.value, proof.value.file, proof.value.via);
        proof.value = { ...proof.value, token: uploaded.proof_token };
      }
      const order = await submitOrder(token.value, buildOrderPayload({
        lines: lines.value, serviceType: serviceType.value, proofToken: proof.value.token, localRef: localRef.value, customer: customerPayload(customer.value),
      }));

      safeRemove(DRAFT_PREFIX + token.value);
      safeSet(LAST_RECEIPT_KEY, order.receipt_token);
      lines.value = [];
      localRef.value = newLocalRef();
      customer.value = emptyCustomer(); // data pribadi tidak dipertahankan setelah terkirim
      clearProof();

      return order;
    } catch (e) {
      if (e.status === 409 && e.code === 'item_unavailable') {
        unavailable.value = e.data?.items ?? [];
      } else if (e.status === 422 && Object.keys(e.errors ?? {}).some((k) => k.startsWith('customer.'))) {
        // Server menolak data diri: tandai kolomnya (kunci `customer.phone` → phone).
        customerErrors.value = Object.fromEntries(Object.keys(e.errors).filter((k) => k.startsWith('customer.')).map((k) => [k.slice('customer.'.length), k.slice('customer.'.length)]));
      } else if (e.status === 422 && e.errors?.proof_token) {
        proof.value = { ...proof.value, token: null }; // unggah ulang pada percobaan berikut
      } else if (e.status === 409 && ['table_unavailable', 'qris_unavailable'].includes(e.code)) {
        fatal.value = fatalFrom(e);
        status.value = 'error';
      }
      submitError.value = e.errors?.proof_token?.[0] ?? e.message;
      return null;
    } finally {
      submitting.value = false;
    }
  }

  return {
    token, status, fatal, context, categories, cards, menuLoading, query, categoryId,
    lines, serviceType, localRef, proof, proofBusy, proofError, submitting, submitError, unavailable, customer, customerErrors,
    taxRate, totals, available,
    init, loadMenu, addLine, changeQty, dropLine, attachProof, clearProof, submit,
  };
});
