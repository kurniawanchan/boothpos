<script setup>
import { computed } from 'vue';
import { useI18n } from 'vue-i18n';
import BarcodeBars from './BarcodeBars.vue';
import { accentAt } from '../../utils/catalogAccents';
import { formatIDR } from '../../utils/money';
import { formatDateTime } from '../../utils/date';
import { PDF_RASTER_SCALE } from '../../utils/barcode';

/**
 * 049 — SATU halaman A4 katalog (794 x 1123 px pada 96 dpi), dirasterisasi ke PDF oleh utils/catalogPdf.js.
 * Ukuran halaman dan tinggi kartu TETAP, supaya paginasi (8 kartu/halaman) deterministik dan tak ada
 * kartu yang terpotong. Hanya token desain (tanpa hex mentah); aksen per penjual dari utils/catalogAccents.
 *
 * Yang SENGAJA tidak ada: harga modal, stok, status pre-order — katalog dibagikan ke kasir dan pembeli.
 * Barcode selalu gelap di atas putih (BarcodeBars), apa pun warna aksen kartunya.
 *
 * BUG YANG DITEMUKAN & DIPERBAIKI (verifikasi browser sungguhan, 049) — halaman dirasterisasi html2canvas, yang
 * memotong teks pada kotak isi elemen: `truncate` pada <span> inline dan `line-clamp-*` (display:-webkit-box)
 * membuat separuh bawah huruf terpotong (nama penjual, nama produk, kaki halaman). Tes jsdom tidak bisa melihatnya.
 * Karena itu semua teks yang dipangkas memakai elemen BLOK dengan tinggi baris dan tinggi kotak dalam piksel yang
 * pas (potongan jatuh di batas baris, bukan di tengah huruf). JANGAN kembali ke line-clamp/truncate inline.
 *
 * Lanjutan (verifikasi browser yang sama): kotak pemotong html2canvas bergeser beberapa piksel ke atas terhadap teks,
 * jadi kotak yang tingginya SAMA dengan tinggi baris tetap kehilangan bagian bawah huruf. Setiap kotak pemotong
 * diberi tinggi 6 px lebih (`h-[… + 6]`) lalu ditarik kembali dengan `-mb-[6px]`: area potong lebih longgar,
 * tata letak tidak berubah.
 */
const props = defineProps({
  page: { type: Object, required: true }, // dari paginateCatalog()
  store: { type: Object, default: () => ({}) },
  generatedAt: { type: String, default: '' },
});

const { t } = useI18n();

const isCover = computed(() => props.page.type === 'cover');
const accent = computed(() => accentAt(props.page.accentIndex ?? 0));
const generatedLabel = computed(() => t('catalog.generated_on', { date: formatDateTime(props.generatedAt) }));
const pageLabel = computed(() => t('catalog.page_of', { page: props.page.number, total: props.page.total }));

// Indeks sampul memuat dua kolom; bila penjual terlalu banyak, sisanya diringkas (bukan meluap dari halaman).
const INDEX_CAPACITY = 36;
const indexRows = computed(() => (props.page.index ?? []).slice(0, INDEX_CAPACITY));
const indexOverflow = computed(() => Math.max(0, (props.page.index ?? []).length - INDEX_CAPACITY));

const contactLines = computed(() => [
  props.store.address,
  props.store.contact_person ? `${t('catalog.contact_person')}: ${props.store.contact_person}` : null,
  props.store.contact_phone ? `${t('catalog.phone')}: ${props.store.contact_phone}` : null,
  props.store.contact_email ? `${t('catalog.email')}: ${props.store.contact_email}` : null,
].filter(Boolean)); // baris yang belum diisi HILANG, tanpa label kosong
</script>

