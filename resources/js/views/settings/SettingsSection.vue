<script setup>
/**
 * 041-back-office-ui-redesign (US2) — pembungkus satu bagian Pengaturan di panel kanan <SettingsLayout>:
 * judul bagian (h2; h1 milik <PageHeader> halaman) + isi. Lebar isi dibatasi supaya formulir tidak
 * melebar selebar layar. Bagian-bagian di folder ini hanya MEMINDAHKAN kartu lama SettingsView —
 * perilaku, panggilan API, dan teksnya tidak berubah.
 */
defineProps({
  title: { type: String, required: true },
});
</script>

<template>
  <div class="flex flex-col gap-5 p-5">
    <h2 class="text-[15px] font-bold tracking-tight">{{ title }}</h2>
    <div class="flex max-w-[640px] flex-col gap-4">
      <slot />
    </div>
  </div>
</template>
