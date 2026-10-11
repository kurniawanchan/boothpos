<script setup>
/**
 * 043 — satu gaya tabel untuk keempat bagian halaman item (Varian & harga, Grup modifier, Ketersediaan, Versi resep),
 * mengikuti desain Back Office: kepala berkalimat biasa (bukan huruf kapital berjarak) dengan garis pemisah tipis,
 * baris ringkas (~44 px) dengan pemisah tipis, dan garis yang tenang.
 *
 * Mengapa SATU komponen dan bukan empat salinan kelas: gaya baris/kepala dipasang lewat selektor turunan di sini,
 * jadi tiap bagian hanya menulis isi sel. Konsekuensinya: sel TIDAK boleh membawa kelas padding sendiri (selektor
 * turunan lebih spesifik dan akan menang); penekanan (tebal, rata kanan, warna) tetap ditulis di sel masing-masing.
 * Slot `head` berisi <tr> kepala; slot bawaan berisi <tr> isi.
 */
</script>

<template>
  <div class="overflow-x-auto">
    <table
      class="w-full border-collapse text-left text-[13px]
        [&_thead]:bg-surface-subtle [&_thead_tr]:border-b [&_thead_tr]:border-line
        [&_th]:px-4 [&_th]:py-2.5 [&_th]:text-[12.5px] [&_th]:font-semibold [&_th]:text-muted-5
        [&_tbody_tr]:border-b [&_tbody_tr]:border-line [&_tbody_tr:last-child]:border-b-0
        [&_td]:px-4 [&_td]:py-3 [&_td]:align-middle
        [&_th:first-child]:pl-5 [&_td:first-child]:pl-5 [&_th:last-child]:pr-5 [&_td:last-child]:pr-5"
    >
      <thead><slot name="head" /></thead>
      <tbody><slot /></tbody>
    </table>
  </div>
</template>
