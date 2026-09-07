<script setup>
import { reactive, ref, computed, watch, onMounted } from 'vue';
import { useI18n } from 'vue-i18n';
import { createInvoice, updateInvoice } from '../../api/invoices';
import { listCompanies } from '../../api/companies';
import { listLicenses } from '../../api/licenses';
import { toMoneyString, parseMoney, formatIDR } from '../../utils/money';
import { useToastStore } from '../../stores/toast';
import BaseModal from '../ui/BaseModal.vue';
import BaseInput from '../ui/BaseInput.vue';
import BaseTextarea from '../ui/BaseTextarea.vue';
import BaseSelect from '../ui/BaseSelect.vue';
import BaseButton from '../ui/BaseButton.vue';

/**
 * 019-billing-system (T056) — create/edit form untuk Invoice mandiri.
 * Grand total SELALU dihitung ulang di server (InvoiceController::store()),
 * field di sini murni pratinjau live agar pengguna tidak perlu berhitung
 * manual sebelum submit.
 *
 * `payment_information` pre-fill dari Settings→Payment adalah TODO
 * (Phase 5 fitur ini, T068 — mendatang, berjalan paralel) — sengaja
 * dibiarkan kosong dulu di sini.
 */
const props = defineProps({
  open: { type: Boolean, default: false },
  invoice: { type: Object, default: null }, // null = create mode
});
const emit = defineEmits(['close', 'saved']);

const { t } = useI18n();
const toast = useToastStore();

const companies = ref([]);
const licenses = ref([]);

async function loadOptions() {
  try {
    const [companiesRes, licensesRes] = await Promise.all([
      listCompanies({ per_page: 100 }),
      listLicenses({ per_page: 100 }),
    ]);
    companies.value = companiesRes.data ?? [];
    licenses.value = licensesRes.data ?? [];
  } catch {
    // Diamkan — error toast sudah ditangani interceptor bersama.
  }
}
onMounted(loadOptions);

const companyOptions = computed(() => companies.value.map((c) => ({ value: c.id, label: c.name })));
const licenseOptions = computed(() => licenses.value.map((l) => ({ value: l.id, label: l.name })));

const isEdit = computed(() => !!props.invoice);

const form = reactive({
  company_id: '',
  license_id: '',
  subtotal: '',
  discount: '',
  due_date: '',
  payment_information: '',
  notes: '',
});
const formErrors = reactive({});
const saving = ref(false);

const selectedLicense = computed(() => licenses.value.find((l) => l.id == form.license_id) ?? null);

const grandTotal = computed(() => {
  const subtotal = parseMoney(form.subtotal);
  const discount = parseMoney(form.discount);
  const total = subtotal - discount;
  return total > 0 ? total : 0;
});

function resetForm() {
  Object.assign(form, {
    company_id: props.invoice?.company_id ?? '',
    license_id: props.invoice?.license_id ?? '',
    subtotal: props.invoice?.subtotal ?? '',
    discount: props.invoice?.discount ?? '',
    due_date: props.invoice?.due_date ?? '',
    payment_information: props.invoice?.payment_information ?? '',
    notes: props.invoice?.notes ?? '',
  });
  Object.keys(formErrors).forEach((k) => delete formErrors[k]);
}

watch(() => props.open, (isOpen) => {
  if (isOpen) resetForm();
});

async function submit() {
  saving.value = true;
  Object.keys(formErrors).forEach((k) => delete formErrors[k]);
  const payload = {
    company_id: form.company_id,
    license_id: form.license_id,
    subtotal: toMoneyString(form.subtotal),
    discount: toMoneyString(form.discount || 0),
    due_date: form.due_date,
    payment_information: form.payment_information || null,
    notes: form.notes || null,
  };
  try {
    if (isEdit.value) {
      await updateInvoice(props.invoice.id, payload);
      toast.success(t('invoices.invoice_updated'));
    } else {
      await createInvoice(payload);
      toast.success(t('invoices.invoice_created'));
    }
    emit('saved');
    emit('close');
  } catch (err) {
    if (err.isValidation) Object.assign(formErrors, Object.fromEntries(Object.entries(err.errors).map(([k, v]) => [k, v[0]])));
  } finally {
    saving.value = false;
  }
}
</script>

<template>
  <BaseModal :open="open" :title="isEdit ? t('invoices.edit_invoice') : t('invoices.new_invoice')" max-width-class="max-w-[520px]" @close="emit('close')">
    <form class="flex flex-col gap-3.5 px-6 py-5" @submit.prevent="submit">
      <BaseSelect v-model="form.company_id" :label="t('invoices.company')" :options="companyOptions" required :error="formErrors.company_id" />
      <BaseSelect v-model="form.license_id" :label="t('invoices.license')" :options="licenseOptions" required :error="formErrors.license_id" />
      <p v-if="selectedLicense" class="text-[12.5px] text-muted-4">
        {{ t('invoices.payment_type') }}: {{ t(`licenses.payment_type_${selectedLicense.payment_type}`) }}
      </p>

      <BaseInput v-model="form.subtotal" type="number" min="0" step="0.01" :label="t('invoices.subtotal')" required :error="formErrors.subtotal" />
      <BaseInput v-model="form.discount" type="number" min="0" step="0.01" :label="t('invoices.discount')" :error="formErrors.discount" />

      <div class="flex items-baseline justify-between rounded-lg bg-surface-subtle px-3.5 py-3">
        <span class="text-[13px] font-semibold text-muted-4">{{ t('invoices.grand_total') }}</span>
        <span class="text-[17px] font-bold tracking-tight">{{ formatIDR(grandTotal) }}</span>
      </div>

      <BaseInput v-model="form.due_date" type="date" :label="t('invoices.due_date')" required :error="formErrors.due_date" />
      <BaseTextarea v-model="form.payment_information" :label="t('invoices.payment_information')" :rows="3" :error="formErrors.payment_information" />
      <BaseTextarea v-model="form.notes" :label="t('master_data.notes')" :rows="2" :error="formErrors.notes" />
    </form>
    <template #footer>
      <div class="flex justify-end gap-2.5">
        <BaseButton variant="secondary" @click="emit('close')">{{ t('common.cancel') }}</BaseButton>
        <BaseButton :loading="saving" @click="submit">{{ t('common.save') }}</BaseButton>
      </div>
    </template>
  </BaseModal>
</template>
