<script setup>
import { reactive, ref, watch } from 'vue';
import { useI18n } from 'vue-i18n';
import { listBusinessTypes } from '../../api/businessTypes';
import { listLicenses } from '../../api/licenses';
import { updateCompany } from '../../api/companies';
import { useToastStore } from '../../stores/toast';
import BaseModal from '../ui/BaseModal.vue';
import BaseInput from '../ui/BaseInput.vue';
import BaseTextarea from '../ui/BaseTextarea.vue';
import BaseSelect from '../ui/BaseSelect.vue';
import BaseButton from '../ui/BaseButton.vue';

// 019-billing-system, second expansion (T090) — edits an already-onboarded
// Company. Deliberately NOT the same modal as CompanyOnboardingModal.vue:
// no owner_username/owner_password fields (research.md R11 — editing a
// company's own details is orthogonal to its owner's login, which is a
// create-time-only concern).

const props = defineProps({ open: { type: Boolean, default: false }, company: { type: Object, default: null } });
const emit = defineEmits(['close', 'updated']);

const { t } = useI18n();
const toast = useToastStore();

const businessTypeOptions = ref([]);
const licenseOptions = ref([]);
const loadingOptions = ref(false);

const form = reactive({
  business_type_id: '',
  license_id: '',
  name: '',
  address: '',
  contact_name: '',
  contact_email: '',
  contact_phone: '',
});
const formErrors = reactive({});
const saving = ref(false);

function fillForm() {
  const c = props.company;
  Object.assign(form, {
    business_type_id: c?.business_type?.id ?? c?.business_type_id ?? '',
    license_id: c?.package?.id ?? c?.license?.id ?? c?.license_id ?? '',
    name: c?.name ?? '',
    address: c?.address ?? '',
    contact_name: c?.contact_name ?? '',
    contact_email: c?.contact_email ?? '',
    contact_phone: c?.contact_phone ?? '',
  });
  Object.keys(formErrors).forEach((k) => delete formErrors[k]);
}

async function loadOptions() {
  loadingOptions.value = true;
  try {
    const [businessTypes, licenses] = await Promise.all([
      listBusinessTypes({ is_active: 1, per_page: 100 }),
      listLicenses({ is_active: 1, per_page: 100 }),
    ]);
    businessTypeOptions.value = businessTypes.data.map((b) => ({ value: b.id, label: b.name }));
    licenseOptions.value = licenses.data.map((l) => ({ value: l.id, label: `${l.name} (${t(`companies.license_tier_${l.license_tier}`)})` }));
  } finally {
    loadingOptions.value = false;
  }
}

watch(() => props.open, (isOpen) => {
  if (isOpen) {
    fillForm();
    loadOptions();
  }
});

async function submit() {
  if (!props.company) return;
  saving.value = true;
  Object.keys(formErrors).forEach((k) => delete formErrors[k]);
  const payload = {
    business_type_id: form.business_type_id,
    license_id: form.license_id,
    name: form.name,
    address: form.address || null,
    contact_name: form.contact_name,
    contact_email: form.contact_email,
    contact_phone: form.contact_phone || null,
  };
  try {
    const company = await updateCompany(props.company.id, payload);
    toast.success(t('companies.company_updated'));
    emit('updated', company);
    emit('close');
  } catch (err) {
    if (err.isValidation) Object.assign(formErrors, Object.fromEntries(Object.entries(err.errors).map(([k, v]) => [k, v[0]])));
  } finally {
    saving.value = false;
  }
}
</script>

<template>
  <BaseModal :open="open" :title="t('companies.edit_company')" max-width-class="max-w-[560px]" @close="emit('close')">
    <form class="flex flex-col gap-3.5 px-6 py-5" @submit.prevent="submit">
      <BaseSelect
        v-model="form.business_type_id"
        :label="t('companies.business_type')"
        :options="businessTypeOptions"
        required
        :disabled="loadingOptions"
        :error="formErrors.business_type_id"
      />
      <BaseSelect
        v-model="form.license_id"
        :label="t('companies.license')"
        :options="licenseOptions"
        required
        :disabled="loadingOptions"
        :error="formErrors.license_id"
      />
      <BaseInput v-model="form.name" :label="t('companies.company_name')" required maxlength="150" :error="formErrors.name" />
      <BaseTextarea v-model="form.address" :label="t('companies.company_address')" :rows="2" :error="formErrors.address" />
      <BaseInput v-model="form.contact_name" :label="t('companies.contact_name')" required maxlength="100" :error="formErrors.contact_name" />
      <BaseInput v-model="form.contact_email" :label="t('companies.contact_email')" type="email" required :error="formErrors.contact_email" />
      <BaseInput v-model="form.contact_phone" :label="t('master_data.phone')" :error="formErrors.contact_phone" />
    </form>
    <template #footer>
      <div class="flex justify-end gap-2.5">
        <BaseButton variant="secondary" @click="emit('close')">{{ t('common.cancel') }}</BaseButton>
        <BaseButton :loading="saving" @click="submit">{{ t('common.save') }}</BaseButton>
      </div>
    </template>
  </BaseModal>
</template>
