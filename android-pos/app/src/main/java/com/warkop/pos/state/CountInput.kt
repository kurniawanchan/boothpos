package com.warkop.pos.state

import com.warkop.pos.model.ShiftCount

/**
 * 065 / research D9 — isian uang buka/tutup shift: stepper per pecahan ATAU total ketikan, tidak keduanya.
 * Aturannya satu: yang terakhir disentuh menang. Menyentuh stepper mengosongkan ketikan; mengetik mengosongkan
 * stepper — jadi hanya SATU total yang pernah dikirim. Murni (tanpa Compose) supaya bisa diuji; layar menyimpannya
 * sebagai state dan memanggil fungsi-fungsi di bawah.
 */
data class CountInput(val counts: Map<Int, Int> = emptyMap(), val typed: String = "") {

    /** Total yang berlaku: ketikan bila ada, kalau tidak jumlah pecahan. */
    val total: Int
        get() = if (typed.isNotEmpty()) typed.toIntOrNull() ?: 0 else ShiftCount.ByDenomination(counts).total

    val isTyped: Boolean get() = typed.isNotEmpty()

    /** Ketikan ditampilkan dengan pemisah ribuan titik (id-ID), mis. "1.500.000"; kosong tetap kosong. */
    val typedDisplay: String get() = typed.reversed().chunked(3).joinToString(".").reversed()

    fun setCount(denomination: Int, qty: Int): CountInput =
        copy(counts = counts + (denomination to qty.coerceAtLeast(0)), typed = "")

    /** Hanya angka; angka nol di depan dibuang; dibatasi 9 digit (< 1 miliar, muat di Int dan di server). */
    fun type(raw: String): CountInput {
        val digits = raw.filter(Char::isDigit).trimStart('0').take(MAX_DIGITS)
        return copy(typed = digits, counts = if (digits.isNotEmpty()) emptyMap() else counts)
    }

    fun toCount(): ShiftCount = if (isTyped) ShiftCount.Typed(total) else ShiftCount.ByDenomination(counts)

    companion object {
        const val MAX_DIGITS = 9
    }
}
