package com.warkop.pos.state

import org.junit.Assert.assertEquals
import org.junit.Test

/** 084 / US3 — pemetaan kursor digit ↔ tampilan berpemisah ribuan ("1.234.567"). */
class CountInputOffsetTest {
    @Test fun `format memberi titik tiap tiga digit dari kanan`() {
        assertEquals("", ThousandsOffsets.format(""))
        assertEquals("999", ThousandsOffsets.format("999"))
        assertEquals("1.000", ThousandsOffsets.format("1000"))
        assertEquals("1.234.567", ThousandsOffsets.format("1234567"))
        assertEquals("123.456.789", ThousandsOffsets.format("123456789"))
    }

    @Test fun `kursor di akhir digit tetap di akhir tampilan`() {
        for (digits in listOf("1", "12", "123", "1234", "12345", "123456", "1234567", "123456789")) {
            assertEquals(digits, ThousandsOffsets.format(digits).replace(".", ""))
            assertEquals(ThousandsOffsets.format(digits).length, ThousandsOffsets.toTransformed(digits, digits.length))
        }
    }

    @Test fun `kursor di awal tetap di awal`() {
        assertEquals(0, ThousandsOffsets.toTransformed("1234567", 0))
        assertEquals(0, ThousandsOffsets.toOriginal("1234567", 0))
    }

    @Test fun `kursor di tengah memperhitungkan titik yang disisipkan`() {
        // "1234567" -> "1.234.567": sesudah digit ke-1 kursor di 1; sesudah digit ke-2 melewati titik -> 3.
        assertEquals(1, ThousandsOffsets.toTransformed("1234567", 1))
        assertEquals(3, ThousandsOffsets.toTransformed("1234567", 2))
        assertEquals(4, ThousandsOffsets.toTransformed("1234567", 3))
        assertEquals(5, ThousandsOffsets.toTransformed("1234567", 4))
        assertEquals(7, ThousandsOffsets.toTransformed("1234567", 5))
    }

    @Test fun `pemetaan balik tidak menghitung titik dan konsisten dengan pemetaan maju`() {
        val digits = "1234567"
        for (o in 0..digits.length) {
            assertEquals(o, ThousandsOffsets.toOriginal(digits, ThousandsOffsets.toTransformed(digits, o)))
        }
        // Kursor tepat sebelum/sesudah titik jatuh pada batas digit yang sama.
        assertEquals(1, ThousandsOffsets.toOriginal(digits, 1))
        assertEquals(1, ThousandsOffsets.toOriginal(digits, 2))
        assertEquals(2, ThousandsOffsets.toOriginal(digits, 3))
    }

    @Test fun `nilai di luar batas dijepit`() {
        assertEquals(ThousandsOffsets.format("12345").length, ThousandsOffsets.toTransformed("12345", 99))
        assertEquals(0, ThousandsOffsets.toTransformed("12345", -3))
        assertEquals(5, ThousandsOffsets.toOriginal("12345", 99))
    }

    @Test fun `tempel angka berformat jadi digit murni lewat type`() {
        val typed = CountInput().type("1.500.000")
        assertEquals("1500000", typed.typed)
        assertEquals(1_500_000, typed.total)
    }

    @Test fun `menghapus digit di tengah menghasilkan digit murni tanpa nol di depan`() {
        // "1.234.567" ditampilkan dari "1234567"; hapus digit pertama -> raw "234567".
        assertEquals("234.567", CountInput().type("234567").typedDisplay)
        assertEquals("", CountInput().type("0").typed)
    }
}