<template>
  <div class="flex h-[1123px] w-[794px] flex-col overflow-hidden bg-white text-ink" data-testid="catalog-page">
    <!-- Sampul: identitas toko + indeks penjual -->
    <template v-if="isCover">
      <div class="flex flex-col gap-5 bg-brand px-12 pb-10 pt-12 text-white">
        <div class="flex items-center gap-5">
          <img v-if="store.logo_url" :src="store.logo_url" :alt="store.name" class="h-24 w-24 shrink-0 rounded-2xl bg-white object-contain p-2" />
          <div class="flex min-w-0 flex-col gap-1">
            <span class="text-[13px] font-bold uppercase tracking-[0.2em] opacity-90">{{ t('catalog.cover_title') }}</span>
            <span class="block break-words text-[40px] font-extrabold leading-[46px]" data-testid="cover-store-name">{{ store.name }}</span>
          </div>
        </div>
        <div v-if="contactLines.length" class="flex flex-col gap-1 text-[14px] leading-snug opacity-95" data-testid="cover-contact">
          <span v-for="line in contactLines" :key="line">{{ line }}</span>
        </div>
      </div>

      <div class="flex flex-1 flex-col gap-4 px-12 py-8">
        <span class="text-[18px] font-extrabold">{{ t('catalog.cover_sellers') }}</span>
        <div class="grid grid-cols-2 gap-x-8 gap-y-2.5">
          <div v-for="row in indexRows" :key="row.artist_id" class="flex items-center gap-2.5 text-[13px]" data-testid="cover-index-row">
            <span class="h-3 w-3 shrink-0 rounded-full bg-current" :class="accentAt(row.accentIndex).text"></span>
            <span class="-mb-[6px] block h-[26px] min-w-0 flex-1 truncate font-semibold leading-[20px]">{{ row.name }}</span>
            <span class="block shrink-0 leading-[20px] text-muted-4">{{ t('catalog.variants_count', { count: row.count }) }} · {{ t('catalog.starts_on_page', { page: row.startPage }) }}</span>
          </div>
        </div>
        <span v-if="indexOverflow" class="text-[12px] text-muted-4">+{{ indexOverflow }}</span>
      </div>
    </template>

    <!-- Halaman penjual: kepala beraksen (diulang di halaman lanjutan) + kartu varian -->
    <template v-else>
      <div :class="accent.text">
        <div class="h-2.5 w-full bg-current"></div>
      </div>
      <div class="flex items-end justify-between gap-4 px-10 py-5" :class="[accent.soft, accent.text]" data-testid="seller-header">
        <div class="flex min-w-0 flex-col">
          <span class="-mb-[6px] block h-[40px] truncate text-[26px] font-extrabold leading-[34px]">{{ page.seller.name }}</span>
          <span class="block text-[12px] font-semibold leading-[16px] opacity-80">
            {{ t('catalog.variants_count', { count: page.seller.variantCount }) }}<template v-if="page.continued"> · {{ t('catalog.continued') }}</template>
          </span>
        </div>
      </div>

      <div class="grid flex-1 grid-cols-2 content-start gap-3 px-10 py-5">
        <div
          v-for="v in page.variants"
          :key="v.variant_id"
          class="flex h-[184px] flex-col gap-2 overflow-hidden rounded-xl border bg-white p-3"
          :class="accent.ring"
          data-testid="catalog-card"
        >
          <div class="flex gap-3">
            <div class="h-[88px] w-[88px] shrink-0 overflow-hidden rounded-lg border border-line-2 bg-surface-subtle">
              <img v-if="v.image_url" :src="v.image_url" :alt="v.variant_name || v.code" class="h-full w-full object-cover" />
              <div v-else data-testid="card-placeholder" class="flex h-full w-full items-center justify-center px-1 text-center text-[10px] text-muted-3">{{ t('catalog.no_image') }}</div>
            </div>
            <div class="flex min-w-0 flex-1 flex-col gap-px">
              <span v-if="v.category_name" class="block max-w-full self-start rounded-full px-2" :class="accent.chip"><span class="-mb-[6px] block h-[22px] truncate text-[10px] font-bold leading-[16px]">{{ v.category_name }}</span></span>
              <span class="-mb-[6px] block h-[38px] overflow-hidden break-words text-[13px] font-bold leading-[16px]">{{ v.product_name }}</span>
              <span class="-mb-[6px] block h-[21px] truncate text-[12px] leading-[15px] text-muted-4">{{ v.variant_name }}</span>
              <span class="mt-auto block text-[19px] font-extrabold leading-[22px]" :class="accent.text">{{ formatIDR(v.sell_price) }}</span>
            </div>
          </div>
          <div class="mt-auto flex">
            <BarcodeBars :pattern="v.barcode?.pattern ?? null" :text="v.code" :width="312" :height="30" :scale="PDF_RASTER_SCALE" />
          </div>
        </div>
      </div>
    </template>

    <div class="flex items-center justify-between gap-4 border-t border-line-2 px-10 py-3 text-[11px] text-muted-4" data-testid="catalog-footer">
      <span class="-mb-[6px] block h-[22px] min-w-0 truncate font-semibold leading-[16px]">{{ store.name }}</span>
      <span class="block shrink-0 leading-[16px]">{{ generatedLabel }}</span>
      <span class="block shrink-0 font-semibold leading-[16px]">{{ pageLabel }}</span>
    </div>
  </div>
</template>
