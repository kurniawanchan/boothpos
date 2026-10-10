<script setup>
import { reactive } from 'vue';
import { useI18n } from 'vue-i18n';
import { formatIDR } from '../../utils/money';
import StatusPill from '../ui/StatusPill.vue';
import BaseButton from '../ui/BaseButton.vue';

/**
 * 028 US2 — baris-baris sebuah tab. Hanya tampilan + niat pengguna: semua angka
 * datang dari server, dan setiap perubahan dikirim ke induk (TabView) yang
 * memanggil API lalu mengganti seluruh tab dengan respons terbaru.
 * Baris yang sudah dikirim ke dapur (`sent`) tidak punya kontrol ubah/hapus.
 */
const props = defineProps({
  lines: { type: Array, required: true },
  editable: { type: Boolean, default: true },
  busy: { type: Boolean, default: false },
  canServe: { type: Boolean, default: false }, // boleh menandai "sudah diantar"
  canCancelAfterPrep: { type: Boolean, default: false }, // owner/admin: boleh membatalkan item yang sudah dimasak
});
const emit = defineEmits(['change-qty', 'remove', 'save-note', 'serve', 'cancel']);
const { t } = useI18n();

const PREP_VARIANT = { new: 'neutral', preparing: 'warn', ready: 'mint', served: 'neutral', cancelled: 'danger' };

// Item yang sudah dikirim: siapa pun dengan izin lantai boleh membatalkan selagi BELUM dimasak (`new`);
// begitu mulai dimasak hanya owner/admin. Kontrol disembunyikan (bukan dinonaktifkan) bila tidak boleh.
const canCancel = (line) => line.sent && (line.prep_status === 'new' || (props.canCancelAfterPrep && ['preparing', 'ready', 'served'].includes(line.prep_status)));

// Catatan yang sedang diketik per baris; dikirim saat fokus keluar (blur) dan hanya bila berubah.
const drafts = reactive({});
const noteOf = (line) => (line.id in drafts ? drafts[line.id] : line.note ?? '');
function commitNote(line) {
  if (!(line.id in drafts)) return;
  const next = drafts[line.id].trim();
  delete drafts[line.id];
  if (next !== (line.note ?? '')) emit('save-note', line, next);
}
</script>

<template>
  <ul class="flex flex-col divide-y divide-line-3">
    <li v-for="line in lines" :key="line.id" class="flex flex-col gap-1.5 py-3" :data-testid="`line-${line.id}`">
      <div class="flex items-start gap-2.5">
        <div class="min-w-0 flex-1">
          <div class="text-[13.5px] font-bold leading-snug text-ink">{{ line.name }}</div>
          <ul v-if="line.modifiers?.length" class="mt-0.5 flex flex-wrap gap-x-2 text-[12px] text-muted-3">
            <li v-for="(m, i) in line.modifiers" :key="i">{{ m.option }}</li>
          </ul>
          <div class="mt-0.5 text-[12px] text-muted-3">{{ formatIDR(line.unit_price) }}</div>
        </div>
        <div class="text-right">
          <div class="text-[13.5px] font-bold">{{ formatIDR(line.line_total) }}</div>
          <StatusPill v-if="line.sent" :variant="PREP_VARIANT[line.prep_status] ?? 'mint'" :data-testid="`prep-${line.id}`">
            {{ line.prep_status ? t(`kitchen.status_${line.prep_status}`) : t('floor.tab.sent') }}<template v-if="line.station"> · {{ line.station.name }}</template>
          </StatusPill>
          <BaseButton v-if="canServe && line.prep_status === 'ready' && line.ticket_item_id" size="sm" class="mt-1.5" :disabled="busy" @click="emit('serve', line)">
            {{ t('kitchen.action_served') }}
          </BaseButton>
          <button
            v-if="editable && canCancel(line)"
            type="button"
            class="mt-1.5 text-[12px] font-semibold text-danger-text hover:underline disabled:opacity-40"
            :aria-label="t('floor.tab.cancel_item', { name: line.name })"
            :disabled="busy"
            @click="emit('cancel', line)"
          >
            {{ t('floor.tab.cancel_item_short') }}
          </button>
        </div>
      </div>

      <div v-if="editable && !line.sent" class="flex items-center gap-2">
        <div class="inline-flex items-center rounded-lg border border-line">
          <button
            type="button"
            class="flex h-8 w-8 items-center justify-center text-muted-4 hover:text-brand-active disabled:opacity-40"
            :aria-label="t('floor.tab.qty_dec', { name: line.name })"
            :disabled="busy"
            @click="emit('change-qty', line, line.qty - 1)"
          >
            <i class="ph-duotone ph-minus text-[14px]" aria-hidden="true"></i>
          </button>
          <span class="min-w-[28px] text-center text-[13px] font-bold" :data-testid="`qty-${line.id}`">{{ line.qty }}</span>
          <button
            type="button"
            class="flex h-8 w-8 items-center justify-center text-muted-4 hover:text-brand-active disabled:opacity-40"
            :aria-label="t('floor.tab.qty_inc', { name: line.name })"
            :disabled="busy"
            @click="emit('change-qty', line, line.qty + 1)"
          >
            <i class="ph-duotone ph-plus text-[14px]" aria-hidden="true"></i>
          </button>
        </div>
        <input
          :value="noteOf(line)"
          :placeholder="t('floor.tab.note_placeholder')"
          :aria-label="t('floor.tab.note_for', { name: line.name })"
          maxlength="255"
          class="h-8 min-w-0 flex-1 rounded-lg border border-line bg-white px-2.5 text-[12.5px] outline-none focus:border-brand focus:ring-[3px] focus:ring-mint-100"
          @input="drafts[line.id] = $event.target.value"
          @blur="commitNote(line)"
          @keydown.enter.prevent="$event.target.blur()"
        />
        <button
          type="button"
          class="flex h-8 w-8 items-center justify-center rounded-lg border border-line-2 text-danger-text hover:bg-danger-bg disabled:opacity-40"
          :aria-label="t('floor.tab.remove_line', { name: line.name })"
          :disabled="busy"
          @click="emit('remove', line)"
        >
          <i class="ph-duotone ph-trash text-[14px]" aria-hidden="true"></i>
        </button>
      </div>
      <div v-else-if="line.note" class="text-[12px] italic text-muted-3">“{{ line.note }}”</div>
      <div v-if="!editable || line.sent" class="text-[12px] font-semibold text-muted-3">{{ line.qty }}×</div>
    </li>
  </ul>
</template>
