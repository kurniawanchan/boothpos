import { onBeforeUnmount, onMounted, ref } from 'vue';

/**
 * 041-back-office-ui-redesign — penghubung antara kerangka dan halaman soal JUDUL.
 *
 * Kerangka (AppShell) selalu menampilkan judul bawaan dari route.meta supaya halaman yang BELUM dimigrasi
 * ke kit tetap punya judul (FR-011: tidak ada konten kosong). Halaman yang memakai <PageHeader> (judul +
 * deskripsi + tindakan) memanggil registerPageHeader(); selama ia terpasang kerangka menyembunyikan judul
 * bawaan, jadi tak pernah ada dua judul. Penghitung (bukan boolean) karena saat navigasi halaman baru
 * terpasang SEBELUM halaman lama dilepas.
 */
export const customHeaderCount = ref(0);

export function registerPageHeader() {
  onMounted(() => {
    customHeaderCount.value += 1;
  });
  onBeforeUnmount(() => {
    customHeaderCount.value = Math.max(0, customHeaderCount.value - 1);
  });
}
