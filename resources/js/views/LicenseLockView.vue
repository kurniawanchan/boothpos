<script setup>
// 018-license-activation — seperti LoginView.vue, layar ini SELALU
// Bahasa Indonesia untuk semua orang, sebelum identitas akun apa pun
// diketahui (belum ada login sama sekali di titik ini) — jangan
// tambahkan toggle bahasa di sini.
import { reactive, ref } from 'vue';
import { useLicenseStore } from '../stores/license';
import BaseTextarea from '../components/ui/BaseTextarea.vue';
import BaseButton from '../components/ui/BaseButton.vue';

const license = useLicenseStore();

const form = reactive({ license_key: '' });
const errors = reactive({ license_key: '' });
const formError = ref('');
const submitting = ref(false);

async function onSubmit() {
  errors.license_key = '';
  formError.value = '';
  submitting.value = true;
  try {
    await license.activate(form.license_key.trim());
    // Tidak perlu router.push — beforeEach guard akan membaca
    // license.activated yang sudah ter-refresh dan membiarkan navigasi
    // berikutnya (mis. ke /login) lewat dengan sendirinya.
  } catch (err) {
    if (err.isValidation) {
      errors.license_key = err.errors?.license_key?.[0] ?? '';
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
      <form class="flex w-full max-w-[420px] flex-col gap-7" @submit.prevent="onSubmit">
        <div class="flex items-center gap-2.5">
          <div class="flex h-[34px] w-[34px] items-center justify-center rounded-lg bg-brand text-[20px] text-white">
            <i class="ph-duotone ph-lock-key" aria-hidden="true"></i>
          </div>
          <span class="text-[19px] font-extrabold tracking-tight">BoothPOS</span>
        </div>

        <div class="flex flex-col gap-1.5">
          <h1 class="text-[26px] font-bold tracking-tight">Aktivasi instalasi</h1>
          <p class="text-[13.5px] text-muted-4">
            Masukkan kunci lisensi yang dikirim ke email Anda setelah pembayaran untuk mengaktifkan BoothPOS di
            perangkat ini.
          </p>
        </div>

        <BaseTextarea
          v-model="form.license_key"
          label="Kunci lisensi"
          :rows="4"
          required
          :error="errors.license_key"
        />

        <p v-if="formError" role="alert" class="text-[13px] font-medium text-danger-text">{{ formError }}</p>

        <BaseButton type="submit" size="lg" class="w-full" :loading="submitting">
          Aktivasi<i class="ph-duotone ph-arrow-right text-[18px]" aria-hidden="true"></i>
        </BaseButton>
      </form>
    </div>

    <div class="hidden flex-col justify-between bg-ink px-10 py-20 md:flex">
      <span class="text-[12px] font-semibold uppercase tracking-[0.14em] text-mint-accent">BoothPOS</span>
      <div class="flex flex-col gap-[18px]">
        <h2 class="max-w-[420px] text-[34px] font-bold leading-[1.22] tracking-tight text-white text-balance">
          Satu lisensi, satu instalasi.
        </h2>
        <p class="max-w-[400px] text-[14.5px] leading-relaxed text-dark-muted">
          Belum punya kunci lisensi? Hubungi tim kami setelah pembayaran selesai untuk menerima kunci aktivasi
          lewat email.
        </p>
      </div>
    </div>
  </div>
</template>
