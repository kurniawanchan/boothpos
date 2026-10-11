<script setup>
import { useI18n } from 'vue-i18n';
import { formatDateTime } from '../../utils/formatters';
import ItemSection from './ItemSection.vue';
import SectionTable from './SectionTable.vue';
import AvailabilityChips from './AvailabilityChips.vue';

/**
 * 043 (US2/US3) — "Ketersediaan per outlet". Tahap A: SATU baris untuk seluruh instalasi (Status Ada/86, Kuota harian
 * dan Channel "—" karena belum ada). Saat ditandai 86 baris diberi catatan: alasan, siapa, dan sampai kapan (akhir
 * hari bisnis). Catatan hanya menyatakan data yang dikirim server — tidak ada janji soal kanal online.
 */
defineProps({
  availability: { type: Array, default: () => [] },
});
const { t } = useI18n();
</script>

<template>
  <ItemSection :title="t('products.sec_availability')" testid="section-availability">
    <p v-if="!availability.length" class="px-5 py-6 text-[13px] text-muted-4">{{ t('page.unavailable') }}</p>
    <SectionTable v-else>
      <template #head>
        <tr>
          <th scope="col">{{ t('products.col_scope') }}</th>
          <th scope="col">{{ t('products.col_status') }}</th>
          <th scope="col">{{ t('products.col_quota') }}</th>
          <th scope="col">{{ t('products.col_channels') }}</th>
        </tr>
      </template>
      <template v-for="(a, i) in availability" :key="a.outlet_id ?? `all-${i}`">
        <tr data-testid="availability-row">
          <td class="font-semibold">{{ a.outlet_code || t('products.scope_all') }}</td>
          <td><AvailabilityChips :availability="[a]" /></td>
          <td class="tabular-nums text-muted-4">{{ a.daily_quota ?? '—' }}</td>
          <td class="text-muted-4">{{ a.channels || '—' }}</td>
        </tr>
        <tr v-if="a.state === 'sold_out'" class="bg-danger-bg" data-testid="sold-out-note">
          <td colspan="4" class="text-[12.5px] text-danger-text">
            <span class="font-bold">{{ t('products.sold_out_reason', { reason: a.reason || '—' }) }}</span>
            <span v-if="a.marked_by"> · {{ t('products.sold_out_by', { name: a.marked_by }) }}</span>
            <span v-if="a.expires_at"> · {{ t('products.sold_out_until', { time: formatDateTime(a.expires_at) }) }}</span>
            <span v-if="a.note"> · {{ t('products.sold_out_note', { note: a.note }) }}</span>
          </td>
        </tr>
      </template>
    </SectionTable>
  </ItemSection>
</template>
