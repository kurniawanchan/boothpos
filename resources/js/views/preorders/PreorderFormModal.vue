<script setup>
import { reactive, ref, computed, onMounted, watch } from 'vue';
import { useI18n } from 'vue-i18n';
import { getPreorder, createPreorder, updatePreorder } from '../../api/preorders';
import { lookupVariants } from '../../api/products';
import { listEvents } from '../../api/events';
import { useToastStore } from '../../stores/toast';
import { useDebouncedFn } from '../../composables/useDebouncedFn';
import { formatIDR, parseMoney, toMoneyString } from '../../utils/money';
import { formatDate } from '../../utils/date';
import BaseModal from '../../components/ui/BaseModal.vue';
import BaseButton from '../../components/ui/BaseButton.vue';
import BaseInput from '../../components/ui/BaseInput.vue';
import BaseSelect from '../../components/ui/BaseSelect.vue';
import BaseTextarea from '../../components/ui/BaseTextarea.vue';
import ImageLightbox from '../../components/ui/ImageLightbox.vue';
import CustomerSearchDropdown from '../../components/preorder/CustomerSearchDropdown.vue';
import { COURIER_DEFAULT, courierSelectOptions } from './preorderMeta';

/**
 * Form "Pre-order baru" / "Ubah pre-order" — dipecah dari PreordersView.vue (041) tanpa perubahan perilaku.
 * Satu form untuk dua mode (022 US1): mode ubah diisi ulang dari data yang ada lewat openEdit() dan disubmit
 * lewat updatePreorder(). Pemanggil membuka lewat ref (`openCreate()` / `openEdit(row)`) dan mendengarkan
 * `saved` untuk memuat ulang daftar serta menyegarkan panel detail bila pre-order yang diubah sedang terbuka.
 */
const emit = defineEmits(['saved']);

const toast = useToastStore();
const { t } = useI18n();

// 021-preorder-form-updates (US3, research.md Decision 9) — form New
// Preorder TIDAK PERNAH punya pemilih event sebelum fitur ini; ditambah
// di sini karena hari-jemput (di bawah) butuh event untuk menurunkan
// pilihan tanggalnya.
const events = ref([]);
onMounted(async () => {
  events.value = (await listEvents({ per_page: 100 })).data;
});
const eventOptions = computed(() => events.value.map((e) => ({ value: e.id, label: e.name })));

// --- Create form (no mockup reference — designed fresh) ----------------
const showCreate = ref(false);
// 022-preorder-invoice-crud-overhaul (US1) — null berarti mode "buat
// baru"; berisi id berarti form yang SAMA persis sedang dipakai untuk
// mengedit preorder tsb (submitCreate() bercabang berdasar ini).
const editingPreorderId = ref(null);
const createCustomer = ref(null);
const createFulfillment = ref('pickup');
const createShippingCost = ref('0');
const createDiscount = ref('0');
const createEventId = ref('');
const createPickupDay = ref('');
const createCourierName = ref(COURIER_DEFAULT);
const createExpectedDate = ref('');
const createNotes = ref('');
const createItems = ref([]);
const createSearch = ref('');
const createResults = ref([]);
const creating = ref(false);
const createErrors = reactive({});

// 024-invoice-layout-shipping-slip — "Add item" sekarang berperilaku sama
// dengan CustomerSearchDropdown.vue: klik/fokus lapangan langsung
// menampilkan daftar produk yang bisa dijelajahi (lookupVariants('') kini
// mengembalikan halaman default, bukan kosong), lalu mengetik menyaring.
const runCreateSearch = useDebouncedFn(async () => {
  createResults.value = (await lookupVariants(createSearch.value.trim(), 8)).data;
}, 300);
// `setTimeout` bukan bagian dari daftar global yang diizinkan compiler
// template Vue (beda dari Math/Date/dst.) — dipanggil dari method di sini,
// bukan langsung sebagai ekspresi inline di template.
function closeCreateResultsSoon() {
  setTimeout(() => { createResults.value = []; }, 150);
}

function openCreate() {
  editingPreorderId.value = null;
  createCustomer.value = null;
  createFulfillment.value = 'pickup';
  createShippingCost.value = '0';
  createDiscount.value = '0';
  createEventId.value = '';
  createPickupDay.value = '';
  createCourierName.value = COURIER_DEFAULT;
  createExpectedDate.value = '';
  createNotes.value = '';
  createItems.value = [];
  createSearch.value = '';
  createResults.value = [];
  Object.keys(createErrors).forEach((k) => delete createErrors[k]);
  showCreate.value = true;
}

