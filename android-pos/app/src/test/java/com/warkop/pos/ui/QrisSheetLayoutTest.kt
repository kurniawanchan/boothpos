package com.warkop.pos.ui

import org.junit.Assert.assertEquals
import org.junit.Assert.assertNull
import org.junit.Test

/** 056 / T012 + T022 — ukuran judul dan total lembar QRIS (FR-003, FR-008). Lebar diberikan sebagai fungsi. */
class QrisSheetLayoutTest {
    // Lebar teks linier: 10 piksel per sp.
    private val widthAt: (Float) -> Int = { sp -> (sp * 10).toInt() }

    // ---- judul (T012) ----
    @Test fun `judul pendek memakai 30 sp`() {
        assertEquals(30f, titleSizeFor(widthAt, available = 300))
    }

    @Test fun `judul panjang mengecil`() {
        assertEquals(24f, titleSizeFor(widthAt, available = 240))
    }

    @Test fun `judul yang tidak muat walau di 22 sp mengembalikan null`() {
        assertNull(titleSizeFor(widthAt, available = 219))
    }

    @Test fun `lebar nol mengembalikan null`() {
        assertNull(titleSizeFor(widthAt, available = 0))
    }

    // ---- total (T022) ----
    @Test fun `total biasa memakai 40 sp`() {
        assertEquals(40f, totalSizeFor(widthAt, available = 400))
    }

    @Test fun `total besar mengecil`() {
        assertEquals(32f, totalSizeFor(widthAt, available = 320))
    }

    @Test fun `total tidak muat walau di 28 sp memakai 28 sp sebagai batas terkecil`() {
        assertEquals(28f, totalSizeFor(widthAt, available = 100))
    }
}
