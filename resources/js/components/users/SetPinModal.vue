<script setup>
import { computed, ref, watch } from 'vue';
import { useI18n } from 'vue-i18n';
import { setUserPin, suggestPin } from '../../api/users';
import BaseModal from '../ui/BaseModal.vue';
import BaseInput from '../ui/BaseInput.vue';
import BaseButton from '../ui/BaseButton.vue';

// 074 — owner/admin mengatur PIN pengguna: ketik 6 digit ATAU "Buat PIN acak". PIN acak diminta ke server (aturan PIN lemah satu
// definisi di sana), ditampilkan di dialog ini, dan baru tersimpan saat admin menekan Simpan. PIN TIDAK disimpan di mana pun di
// sisi klien: seluruh keadaan PIN dikosongkan saat dialog ditutup atau dibuka ulang, jadi tidak ada cara menampilkannya lagi.
const props = defineProps({
  open: { type: Boolean, default: false },
  user: { type: Object, default: null },
});
const emit = defineEmits(['close', 'saved']);

const { t } = useI18n();

const pin = ref('');
const generated = ref(null); // PIN acak dari server yang sedang tampil; null bila admin mengetik sendiri
const reveal = ref(false);
const saving = ref(false);
const generating = ref(false);
const error = ref('');
const saved = ref(false);
const keptGenerated = ref(''); // hanya diisi setelah simpan berhasil dengan PIN acak, supaya tetap terbaca sampai dialog ditutup

function resetState() {
  pin.value = '';
  generated.value = null;
  reveal.value = false;
  saving.value = false;
  generating.value = false;
  error.value = '';
  saved.value = false;
  keptGenerated.value = '';
}

watch(() => props.open, (isOpen) => { if (isOpen) resetState(); });

const name = computed(() => props.user?.name ?? '');
const replacing = computed(() => props.user?.has_pin === true);
const title = computed(() => t(replacing.value ? 'users.pin_title_reset' : 'users.pin_title_set', { name: name.value }));
const canSave = computed(() => pin.value.length === 6 && !saving.value && !saved.value);
// Penanda log saja (server tidak bisa membuktikannya): "generated" hanya bila yang disimpan persis PIN acak dari server.
const source = computed(() => (generated.value !== null && generated.value === pin.value ? 'generated' : 'typed'));

function onInput(value) {
  pin.value = String(value).replace(/\D/g, '').slice(0, 6);
  error.value = '';
}

async function generate() {
  generating.value = true;
  error.value = '';
  try {
    const res = await suggestPin();
    pin.value = res.pin;
    generated.value = res.pin;
    reveal.value = true;
  } catch (err) {
    error.value = err.message || t('users.pin_generate_failed');
  } finally {
    generating.value = false;
  }
}

async function save() {
  if (!canSave.value) return;
  saving.value = true;
  error.value = '';
  const wasGenerated = source.value === 'generated';
  try {
    const updated = await setUserPin(props.user.id, pin.value, source.value);
    keptGenerated.value = wasGenerated ? pin.value : '';
    // PIN ketikan tidak perlu terbaca lagi; PIN acak tetap tampil (keptGenerated) sampai dialog ditutup.
    pin.value = '';
    generated.value = null;
    saved.value = true;
    emit('saved', updated);
  } catch (err) {
    error.value = (err.isValidation && err.errors?.pin?.[0]) || err.message || t('users.pin_save_failed');
  } finally {
    saving.value = false;
  }
}

function close() {
  resetState();
  emit('close');
}
</script>

<template>
  <BaseModal :open="open" :title="title" max-width-class="max-w-[440px]" @close="close">
    <div class="flex flex-col gap-4 px-6 py-5">
      <template v-if="!saved">
        <p v-if="replacing" class="text-[12.5px] text-muted-4" data-testid="pin-replace-hint">{{ t('users.pin_replace_hint', { name }) }}</p>

        <BaseInput
          :model-value="pin"
          :label="t('users.pin_label')"
          :type="reveal ? 'text' : 'password'"
          :hint="t('users.pin_hint')"
          :error="error"
          :maxlength="6"
          :disabled="saving"
          autocomplete="off"
          inputmode="numeric"
          data-testid="pin-input"
          @update:model-value="onInput"
        />

        <div class="flex flex-wrap items-center gap-2">
          <BaseButton variant="secondary" :loading="generating" :disabled="saving" data-testid="pin-generate" @click="generate">
            <i class="ph-duotone ph-dice-five text-[16px]" aria-hidden="true"></i>
            {{ generating ? t('users.pin_generating') : t('users.pin_generate') }}
          </BaseButton>
          <button
            type="button"
            class="text-[12.5px] font-semibold text-muted-4 hover:text-brand-active"
            data-testid="pin-reveal"
            @click="reveal = !reveal"
          >
            {{ reveal ? t('users.pin_hide') : t('users.pin_show') }}
          </button>
        </div>

        <div v-if="generated !== null" class="rounded-lg border-2 border-line bg-surface-subtle px-4 py-3" data-testid="pin-generated-box">
          <p class="text-center font-mono text-[28px] font-bold tracking-[0.3em] text-ink" data-testid="pin-generated">{{ generated }}</p>
          <p class="mt-1 text-center text-[12px] text-muted-4">{{ t('users.pin_generated_note', { name }) }}</p>
        </div>
      </template>

      <template v-else>
        <p class="text-[13.5px] font-semibold text-ink" data-testid="pin-saved">{{ t('users.pin_saved', { name }) }}</p>
        <div v-if="keptGenerated" class="rounded-lg border-2 border-line bg-surface-subtle px-4 py-3" data-testid="pin-kept-box">
          <p class="text-center font-mono text-[28px] font-bold tracking-[0.3em] text-ink" data-testid="pin-kept">{{ keptGenerated }}</p>
          <p class="mt-1 text-center text-[12px] text-muted-4">{{ t('users.pin_saved_note') }}</p>
        </div>
        <p v-else class="text-[12.5px] text-muted-4">{{ t('users.pin_saved_typed_note') }}</p>
      </template>
    </div>

    <template #footer>
      <div class="flex justify-end gap-2.5">
        <BaseButton variant="secondary" data-testid="pin-close" @click="close">{{ saved ? t('users.pin_close') : t('common.cancel') }}</BaseButton>
        <BaseButton v-if="!saved" :loading="saving" :disabled="!canSave" data-testid="pin-save" @click="save">{{ saving ? t('users.pin_saving') : t('users.pin_save') }}</BaseButton>
      </div>
    </template>
  </BaseModal>
</template>