// 022-preorder-invoice-crud-overhaul (US1, FR-001) — form YANG SAMA
// dengan "buat baru" di atas, hanya diisi ulang dari data preorder yang
// sudah ada dan disubmit lewat updatePreorder() (lihat submitCreate()).
// Tidak ada form terpisah untuk edit — item/diskon/fulfillment/dst semua
// field yang sama persis yang sudah ada di form ini.
async function openEdit(row) {
  const full = await getPreorder(row.id);
  editingPreorderId.value = full.id;
  createCustomer.value = full.customer ?? { id: full.customer_id ?? null, name: row.customer_name };
  createFulfillment.value = full.fulfillment;
  createShippingCost.value = String(full.shipping_cost ?? '0');
  createDiscount.value = String(full.discount ?? '0');
  createEventId.value = full.event_id ?? '';
  createPickupDay.value = full.pickup_day ?? '';
  createCourierName.value = full.courier_name ?? COURIER_DEFAULT;
  createExpectedDate.value = full.expected_date ?? '';
  createNotes.value = full.notes ?? '';
  createItems.value = (full.items ?? []).map((i) => ({
    // `id` marks this as a genuinely EXISTING preorder_items row — the
    // backend preserves its locked-in price snapshot when it sees this id
    // come back unchanged. Deleting the row and re-adding the same variant
    // via "Add item" (below) produces a row with NO id, so the backend
    // re-snapshots the variant's CURRENT price instead — this is the fix
    // for a stale/wrong line price that couldn't otherwise be refreshed.
    id: i.id, variant_id: i.variant_id, sku: i.sku_snapshot, label: i.name_snapshot, sell_price: i.sell_price, qty: i.qty, image_url: i.image_url ?? null, category_name: i.category_name ?? null,
  }));
  createSearch.value = '';
  createResults.value = [];
  Object.keys(createErrors).forEach((k) => delete createErrors[k]);
  showCreate.value = true;
}

function addCreateItem(variant) {
  const existing = createItems.value.find((i) => i.variant_id === variant.variant_id);
  if (existing) existing.qty += 1;
  // No `id` here on purpose — this is what tells the backend "re-snapshot
  // this variant's current price", even if it's the same variant as a row
  // just deleted from this same form (see the mapping above).
  else createItems.value.push({ id: null, variant_id: variant.variant_id, sku: variant.sku, label: variant.label, sell_price: variant.sell_price, qty: 1, image_url: variant.image_url ?? null, category_name: variant.category_name ?? null });
  createSearch.value = '';
  createResults.value = [];
}

function bumpCreateItem(item, step) {
  item.qty = Math.max(1, item.qty + step);
}

// 021-preorder-form-updates (US1, FR-006) — entri langsung sebagai
// pelengkap stepper +/- yang sudah ada, bukan penggantinya. Minimum 1
// tetap dijaga di sini (bukan cuma di backend) supaya UI tidak sempat
// menampilkan 0/negatif sebelum request dikirim.
function setCreateItemQty(item, rawValue) {
  const parsed = parseInt(rawValue, 10);
  item.qty = Number.isFinite(parsed) && parsed >= 1 ? parsed : 1;
}

function removeCreateItem(idx) {
  createItems.value.splice(idx, 1);
}

// 021-preorder-form-updates (US3, research.md Decision 2) — pilihan hari
// jemput diturunkan dari rentang tanggal ASLI event yang dipilih, bukan
// label generik "Day 1"/"Day 2" tetap. Tidak ada apa pun untuk dipilih
// kalau belum ada event terpilih (FR-008a).
const createSelectedEvent = computed(() => events.value.find((e) => e.id === createEventId.value) ?? null);
const createPickupDayOptions = computed(() => {
  const event = createSelectedEvent.value;
  if (!event) return [];
  const options = [];
  let cursor = new Date(event.start_date);
  const end = new Date(event.end_date);
  let dayNumber = 1;
  while (cursor <= end) {
    const iso = cursor.toISOString().slice(0, 10);
    options.push({ value: iso, label: `${t('preorders.pickup_day_option', { day: dayNumber })} (${formatDate(iso)})` });
    cursor.setDate(cursor.getDate() + 1);
    dayNumber++;
  }
  return options;
});

