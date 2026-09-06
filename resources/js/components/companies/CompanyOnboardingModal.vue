<script setup>
import { reactive, ref, watch } from 'vue';
import { useI18n } from 'vue-i18n';
import { listBusinessTypes } from '../../api/businessTypes';
import { listPackages } from '../../api/packages';
import { createCompany } from '../../api/companies';
import { useToastStore } from '../../stores/toast';
import BaseModal from '../ui/BaseModal.vue';
import BaseInput from '../ui/BaseInput.vue';
import BaseTextarea from '../ui/BaseTextarea.vue';
import BaseSelect from '../ui/BaseSelect.vue';
import BaseButton from '../ui/BaseButton.vue';

const props = defineProps({ open: { type: Boolean, default: false } });
const emit = defineEmits(['close', 'onboarded']);

const { t } = useI18n();
const toast = useToastStore();

const businessTypeOptions = ref([]);
const packageOptions = ref([]);
const loadingOptions = ref(false);

const form = reactive({
  business_type_id: '',
  package_id: '',
  name: '',
  address: '',
  contact_name: '',
  contact_email: '',
  contact_phone: '',
  owner_username: '',
  owner_password: '',
});
const formErrors = reactive({});
const saving = ref(false);

function resetForm() {
  Object.assign(form, {
    business_type_id: '', package_id: '', name: '', address: '',
    contact_name: '', contact_email: '', contact_phone: '',
    owner_username: '', owner_password: '',
  });
  Object.keys(formErrors).forEach((k) => delete formErrors[k]);
}

async function loadOptions() {
  loadingOptions.value = true;
  try {
    const [businessTypes, packages] = await Promise.all([
      listBusinessTypes({ is_active: 1, per_page: 100 }),
      listPackages({ is_active: 1, per_page: 100 }),
    ]);
    businessTypeOptions.value = businessTypes.data.map((b) => ({ value: b.id, label: b.name }));
    packageOptions.value = packages.data.map((p) => ({ value: p.id, label: `${p.name} (${t(`companies.license_tier_${p.license_tier}`)})` }));
  } finally {
    loadingOptions.value = false;
  }
}

watch(() => props.open, (isOpen) => {
  if (isOpen) {
    resetForm();
    loadOptions();
  }
});

async function submit() {
  saving.value = true;
  Object.keys(formErrors).forEach((k) => delete formErrors[k]);
  const payload = {
    business_type_id: form.business_type_id,
    package_id: form.package_id,
    name: form.name,
    address: form.address || null,
    contact_name: form.contact_name,
    contact_email: form.contact_email,
    contact_phone: form.contact_phone || null,
    owner_username: form.owner_username,
    owner_password: form.owner_password,
  };
  try {
    const company = await createCompany(payload);
    toast.success(t('companies.onboarded_success'));
    emit('onboarded', company);
    emit('close');
  } catch (err) {
    if (err.isValidation) Object.assign(formErrors, Object.fromEntries(Object.entries(err.errors).map(([k, v]) => [k, v[0]])));
  } finally {
    saving.value = false;
  }
}
</script>

<template>
  <BaseModal :open="open" :title="t('companies.onboard_company')" max-width-class="max-w-[560px]" @close="emit('close')">
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
        v-model="form.package_id"
        :label="t('companies.package')"
        :options="packageOptions"
        required
        :disabled="loadingOptions"
        :error="formErrors.package_id"
      />
      <BaseInput v-model="form.name" :label="t('companies.company_name')" required maxlength="150" :error="formErrors.name" />
      <BaseTextarea v-model="form.address" :label="t('companies.company_address')" :rows="2" :error="formErrors.address" />
      <BaseInput v-model="form.contact_name" :label="t('companies.contact_name')" required maxlength="100" :error="formErrors.contact_name" />
      <BaseInput v-model="form.contact_email" :label="t('companies.contact_email')" type="email" required :error="formErrors.contact_email" />
      <BaseInput v-model="form.contact_phone" :label="t('master_data.phone')" :error="formErrors.contact_phone" />
      <hr class="border-line" />
      <BaseInput v-model="form.owner_username" :label="t('companies.owner_username')" required maxlength="50" :error="formErrors.owner_username" />
      <BaseInput v-model="form.owner_password" :label="t('companies.owner_password')" type="password" required :hint="t('companies.owner_password_hint')" :error="formErrors.owner_password" />
    </form>
    <template #footer>
      <div class="flex justify-end gap-2.5">
        <BaseButton variant="secondary" @click="emit('close')">{{ t('common.cancel') }}</BaseButton>
        <BaseButton :loading="saving" @click="submit">{{ t('companies.onboard_btn') }}</BaseButton>
      </div>
    </template>
  </BaseModal>
</template>
