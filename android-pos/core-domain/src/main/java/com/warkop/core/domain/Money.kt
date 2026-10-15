package com.warkop.core.domain

import java.math.BigDecimal
import java.math.RoundingMode

/**
 * Uang di klien = Long rupiah. Server mengirim string desimal ("66000.00"); SATU fungsi ini mengubahnya,
 * supaya tidak ada pembulatan ganda di tempat lain. Klien TIDAK menjadi sumber kebenaran angka uang
 * (Konstitusi IV): angka dari server menimpa angka sementara di layar.
 */
object Money {
    /** null/kosong/tak valid → 0. Pecahan sen dibulatkan ke rupiah terdekat (HALF_UP). */
    fun parse(value: String?): Long {
        if (value.isNullOrBlank()) return 0L
        return try {
            BigDecimal(value.trim()).setScale(0, RoundingMode.HALF_UP).toLong()
        } catch (_: NumberFormatException) {
            0L
        }
    }

    /** Untuk menulis kembali ke server bila perlu (mis. nominal bayar): "66000.00". */
    fun toServer(rupiah: Long): String = BigDecimal(rupiah).setScale(2).toPlainString()
}