// FR-011 — beralih fulfillment membersihkan field yang tidak lagi
// relevan, dan mengganti event/hilangnya event membersihkan hari jemput
// yang sudah tidak valid (FR-009a versi form; versi backend untuk
// preorder yang SUDAH tersimpan ada di T026/EventController).
watch(createFulfillment, (mode) => {
  if (mode === 'pickup') createCourierName.value = COURIER_DEFAULT;
  else createPickupDay.value = '';
});
watch(createEventId, () => {
  if (!createPickupDayOptions.value.some((o) => o.value === createPickupDay.value)) {
    createPickupDay.value = '';
  }
});

const createSubtotal = computed(() => createItems.value.reduce((sum, i) => sum + parseMoney(i.sell_price) * i.qty, 0));
// 021-preorder-form-updates (US2) — diskon dikurangkan di sisi tampilan
// juga, murni untuk pratinjau langsung; server tetap yang menghitung
// ulang dan memvalidasi nilai final (Constitution IV).
const createTotal = computed(() => Math.max(0, createSubtotal.value + (createFulfillment.value === 'courier' ? Number(createShippingCost.value) || 0 : 0) - (Number(createDiscount.value) || 0)));
const canSubmitCreate = computed(() => !!createCustomer.value && createItems.value.length > 0);

async function submitCreate() {
  if (!canSubmitCreate.value) return;
  creating.value = true;
  Object.keys(createErrors).forEach((k) => delete createErrors[k]);
  const payload = {
    customer_id: createCustomer.value.id,
    event_id: createEventId.value || null,
    fulfillment: createFulfillment.value,
    shipping_cost: createFulfillment.value === 'courier' ? toMoneyString(createShippingCost.value) : undefined,
    discount: toMoneyString(createDiscount.value),
    pickup_day: createFulfillment.value === 'pickup' ? (createPickupDay.value || null) : null,
    courier_name: createFulfillment.value === 'courier' ? createCourierName.value : null,
    expected_date: createExpectedDate.value || null,
    notes: createNotes.value || null,
    items: createItems.value.map((i) => ({ id: i.id ?? null, variant_id: i.variant_id, qty: i.qty })),
  };
  let edited = null;
  try {
    if (editingPreorderId.value) {
      await updatePreorder(editingPreorderId.value, payload);
      toast.success(t('preorders.preorder_updated'));
      // Pemanggil menyegarkan panel detail bila pre-order ini sedang terbuka di sana.
      edited = { id: editingPreorderId.value, customer_name: createCustomer.value.name, fulfillment: payload.fulfillment };
    } else {
      await createPreorder(payload);
      toast.success(t('preorders.preorder_created'));
    }
    showCreate.value = false;
    emit('saved', edited);
  } catch (err) {
    if (err.isValidation) Object.assign(createErrors, Object.fromEntries(Object.entries(err.errors).map(([k, v]) => [k, v[0]])));
  } finally {
    creating.value = false;
  }
}

// Gambar item di baris yang sudah ditambahkan dibuka di lightbox sendiri.
const itemLightboxSrc = ref(null);
const itemLightboxAlt = ref('');
function openItemLightbox(item) {
  if (!item.image_url) return;
  itemLightboxSrc.value = item.image_url;
  itemLightboxAlt.value = item.label ?? item.name_snapshot;
}

defineExpose({ openCreate, openEdit });
</script>

