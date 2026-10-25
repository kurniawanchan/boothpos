package com.warkop.pos.ui

/**
 * 055 / T041 — label tombol Kirim dan Bayar di counter. Kirim tetap menampilkan jumlah item yang belum dikirim
 * (keputusan produk, 2026-10-18); Bayar tidak lagi menampilkan nominal (FR-010).
 */
fun kirimLabel(unsent: Int): String = "Kirim $unsent"

fun bayarLabel(waiter: Boolean): String = if (waiter) "Cetak pre-bill" else "Bayar"

/** 075 — satu harga bila semua varian sama, selain itu "terendah–tertinggi" (sama dengan kartu POS web). */
fun priceLabel(min: Int, max: Int): String = if (min == max) com.warkop.pos.model.rp(min) else "${com.warkop.pos.model.rp(min)}–${com.warkop.pos.model.rp(max)}"

/** 075 — null (stok tidak dilacak, mis. hidangan made-to-order) berarti baris stok tidak ditampilkan. */
fun stockLabel(stock: Int?): String? = stock?.let { "Stok $it" }

fun variantCountLabel(count: Int): String = "$count varian"

/** 075 — inisial pengganti foto: huruf pertama dua kata pertama; kosong → "?". */
fun initialsOf(name: String): String =
    name.trim().split(Regex("\\s+")).filter { it.isNotEmpty() }.take(2).joinToString("") { it.take(1).uppercase() }.ifEmpty { "?" }

/**
 * 075 — baris Diskon pada ringkasan order selalu ada (referensi desain): "Rp 0" bila belum ada potongan, jumlah negatif
 * bila ada. Angkanya dari server; ini hanya tampilan.
 */
fun discountRowLabel(discount: Int, percent: Int, reason: String?): String =
    if (discount > 0) com.warkop.pos.model.discountHeading(percent, reason) else "Diskon"

fun discountRowAmount(discount: Int): String = if (discount > 0) "-" + com.warkop.pos.model.rp(discount) else com.warkop.pos.model.rp(0)
