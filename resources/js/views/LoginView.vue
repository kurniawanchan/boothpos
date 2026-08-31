<script setup>
import { reactive, ref } from 'vue';
import { useRouter } from 'vue-router';
import { useAuthStore } from '../stores/auth';
import BaseInput from '../components/ui/BaseInput.vue';
import BaseButton from '../components/ui/BaseButton.vue';

const router = useRouter();
const auth = useAuthStore();

const form = reactive({ username: '', password: '' });
const errors = reactive({ username: '', password: '' });
const formError = ref('');
const submitting = ref(false);
const appHost = window.location.host;

async function onSubmit() {
  errors.username = '';
  errors.password = '';
  formError.value = '';
  submitting.value = true;
  try {
    await auth.login(form.username, form.password);
    router.push({ name: 'dashboard' });
  } catch (err) {
    if (err.isValidation) {
      errors.username = err.errors?.username?.[0] ?? '';
      errors.password = err.errors?.password?.[0] ?? '';
    } else if (err.status === 401) {
      formError.value = 'Username atau kata sandi salah.';
    } else {
      formError.value = err.message;
    }
  } finally {
    submitting.value = false;
  }
}
</script>

<template>
  <div class="grid min-h-screen grid-cols-1 md:grid-cols-2">
    <div class="flex items-center justify-center bg-white px-8 py-20">
      <form class="flex w-full max-w-[380px] flex-col gap-7" @submit.prevent="onSubmit">
        <div class="flex items-center gap-2.5">
          <div class="flex h-[34px] w-[34px] items-center justify-center rounded-lg bg-brand text-[20px] text-white">
            <i class="ph-duotone ph-storefront" aria-hidden="true"></i>
          </div>
          <span class="text-[19px] font-extrabold tracking-tight">BoothPOS</span>
        </div>

        <div class="flex flex-col gap-1.5">
          <h1 class="text-[26px] font-bold tracking-tight">Masuk ke kasir</h1>
          <p class="text-[14px] leading-relaxed text-muted">
            Instalasi lokal · <span class="font-mono text-[12.5px]">{{ appHost }}</span>
          </p>
        </div>

        <div class="flex flex-col gap-3.5">
          <BaseInput
            v-model="form.username"
            label="Username"
            autocomplete="username"
            required
            :error="errors.username"
          />
          <BaseInput
            v-model="form.password"
            label="Kata sandi"
            type="password"
            autocomplete="current-password"
            required
            :error="errors.password"
          />
        </div>

        <p v-if="formError" role="alert" class="text-[13px] font-medium text-danger-text">{{ formError }}</p>

        <BaseButton type="submit" size="lg" class="w-full" :loading="submitting">
          Masuk<i class="ph-duotone ph-arrow-right text-[18px]" aria-hidden="true"></i>
        </BaseButton>
      </form>
    </div>

    <div class="hidden flex-col justify-between bg-ink px-10 py-20 md:flex">
      <span class="text-[12px] font-semibold uppercase tracking-[0.14em] text-mint-accent">BoothPOS · Instalasi lokal</span>
      <div class="flex flex-col gap-[18px]">
        <h2 class="max-w-[420px] text-[34px] font-bold leading-[1.22] tracking-tight text-white text-balance">
          Kasir tetap jalan walau sinyal venue mati.
        </h2>
        <p class="max-w-[400px] text-[14.5px] leading-relaxed text-dark-muted">
          Seluruh transaksi terikat ke event aktif, rekap hasil per artist terhitung otomatis saat event ditutup.
        </p>
      </div>
      <div class="flex gap-10">
        <div class="flex flex-col gap-1">
          <span class="text-[22px] font-bold text-white">&lt; 30 dtk</span>
          <span class="text-[12px] text-dark-muted-2">per transaksi</span>
        </div>
        <div class="flex flex-col gap-1">
          <span class="text-[22px] font-bold text-white">&lt; 15 mnt</span>
          <span class="text-[12px] text-dark-muted-2">rekap artist</span>
        </div>
        <div class="flex flex-col gap-1">
          <span class="text-[22px] font-bold text-white">0</span>
          <span class="text-[12px] text-dark-muted-2">transaksi hilang</span>
        </div>
      </div>
    </div>
  </div>
</template>
