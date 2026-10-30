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

/**
 * 084 — pemetaan posisi kursor antara digit murni ("1234567") dan tampilannya ("1.234.567"). Dulu kolom diberi String yang
 * SUDAH berformat; tiap ketikan menulis ulang teks berbeda dari yang diketik sehingga Compose menaruh kursor di awal setelah
 * lebih dari tiga digit. Sekarang kolom menyimpan digit murni dan pemisah ribuan hanya TAMPILAN (VisualTransformation) —
 * fungsi murni ini jadi pemetaan kursornya, supaya bisa diuji tanpa perangkat.
 */
object ThousandsOffsets {
    /** "1234567" → "1.234.567"; kosong tetap kosong. Sama dengan [CountInput.typedDisplay]. */
    fun format(digits: String): String = digits.reversed().chunked(3).joinToString(".").reversed()

    /** Posisi kursor di digit ([offset], 0..panjang) → posisi di teks berformat. */
    fun toTransformed(digits: String, offset: Int): Int {
        val n = digits.length
        val o = offset.coerceIn(0, n)
        if (o == 0) return 0
        val i = o - 1 // digit terakhir sebelum kursor
        val dotsBeforeDigit = (n - 1) / 3 - (n - 1 - i) / 3
        return i + dotsBeforeDigit + 1
    }

    /** Posisi kursor di teks berformat ([offset]) → posisi di digit: titik tidak dihitung. */
    fun toOriginal(digits: String, offset: Int): Int {
        val shown = format(digits)
        val t = offset.coerceIn(0, shown.length)
        return shown.take(t).count { it != '.' }
    }
}