<template>
  <!-- Form pre-order — tanpa referensi mockup, dirancang sendiri -->
  <BaseModal :open="showCreate" :title="editingPreorderId ? t('preorders.edit_preorder') : t('preorders.new_preorder')" max-width-class="max-w-[560px]" @close="showCreate = false">
    <div class="flex flex-col gap-4 px-6 py-5">
      <CustomerSearchDropdown v-model="createCustomer" />
      <p v-if="createErrors.customer_id" class="text-[12px] font-medium text-danger-text">{{ createErrors.customer_id }}</p>

      <!-- 021-preorder-form-updates (US3, research.md Decision 9) — form
           ini SEBELUMNYA tidak punya pemilih event sama sekali; hari
           jemput di bawah butuh event untuk menurunkan pilihan
           tanggalnya. -->
      <BaseSelect
        v-model="createEventId"
        :label="t('preorders.event_optional')"
        :options="eventOptions"
        :placeholder="t('preorders.no_event_selected')"
      />

      <div class="grid grid-cols-2 gap-2.5">
        <button
          type="button"
          class="rounded-lg border px-3.5 py-3 text-[13px] font-bold transition-colors"
          :class="createFulfillment === 'pickup' ? 'border-brand bg-mint-50 text-brand-active' : 'border-line text-muted-5'"
          @click="createFulfillment = 'pickup'"
        >
          {{ t('preorders.fulfillment_pickup') }}
        </button>
        <button
          type="button"
          class="rounded-lg border px-3.5 py-3 text-[13px] font-bold transition-colors"
          :class="createFulfillment === 'courier' ? 'border-brand bg-mint-50 text-brand-active' : 'border-line text-muted-5'"
          @click="createFulfillment = 'courier'"
        >
          {{ t('preorders.fulfillment_mail_order') }}
        </button>
      </div>

      <!-- 021-preorder-form-updates (US3) — hari jemput hanya muncul kalau
           fulfillment=pickup DAN event sudah dipilih (FR-008/FR-008a);
           tidak ada picker generik "Day 1/Day 2" tanpa event. -->
      <BaseSelect
        v-if="createFulfillment === 'pickup' && createSelectedEvent"
        v-model="createPickupDay"
        :label="t('preorders.pickup_day_label')"
        :options="createPickupDayOptions"
        :placeholder="t('preorders.select_pickup_day')"
      />
      <BaseInput v-if="createFulfillment === 'courier'" v-model="createShippingCost" type="number" min="0" :label="t('preorders.shipping_cost_rp')" />
      <!-- 021-preorder-form-updates (US3) — dropdown kurir, default JNE,
           hanya muncul untuk fulfillment=courier ("Mail Order"). -->
      <BaseSelect
        v-if="createFulfillment === 'courier'"
        v-model="createCourierName"
        :label="t('preorders.courier')"
        :options="courierSelectOptions"
      />
      <BaseInput v-model="createDiscount" type="number" min="0" :label="t('preorders.discount_rp')" :error="createErrors.discount" />
      <BaseInput v-model="createExpectedDate" type="date" :label="t('preorders.eta_optional')" />

      <!-- Requested: a clear section label above the item rows, separate
           from the "Add item" search field below it. -->
      <span class="text-[13px] font-bold text-muted-5">{{ t('preorders.item_list_section') }}</span>
      <div v-for="(item, idx) in createItems" :key="item.variant_id" class="flex items-center gap-3 rounded-lg border border-line-3 bg-surface-subtle p-3">
        <button
          v-if="item.image_url"
          type="button"
          class="h-10 w-10 flex-none cursor-zoom-in"
          :aria-label="t('master_data.enlarge_variant_image', { name: item.label })"
          @click="openItemLightbox(item)"
        >
          <img :src="item.image_url" :alt="item.label" class="h-10 w-10 rounded-md border border-line-2 object-cover" />
        </button>
        <!-- Requested: always show a thumbnail slot, not just when a real
             image exists — matches the "Add item" dropdown's own
             image-or-placeholder convention just below. -->
        <div v-else class="flex h-10 w-10 flex-none items-center justify-center rounded-md border border-line-2 bg-white text-muted-3">
          <i class="ph-duotone ph-image text-[16px]" aria-hidden="true"></i>
        </div>
        <div class="flex min-w-0 flex-1 flex-col gap-0.5">
          <span class="text-[13px] font-semibold">{{ item.label }}<span v-if="item.category_name" class="font-normal text-muted-3"> · {{ item.category_name }}</span></span>
          <span class="flex items-baseline gap-1.5">
            <span class="font-mono text-[10.5px] text-muted-3">{{ item.sku }}</span>
            <span class="text-[13px] font-bold">{{ formatIDR(item.sell_price) }}</span>
          </span>
        </div>
        <div class="flex items-center gap-0.5 overflow-hidden rounded-lg border border-line bg-white">
          <button type="button" class="flex h-[30px] w-[30px] items-center justify-center text-muted-5 hover:bg-line-7" :aria-label="t('preorders.decrease')" @click="bumpCreateItem(item, -1)"><i class="ph-duotone ph-minus text-[13px]" aria-hidden="true"></i></button>
          <input
            type="number"
            min="1"
            class="h-[30px] w-[44px] border-x border-line text-center text-[13px] font-bold outline-none [appearance:textfield] [&::-webkit-inner-spin-button]:appearance-none"
            :aria-label="t('preorders.qty_direct_entry', { name: item.label })"
            :value="item.qty"
            @change="(e) => setCreateItemQty(item, e.target.value)"
          />
          <button type="button" class="flex h-[30px] w-[30px] items-center justify-center text-muted-5 hover:bg-line-7" :aria-label="t('preorders.increase')" @click="bumpCreateItem(item, 1)"><i class="ph-duotone ph-plus text-[13px]" aria-hidden="true"></i></button>
        </div>
        <button type="button" class="flex h-[30px] w-[30px] items-center justify-center rounded-md border border-line-2 text-danger-text hover:bg-danger-bg" :aria-label="t('preorders.delete_item', { name: item.label })" @click="removeCreateItem(idx)"><i class="ph-duotone ph-trash text-[13px]" aria-hidden="true"></i></button>
      </div>

      <!-- 024-invoice-layout-shipping-slip — "Add item" sekarang berperilaku
           sama dengan CustomerSearchDropdown.vue: fokus lapangan langsung
           menampilkan daftar produk yang bisa dijelajahi (bukan hanya
           setelah mengetik), lalu mengetik menyaring daftar itu. Ditutup
           lewat @blur (delay singkat supaya klik pada baris hasil sempat
           terdaftar sebelum panel hilang), meniru mekanisme click-outside
           CustomerSearchDropdown.vue tanpa perlu Teleport terpisah untuk
           penggunaan lokal ini. -->
      <div class="relative">
        <BaseInput
          v-model="createSearch"
          :label="t('preorders.add_item')"
          :placeholder="t('preorders.search_product_or_sku_placeholder')"
          @input="runCreateSearch"
          @focus="runCreateSearch"
          @blur="closeCreateResultsSoon"
        />
        <div v-if="createResults.length" class="absolute z-10 mt-1 max-h-[280px] w-full overflow-y-auto rounded-lg border border-line bg-white shadow-lg">
          <button v-for="v in createResults" :key="v.variant_id" type="button" class="flex w-full items-center gap-2.5 px-3.5 py-2.5 text-left hover:bg-line-7" @mousedown.prevent="addCreateItem(v)">
            <img v-if="v.image_url" :src="v.image_url" :alt="v.label" class="h-9 w-9 flex-none rounded-md border border-line-2 object-cover" />
            <div v-else class="flex h-9 w-9 flex-none items-center justify-center rounded-md border border-line-2 bg-surface-subtle text-muted-3">
              <i class="ph-duotone ph-image text-[14px]" aria-hidden="true"></i>
            </div>
            <div class="flex min-w-0 flex-1 flex-col gap-0.5">
              <span class="text-[13px] font-semibold">{{ v.label }}<span v-if="v.category_name" class="font-normal text-muted-3"> · {{ v.category_name }}</span></span>
              <span class="flex items-baseline gap-1.5">
                <span class="font-mono text-[11px] text-muted-3">{{ v.sku }}</span>
                <span class="text-[13px] font-bold">{{ formatIDR(v.sell_price) }}</span>
              </span>
            </div>
          </button>
        </div>
      </div>

      <!-- Requested: when Mail Order is selected, show the customer's
           own stored address info right here so staff can see at a
           glance whether it's usable before even opening the shipment
           form later — pulled straight from the already-selected
           customer (CustomerSearchDropdown returns full CustomerResource
           fields), no extra request. -->
      <div v-if="createFulfillment === 'courier' && createCustomer" class="flex flex-col gap-1 rounded-lg border border-line-3 bg-surface-subtle p-3">
        <span class="text-[12px] font-bold text-muted-5">{{ t('preorders.customer_address_info') }}</span>
        <span class="text-[13px]">{{ createCustomer.address || t('preorders.address_not_filled') }}</span>
        <span v-if="createCustomer.phone" class="text-[12px] text-muted-3">{{ createCustomer.phone }}</span>
      </div>

      <BaseTextarea v-model="createNotes" :label="t('preorders.notes')" :rows="2" />

      <div class="flex items-baseline justify-between border-t border-dashed border-line-2 pt-3">
        <span class="text-[13.5px] font-bold">{{ t('preorders.estimated_total') }}</span>
        <span class="text-[20px] font-extrabold tracking-tight">{{ formatIDR(createTotal) }}</span>
      </div>
    </div>
    <template #footer>
      <div class="flex justify-end gap-2.5">
        <BaseButton variant="secondary" @click="showCreate = false">{{ t('common.cancel') }}</BaseButton>
        <BaseButton :disabled="!canSubmitCreate" :loading="creating" @click="submitCreate">{{ editingPreorderId ? t('common.save') : t('preorders.save_preorder') }}</BaseButton>
      </div>
    </template>
  </BaseModal>
  <ImageLightbox :open="!!itemLightboxSrc" :src="itemLightboxSrc" :alt="itemLightboxAlt" @close="itemLightboxSrc = null" />
</template>
