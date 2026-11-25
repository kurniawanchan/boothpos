package com.warkop.manager.domain

import com.warkop.core.domain.Money
import java.text.DecimalFormat
import java.text.DecimalFormatSymbols
import java.util.Locale

/** 094 — SATU pemformat angka tampilan aplikasi manajemen. Hanya format; angka selalu dari server (Konstitusi IV). */
object Format {
    private val ID = DecimalFormatSymbols(Locale("in", "ID"))

    /** "1250000.00" → "Rp 1.250.000" (pembulatan sekali, di [Money.parse]). */
    fun rupiah(value: String?): String = "Rp " + count(Money.parse(value))

    fun count(n: Long): String = DecimalFormat("#,##0", ID).format(n)

    /** Jumlah desimal server ("2.500") → "2,5"; maksimal 3 desimal, tanpa nol di belakang. */
    fun qty(value: String?): String = value?.toBigDecimalOrNull()?.let { DecimalFormat("#,##0.###", ID).format(it) } ?: "—"

    fun percent1(v: Double): String = DecimalFormat("#,##0.0", ID).format(v)
}
