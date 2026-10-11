<script setup>
import { computed } from 'vue';
import { useI18n } from 'vue-i18n';
import { useAuthStore } from '../../stores/auth';
import { useSettingsStore } from '../../stores/settings';
import BaseButton from '../ui/BaseButton.vue';

/**
 * 043 (US10, T068) — banner daftar Produk: "N perubahan belum dirilis" + tombol buka dialog rilis.
 *
 * Tampil HANYA bila rilis menu aktif DAN pengguna boleh bertindak: pemegang menu.release.publish (bila ada
 * perubahan) dan/atau pemegang menu.price.approve (tautan ke daftar persetujuan). Kendali yang tak boleh dipakai
 * pengguna tidak dirender (disembunyikan, bukan dinonaktifkan) — izin server tetap penjaga sebenarnya. Saat rilis
 * mati komponen ini tidak merender apa pun.
 *
 * `unreleased` = bidang `unreleased` dari GET /products/summary ({ count, summary[], active_version }); null saat
 * rilis mati atau belum termuat.
 */
const props = defineProps({
  unreleased: { type: Object, default: null },
});
const emit = defineEmits(['release', 'approvals']);
const { t } = useI18n();
const auth = useAuthStore();
const settings = useSettingsStore();

const count = computed(() => props.unreleased?.count ?? 0);
const canPublish = computed(() => auth.hasPermission('menu.release.publish'));
const canApprove = computed(() => auth.hasPermission('menu.price.approve'));
const visible = computed(() => settings.menuReleaseEnabled && ((canPublish.value && count.value > 0) || canApprove.value));
const lines = computed(() => (props.unreleased?.summary ?? []).slice(0, 3));
</script>

<template>
  <section v-if="visible" class="mx-[26px] mb-4 flex flex-wrap items-center gap-x-5 gap-y-2 border-2 border-brand bg-mint-50 px-4 py-3" :aria-label="t('products.release_banner_aria')" data-testid="release-banner">
    <div class="min-w-0 flex-1">
      <p class="text-[13.5px] font-bold">{{ count > 0 ? t('products.release_banner_count', { count }) : t('products.release_banner_none') }}</p>
      <ul v-if="count > 0 && lines.length" class="mt-0.5 text-[12px] text-muted-4">
        <li v-for="(line, i) in lines" :key="i" class="truncate">{{ line }}</li>
      </ul>
    </div>
    <div class="flex flex-wrap items-center gap-2">
      <BaseButton v-if="canApprove" size="sm" variant="secondary" @click="emit('approvals')">{{ t('products.release_banner_approvals') }}</BaseButton>
      <BaseButton v-if="canPublish && count > 0" size="sm" @click="emit('release')">{{ t('products.release_banner_action') }}</BaseButton>
    </div>
  </section>
</template>
