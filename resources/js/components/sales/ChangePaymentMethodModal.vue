<script setup>
import { ref, computed, watch } from 'vue';
import { useI18n } from 'vue-i18n';
import BaseModal from '../ui/BaseModal.vue';
import BaseButton from '../ui/BaseButton.vue';
import MethodTiles from '../payment/MethodTiles.vue';
import ChannelPicker from '../payment/ChannelPicker.vue';
import { changePaymentMethod } from '../../api/orders';
import { listPaymentChannels } from '../../api/payments';
import { formatIDR } from '../../utils/money';

/**
 * 054 (US7) — dialog "Ubah metode pembayaran" untuk SATU entri pembayaran penjualan POS. Hanya metode
 * (dan kanal untuk non-tunai) yang berubah; nominal, total, dan status tidak. Izin dan keadaan (batal,
 * tunai di shift tertutup, tunai dengan kembalian) diputuskan SERVER — aksinya sudah disembunyikan
 * lewat `can_change_method`, dan penolakan 409/422 yang tetap terjadi (mis. tak ada shift terbuka untuk
 * tujuan tunai) ditampilkan oleh interceptor global; dialog tetap terbuka supaya pengguna bisa memperbaiki.
 */
const props = defineProps({
  open: { type: Boolean, default: false },
  orderId: { type: [Number, String, null], default: null },
  payment: { type: Object, default: null },
});
const emit = defineEmits(['close', 'saved']);

const { t } = useI18n();

const channels = ref([]);
const method = ref('cash');
const channelId = ref(null);
const saving = ref(false);

watch(
  () => [props.open, props.payment?.id],
  async ([isOpen]) => {
    if (!isOpen || !props.payment) return;
    method.value = props.payment.method;
    channelId.value = props.payment.channel_id ?? null;
    saving.value = false;
    if (!channels.value.length) {
      try {
        channels.value = (await listPaymentChannels()).data ?? [];
      } catch {
        channels.value = [];
      }
    }
  },
  { immediate: true },
);

// Ganti metode = kanal lama tak berlaku lagi (ChannelPicker memilih otomatis bila hanya ada satu kanal).
function setMethod(next) {
  if (next === method.value) return;
  method.value = next;
  channelId.value = next === props.payment?.method ? (props.payment?.channel_id ?? null) : null;
}

const filteredChannels = computed(() => channels.value.filter((c) => c.type === method.value && c.is_active !== false));
const changed = computed(() => {
  if (!props.payment) return false;
  if (method.value !== props.payment.method) return true;
  return method.value !== 'cash' && channelId.value !== (props.payment.channel_id ?? null);
});
const canSave = computed(() => changed.value && !saving.value && (method.value === 'cash' || !!channelId.value));

async function save() {
  if (!canSave.value) return;
  saving.value = true;
  try {
    const payload = method.value === 'cash' ? { method: 'cash' } : { method: method.value, channel_id: channelId.value };
    const result = await changePaymentMethod(props.orderId, props.payment.id, payload);
    emit('saved', result);
    emit('close');
  } catch {
    // 403/409/422 sudah ditampilkan interceptor global; dialog tetap terbuka.
  } finally {
    saving.value = false;
  }
}
</script>

<template>
  <BaseModal :open="open" :title="t('payment_ledger.change_method_title')" max-width-class="max-w-[520px]" @close="emit('close')">
    <div v-if="payment" class="flex flex-col gap-4 px-6 py-5">
      <p class="text-[12.5px] text-muted-4">
        {{ t('payment_ledger.change_method_intro', { amount: formatIDR(payment.amount) }) }}
      </p>

      <MethodTiles :model-value="method" @update:model-value="setMethod" />

      <ChannelPicker v-if="method !== 'cash'" :channels="filteredChannels" :model-value="channelId" @update:model-value="(v) => (channelId = v)" />

      <p v-if="changed && method !== 'cash'" class="rounded-lg bg-warn-bg px-3 py-2 text-[12px] text-warn-text">
        {{ t('payment_ledger.change_method_needs_verification') }}
      </p>
      <p v-else-if="changed && method === 'cash'" class="rounded-lg bg-line-7 px-3 py-2 text-[12px] text-muted-4">
        {{ t('payment_ledger.change_method_cash_shift') }}
      </p>
    </div>

    <template #footer>
      <div class="flex justify-end gap-2.5">
        <BaseButton variant="secondary" @click="emit('close')">{{ t('common.cancel') }}</BaseButton>
        <BaseButton :disabled="!canSave" :loading="saving" @click="save">{{ t('common.save') }}</BaseButton>
      </div>
    </template>
  </BaseModal>
</template>
