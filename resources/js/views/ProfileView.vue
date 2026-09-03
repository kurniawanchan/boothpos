<script setup>
import { reactive, ref } from 'vue';
import { useI18n } from 'vue-i18n';
import { useAuthStore } from '../stores/auth';
import { useToastStore } from '../stores/toast';
import BaseButton from '../components/ui/BaseButton.vue';
import BaseInput from '../components/ui/BaseInput.vue';

const { t } = useI18n();
const auth = useAuthStore();
const toast = useToastStore();

// --- Foto profil (005-ux-enhancements-dashboard, US3) --------------------
const photoInput = ref(null);
const uploadingPhoto = ref(false);
const photoError = ref('');

function pickPhoto() {
  photoInput.value?.click();
}

async function onPhotoSelected(e) {
  const file = e.target.files?.[0];
  e.target.value = '';
  if (!file) return;

  photoError.value = '';
  uploadingPhoto.value = true;
  try {
    await auth.changePhoto(file);
    toast.success(t('profile.photo_updated'));
  } catch (err) {
    photoError.value = err.isValidation ? Object.values(err.errors)[0]?.[0] ?? err.message : err.message;
  } finally {
    uploadingPhoto.value = false;
  }
}

// --- Ganti password --------------------------------------------------------
const passwordForm = reactive({ current_password: '', password: '', password_confirmation: '' });
const passwordErrors = reactive({});
const savingPassword = ref(false);

async function submitPasswordChange() {
  Object.keys(passwordErrors).forEach((k) => delete passwordErrors[k]);
  savingPassword.value = true;
  try {
    const res = await auth.changePassword({ ...passwordForm });
    toast.success(res.message);
    passwordForm.current_password = '';
    passwordForm.password = '';
    passwordForm.password_confirmation = '';
  } catch (err) {
    if (err.isValidation) {
      Object.assign(passwordErrors, Object.fromEntries(Object.entries(err.errors).map(([k, v]) => [k, v[0]])));
    } else {
      toast.error(err.message);
    }
  } finally {
    savingPassword.value = false;
  }
}
</script>

<template>
  <div class="flex flex-col gap-5 px-[26px] pb-10 pt-[22px]">
    <div class="grid grid-cols-1 gap-4 lg:grid-cols-2">
      <div class="flex flex-col gap-4 rounded-card border border-line-2 bg-white p-5">
        <span class="text-[14.5px] font-bold">{{ t('profile.photo') }}</span>
        <div class="flex items-center gap-4">
          <img
            v-if="auth.user?.photo_url"
            :src="auth.user.photo_url"
            :alt="auth.user?.name"
            class="h-16 w-16 rounded-full object-cover"
          />
          <div v-else class="flex h-16 w-16 items-center justify-center rounded-full bg-mint-100 text-[20px] font-bold text-brand-active">
            {{ (auth.user?.name || '?').slice(0, 2).toUpperCase() }}
          </div>
          <div class="flex flex-col gap-1.5">
            <BaseButton variant="secondary" :disabled="uploadingPhoto" @click="pickPhoto">
              <i class="ph-duotone ph-camera text-[16px]" aria-hidden="true"></i>
              {{ t('profile.change_photo') }}
            </BaseButton>
            <span class="text-[11.5px] text-muted-3">{{ t('profile.photo_hint') }}</span>
            <span v-if="photoError" class="text-[12px] font-medium text-danger-text">{{ photoError }}</span>
          </div>
          <input ref="photoInput" type="file" accept="image/jpeg,image/png" class="hidden" @change="onPhotoSelected" />
        </div>

        <div class="mt-2 flex flex-col gap-3 border-t border-line-6 pt-4">
          <div class="flex flex-col gap-0.5">
            <span class="text-[11.5px] font-semibold text-muted-3">{{ t('profile.name') }}</span>
            <span class="text-[14px] font-semibold">{{ auth.user?.name }}</span>
          </div>
          <div class="flex flex-col gap-0.5">
            <span class="text-[11.5px] font-semibold text-muted-3">{{ t('profile.username') }}</span>
            <span class="text-[14px] font-semibold">{{ auth.user?.username }}</span>
          </div>
          <div class="flex flex-col gap-0.5">
            <span class="text-[11.5px] font-semibold text-muted-3">{{ t('profile.role') }}</span>
            <span class="text-[14px] font-semibold capitalize">{{ auth.user?.role }}</span>
          </div>
        </div>
      </div>

      <form class="flex flex-col gap-3.5 rounded-card border border-line-2 bg-white p-5" @submit.prevent="submitPasswordChange">
        <span class="text-[14.5px] font-bold">{{ t('profile.change_password') }}</span>
        <BaseInput
          v-model="passwordForm.current_password"
          type="password"
          :label="t('profile.current_password')"
          required
          autocomplete="current-password"
          :error="passwordErrors.current_password"
        />
        <BaseInput
          v-model="passwordForm.password"
          type="password"
          :label="t('profile.new_password')"
          required
          autocomplete="new-password"
          :error="passwordErrors.password"
        />
        <BaseInput
          v-model="passwordForm.password_confirmation"
          type="password"
          :label="t('profile.confirm_password')"
          required
          autocomplete="new-password"
        />
        <BaseButton type="submit" :disabled="savingPassword">{{ t('profile.save_password') }}</BaseButton>
      </form>
    </div>
  </div>
</template>
