<script setup>
import { useI18n } from 'vue-i18n';
import { modifierRuleText } from '../../utils/itemDetail';
import BaseButton from '../ui/BaseButton.vue';

/**
 * 086 — grup modifier yang DIWARISI dari kategori, dengan aksi melepasnya untuk item ini saja, plus daftar grup yang
 * sudah dilepas dengan aksi memulihkan. Satu komponen untuk laci Grup modifier dan formulir Ubah item (FR-006).
 *
 * - Isi grup (opsi, harga) tidak pernah diubah di sini; kategori juga tidak. Yang berubah hanya hubungan item↔grup.
 * - `editable` false: TIDAK ada tombol sama sekali (Konstitusi III: kontrol yang tidak bisa dipakai disembunyikan, bukan
 *   dinonaktifkan). Server tetap menolak 403 dari peran tanpa izin.
 * - Judul bagian memakai <p>, bukan heading: formulir Ubah item menghitung heading sebagai bagiannya sendiri.
 * - Komponen tidak menyimpan apa pun: ia hanya mengemit `exclude` / `restore` dengan `group_id`; pemanggil yang mengubah daftar
 *   (lihat utils/modifierExclusion.js) dan mengirimnya lewat `saveModifierLinks`.
 */
defineProps({
  inherited: { type: Array, default: () => [] }, // baris source:'category' yang masih berlaku
  excluded: { type: Array, default: () => [] }, // baris bentuk sama yang dilepas item ini
  editable: { type: Boolean, default: false },
  testid: { type: String, default: 'inherited-modifiers' },
});
defineEmits(['exclude', 'restore']);
const { t } = useI18n();
</script>

<template>
  <div class="flex flex-col gap-3" :data-testid="testid">
    <section v-if="inherited.length" class="flex flex-col gap-2 border-2 border-line bg-surface-subtle px-4 py-3" data-testid="im-inherited">
      <p class="text-[11.5px] font-bold uppercase tracking-wider text-muted" data-testid="im-inherited-title">{{ t('products.ml_from_category_editable') }}</p>
      <p v-if="editable" class="text-[12px] text-muted-4">{{ t('products.ml_from_category_hint') }}</p>
      <ul class="flex flex-col gap-1.5 text-[12.5px]">
        <li v-for="l in inherited" :key="l.group_id" class="flex items-center justify-between gap-3" data-testid="im-inherited-row">
          <span class="font-semibold">{{ l.name }}</span>
          <span class="flex items-center gap-3">
            <span class="text-muted-4">{{ modifierRuleText(l, t) }}</span>
            <BaseButton v-if="editable" variant="secondary" size="xs" :aria-label="t('products.ml_exclude_aria', { name: l.name })" data-testid="im-exclude" @click="$emit('exclude', l.group_id)">
              {{ t('products.ml_exclude') }}
            </BaseButton>
          </span>
        </li>
      </ul>
    </section>

    <section v-if="excluded.length" class="flex flex-col gap-2 border-2 border-dashed border-line px-4 py-3" data-testid="im-excluded">
      <p class="text-[11.5px] font-bold uppercase tracking-wider text-muted" data-testid="im-excluded-title">{{ t('products.ml_excluded_title') }}</p>
      <p class="text-[12px] text-muted-4">{{ t('products.ml_excluded_hint') }}</p>
      <ul class="flex flex-col gap-1.5 text-[12.5px]">
        <li v-for="l in excluded" :key="l.group_id" class="flex items-center justify-between gap-3" data-testid="im-excluded-row">
          <span class="font-semibold text-muted-5 line-through">{{ l.name }}</span>
          <BaseButton v-if="editable" variant="secondary" size="xs" :aria-label="t('products.ml_restore_aria', { name: l.name })" data-testid="im-restore" @click="$emit('restore', l.group_id)">
            {{ t('products.ml_restore') }}
          </BaseButton>
        </li>
      </ul>
    </section>
  </div>
</template>
