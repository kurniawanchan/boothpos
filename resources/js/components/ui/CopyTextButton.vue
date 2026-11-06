<script setup>
// 063 — tombol salin-teks kecil (nomor transaksi). Memakai Clipboard API, dengan cadangan execCommand untuk
// konteks tidak aman (mis. dibuka lewat IP jaringan lokal, bukan localhost). Hasilnya diberi tahu lewat toast.
import { useI18n } from 'vue-i18n';
import { useToastStore } from '../../stores/toast';

const props = defineProps({ text: { type: String, required: true }, label: { type: String, default: '' } });
const { t } = useI18n();
const toast = useToastStore();

async function copy() {
  let ok = false;
  try {
    await navigator.clipboard.writeText(props.text);
    ok = true;
  } catch {
    try {
      const area = document.createElement('textarea');
      area.value = props.text;
      area.setAttribute('readonly', '');
      area.style.position = 'fixed';
      area.style.opacity = '0';
      document.body.appendChild(area);
      area.select();
      ok = document.execCommand('copy');
      document.body.removeChild(area);
    } catch {
      ok = false;
    }
  }
  if (ok) toast.success(t('common.copied', { text: props.text }));
  else toast.error(t('common.copy_failed'));
}
</script>

<template>
  <button
    type="button"
    class="inline-flex h-6 w-6 shrink-0 items-center justify-center rounded text-muted-3 hover:bg-surface-subtle hover:text-brand-active focus-visible:outline-2 focus-visible:outline-brand-active"
    :aria-label="label || t('common.copy')"
    :title="label || t('common.copy')"
    data-testid="copy-text"
    @click.stop="copy"
  >
    <i class="ph-duotone ph-copy text-[15px]" aria-hidden="true"></i>
  </button>
</template>
