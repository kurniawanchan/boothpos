package com.warkop.pos.ui

/**
 * 054 / US1 — pilih ukuran huruf terbesar yang muat dalam [available] piksel. Fungsi murni supaya bisa diuji tanpa
 * perangkat: pengukuran lebar teks dilakukan pemanggil (Compose), fungsi ini hanya memilih.
 *
 * [sizes] diurutkan dari yang terbesar. `null` = tidak ada ukuran yang muat; pemanggil lalu memindahkan chip
 * (nama outlet tidak pernah dipotong dengan elipsis, FR-002).
 */
fun chooseFontSize(textWidthAt: (Float) -> Int, available: Int, sizes: List<Float>): Float? {
    if (available <= 0) return null
    return sizes.firstOrNull { textWidthAt(it) <= available }
}
