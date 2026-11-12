package com.boothpos.customer.core.ui.format

import java.math.BigDecimal
import java.math.RoundingMode

/**
 * Format Rupiah gaya id-ID: `Rp15.000`; desimal hanya ditampilkan bila bukan `,00`.
 *
 * Ditulis tangan (bukan NumberFormat) supaya hasilnya sama di semua perangkat dan versi ICU:
 * keluaran NumberFormat untuk id-ID berbeda antar versi Android (spasi tak-putus, `Rp` vs `IDR`).
 * Hanya untuk TAMPILAN; angka resminya selalu dari server.
 */
object RupiahFormatter {

    fun format(amount: BigDecimal): String {
        val scaled = amount.setScale(2, RoundingMode.HALF_UP)
        val absolute = scaled.abs()
        val whole = absolute.toBigInteger()
        val cents = absolute.subtract(BigDecimal(whole)).movePointRight(2).intValueExact()
        val grouped = whole.toString().reversed().chunked(3).joinToString(".").reversed()
        return buildString {
            if (scaled.signum() < 0) append('-')
            append("Rp").append(grouped)
            if (cents != 0) append(',').append(cents.toString().padStart(2, '0'))
        }
    }

    /** Untuk string desimal dari API (`"15000.00"`). Null bila bukan angka yang sah. */
    fun formatOrNull(raw: String?): String? =
        raw?.trim()?.takeIf { it.isNotEmpty() }?.toBigDecimalOrNull()?.let(::format)
}
