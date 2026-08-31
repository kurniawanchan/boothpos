<script setup>
import { computed } from 'vue';

const props = defineProps({
  channels: { type: Array, required: true }, // already filtered by type
  modelValue: { type: [Number, String, null], default: null },
});
const emit = defineEmits(['update:modelValue']);

const selected = computed(() => props.channels.find((c) => c.id === props.modelValue) ?? null);
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
      <img
        v-if="selected.type === 'qr_ewallet' && selected.qr_image_url"
        :src="selected.qr_image_url"
        :alt="`Kode QR ${selected.provider}`"
        class="h-40 w-40 self-center rounded-md border border-line-2 object-contain"
      />
      <span v-else class="font-mono text-[27px] font-extrabold tracking-wide text-ink" style="font-variant-numeric: tabular-nums">
        {{ selected.account_number || '—' }}
      </span>
      <span class="text-[13px] text-muted-4">a.n. {{ selected.account_name }}</span>
    </div>
    <p v-else class="text-[12.5px] text-muted-3">Pilih kanal pembayaran di atas.</p>
  </div>
</template>
