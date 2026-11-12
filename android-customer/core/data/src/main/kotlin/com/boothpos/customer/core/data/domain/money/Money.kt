package com.boothpos.customer.core.data.domain.money

import java.math.BigDecimal
import java.math.RoundingMode

/**
 * Uang datang dari API sebagai string desimal (`"15000.00"`) dan diurai SEKALI di mapper menjadi
 * BigDecimal berskala 2. Tidak pernah Double.
 */
object Money {
    val ZERO: BigDecimal = BigDecimal("0.00")

    private val pattern = Regex("""-?\d{1,15}(\.\d{1,4})?""")

    /** Null bila bukan angka desimal polos (tanpa eksponen, pemisah ribuan, atau simbol). */
    fun parse(raw: String?): BigDecimal? =
        raw?.trim()?.takeIf(pattern::matches)?.toBigDecimalOrNull()?.setScale(2, RoundingMode.HALF_UP)

    fun parseOrZero(raw: String?): BigDecimal = parse(raw) ?: ZERO

    /** Bentuk kawat (`"150000.00"`), mis. untuk `expected_total` saat checkout. */
    fun toWire(amount: BigDecimal): String = amount.setScale(2, RoundingMode.HALF_UP).toPlainString()
}
