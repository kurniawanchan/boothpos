package com.warkop.pos.ui

import org.junit.Assert.assertEquals
import org.junit.Assert.assertNull
import org.junit.Test

/** 054 / US1 — pemilihan ukuran huruf chip nama outlet (FR-001, FR-002). Lebar diberikan sebagai fungsi, jadi tanpa Compose. */
class ChipFitTest {
    private val sizes = listOf(14f, 13f, 12f)
    // Lebar teks meningkat linier dengan ukuran huruf: 10 piksel per sp.
    private val widthAt: (Float) -> Int = { size -> (size * 10).toInt() }

    @Test fun `ukuran terbesar dipakai bila sudah muat`() {
        assertEquals(14f, chooseFontSize(widthAt, available = 140, sizes = sizes))
    }

    @Test fun `mengecil ke ukuran berikutnya bila 14sp tidak muat`() {
        assertEquals(13f, chooseFontSize(widthAt, available = 135, sizes = sizes))
    }

    @Test fun `mengecil sampai batas terkecil`() {
        assertEquals(12f, chooseFontSize(widthAt, available = 120, sizes = sizes))
    }

    @Test fun `tepat pas dihitung muat`() {
        assertEquals(12f, chooseFontSize(widthAt, available = 120, sizes = listOf(12f)))
    }

    @Test fun `tidak ada ukuran yang muat berarti pindahkan chip`() {
        assertNull(chooseFontSize(widthAt, available = 119, sizes = sizes))
    }

    @Test fun `ruang nol atau negatif berarti pindahkan chip`() {
        assertNull(chooseFontSize(widthAt, available = 0, sizes = sizes))
        assertNull(chooseFontSize(widthAt, available = -5, sizes = sizes))
    }

    @Test fun `daftar ukuran kosong berarti pindahkan chip`() {
        assertNull(chooseFontSize(widthAt, available = 500, sizes = emptyList()))
    }
}
