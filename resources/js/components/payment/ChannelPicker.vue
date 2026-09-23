<script setup>
import { computed, ref, watch } from 'vue';
import { useI18n } from 'vue-i18n';
import ImageLightbox from '../ui/ImageLightbox.vue';

const { t } = useI18n();

const props = defineProps({
  channels: { type: Array, required: true }, // already filtered by type
  modelValue: { type: [Number, String, null], default: null },
});
const emit = defineEmits(['update:modelValue']);

const selected = computed(() => props.channels.find((c) => c.id === props.modelValue) ?? null);

// 022-preorder-invoice-crud-overhaul (US3, FR-011) — QR diperbesar dan bisa
// diklik untuk tampilan penuh; komponen popup-nya (ImageLightbox.vue) satu
// definisi yang sama dipakai lagi oleh invoice pre-order (research.md
// Decision 8) — bukan hasil ditulis dua kali per layar.
const showQrLightbox = ref(false);

// BUG YANG DITEMUKAN & DIPERBAIKI: saat hanya ada satu kanal pembayaran
// (mis. satu channel Gopay qr_ewallet), chip pemilihan hanya dirender
// ketika channels.length > 1, dan tidak ada fallback auto-select — akibatnya
// tidak ada yang pernah terpilih dan layar checkout QRIS tampak kosong
// selamanya ("Pilih kanal pembayaran di atas."). Auto-pilih satu-satunya
// kanal yang tersedia; perilaku pilih-manual untuk 2+ kanal tidak diubah.
watch(
  () => props.channels,
  (list) => {
    if (list.length === 1 && props.modelValue !== list[0].id) {
      emit('update:modelValue', list[0].id);
    } else if (list.length === 0 && props.modelValue !== null) {
      emit('update:modelValue', null);
    } else if (list.length > 1 && props.modelValue !== null && !list.some((c) => c.id === props.modelValue)) {
      // kanal yang sebelumnya terpilih sudah tidak ada di daftar (mis. ganti metode)
      emit('update:modelValue', null);
    }
  },
  { immediate: true }
);
</script>

<template>
  <div class="flex flex-col gap-3">
    <div v-if="channels.length > 1" class="flex flex-wrap gap-2">
      <button
        v-for="c in channels"
        :key="c.id"
        type="button"
        class="rounded-full border px-3.5 py-1.5 text-[12.5px] font-semibold transition-colors"
        :class="modelValue === c.id ? 'border-brand bg-mint-100 text-brand-active' : 'border-line bg-white text-muted-4 hover:border-brand'"
        @click="emit('update:modelValue', c.id)"
      >
        {{ c.provider }}
      </button>
    </div>
    <div v-if="selected" class="flex flex-col gap-2 rounded-lg border border-mint-border bg-mint-50 px-4 py-3.5">
      <span class="text-[11.5px] font-bold uppercase tracking-wider text-dark-muted-2">{{ selected.provider }}</span>
      <button
        v-if="selected.type === 'qr_ewallet' && selected.qr_image_url"
        type="button"
        class="self-center"
        :aria-label="t('pos.enlarge_qr')"
        @click="showQrLightbox = true"
      >
        <img
          :src="selected.qr_image_url"
          :alt="t('pos.qr_code_for', { provider: selected.provider })"
          class="h-56 w-56 cursor-zoom-in rounded-md border border-line-2 object-contain transition-transform hover:scale-[1.02]"
        />
      </button>
      <span v-else class="font-mono text-[27px] font-extrabold tracking-wide text-ink" style="font-variant-numeric: tabular-nums">
        {{ selected.account_number || '—' }}
      </span>
      <span class="text-[13px] text-muted-4">{{ t('pos.account_holder', { name: selected.account_name }) }}</span>
    </div>
    <p v-else class="text-[12.5px] text-muted-3">{{ t('pos.pick_channel_above') }}</p>

    <ImageLightbox
      :open="showQrLightbox"
      :src="selected?.qr_image_url"
      :alt="selected ? t('pos.qr_code_for', { provider: selected.provider }) : ''"
      @close="showQrLightbox = false"
    />
  </div>
</template>
