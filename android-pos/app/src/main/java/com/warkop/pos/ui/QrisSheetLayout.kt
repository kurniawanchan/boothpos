package com.warkop.pos.ui

/**
 * 056 / T010 — ukuran judul lembar QRIS: pilih ukuran terbesar yang muat dalam lebar sheet (FR-003). Null = tidak
 * muat walau di 22 sp; pemanggil membiarkan judul turun baris, tidak memotongnya.
 */
fun titleSizeFor(textWidthAt: (Float) -> Int, available: Int): Float? =
    chooseFontSize(textWidthAt, available, listOf(30f, 28f, 26f, 24f, 22f))

/**
 * 056 / T021 — ukuran nominal total: satu baris, dibuat muat (FR-008). Bila tidak ada yang muat, pakai 28 sp:
 * batas terkecil yang masih terbaca di kasir. Teks tidak pernah dipotong atau dipecah.
 */
fun totalSizeFor(textWidthAt: (Float) -> Int, available: Int): Float =
    chooseFontSize(textWidthAt, available, listOf(40f, 36f, 32f, 28f)) ?: 28f
