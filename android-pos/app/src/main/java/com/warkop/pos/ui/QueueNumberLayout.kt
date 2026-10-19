package com.warkop.pos.ui

/**
 * 061 / T002 — ukuran nomor antrean di kartu "Panggil pelanggan": angka terbesar yang muat di lebar kartu. Memakai
 * [chooseFontSize] (aturan yang sama dengan chip dan label). Bila tidak ada yang muat, ukuran terkecil dipakai: nomor
 * tidak pernah dipotong di tepi kartu.
 */
private val QUEUE_SIZES = listOf(168f, 150f, 132f, 120f, 104f, 88f, 72f, 64f)

fun queueSizeFor(textWidthAt: (Float) -> Int, available: Int): Float =
    chooseFontSize(textWidthAt, available, QUEUE_SIZES) ?: QUEUE_SIZES.last()
