package com.warkop.pos.ui

/**
 * 055 / T041 — label tombol Kirim dan Bayar di counter. Kirim tetap menampilkan jumlah item yang belum dikirim
 * (keputusan produk, 2026-10-18); Bayar tidak lagi menampilkan nominal (FR-010).
 */
fun kirimLabel(unsent: Int): String = "Kirim $unsent"

fun bayarLabel(waiter: Boolean): String = if (waiter) "Cetak pre-bill" else "Bayar"
