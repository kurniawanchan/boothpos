package com.boothpos.customer.core.ui.format

import java.math.BigDecimal
import org.junit.Assert.assertEquals
import org.junit.Assert.assertNull
import org.junit.Test

class RupiahFormatterTest {

    @Test
    fun `nominal bulat tanpa desimal dan dengan pemisah ribuan titik`() {
        assertEquals("Rp15.000", RupiahFormatter.format(BigDecimal("15000.00")))
        assertEquals("Rp0", RupiahFormatter.format(BigDecimal.ZERO))
        assertEquals("Rp999", RupiahFormatter.format(BigDecimal("999")))
        assertEquals("Rp1.000", RupiahFormatter.format(BigDecimal("1000")))
        assertEquals("Rp1.250.000", RupiahFormatter.format(BigDecimal("1250000.00")))
        assertEquals("Rp12.345.678.901", RupiahFormatter.format(BigDecimal("12345678901.00")))
    }

    @Test
    fun `desimal hanya tampil bila bukan nol dan memakai koma`() {
        assertEquals("Rp15.000,50", RupiahFormatter.format(BigDecimal("15000.50")))
        assertEquals("Rp15.000,05", RupiahFormatter.format(BigDecimal("15000.05")))
        assertEquals("Rp0,99", RupiahFormatter.format(BigDecimal("0.99")))
    }

    @Test
    fun `lebih dari dua desimal dibulatkan setengah ke atas`() {
        assertEquals("Rp10,13", RupiahFormatter.format(BigDecimal("10.125")))
        assertEquals("Rp11", RupiahFormatter.format(BigDecimal("10.999")))
    }

    @Test
    fun `nilai negatif diberi tanda minus di depan`() {
        assertEquals("-Rp2.500", RupiahFormatter.format(BigDecimal("-2500.00")))
        assertEquals("Rp0", RupiahFormatter.format(BigDecimal("-0.00")))
    }

    @Test
    fun `string dari API diformat dan masukan tidak sah menjadi null`() {
        assertEquals("Rp150.000", RupiahFormatter.formatOrNull("150000.00"))
        assertEquals("Rp150.000", RupiahFormatter.formatOrNull(" 150000.00 "))
        assertNull(RupiahFormatter.formatOrNull(null))
        assertNull(RupiahFormatter.formatOrNull(""))
        assertNull(RupiahFormatter.formatOrNull("Rp 15rb"))
    }
}
