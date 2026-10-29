<?php

// 049 — katalog varian per penjual (PDF/Excel) dibuat utuh di memori, jadi ada batas atas jumlah varian.
// 2.000 melampaui target 500 varian dengan banyak ruang, namun menahan satu permintaan agar tidak
// menghabiskan memori PHP (batas bawaan 128 MB sudah pernah menjatuhkan ekspor Excel lain).
return [
    'max_variants' => 2000,
];
