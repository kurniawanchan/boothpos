package com.warkop.pos.print

import org.junit.Assert.assertEquals
import org.junit.Assert.assertFalse
import org.junit.Assert.assertTrue
import org.junit.Test

/** 059 / T010 — baris raster memakai GS v 0 dengan byte yang benar; baris teks tidak berubah. */
class EscPosRasterTest {
    private fun ByteArray.contains(seq: ByteArray): Boolean =
        (0..size - seq.size).any { i -> seq.indices.all { j -> this[i + j] == seq[j] } }

    private val gsRasterHeader = byteArrayOf(0x1D, 'v'.code.toByte(), '0'.code.toByte(), 0)

    @Test fun `baris raster menulis header GS v 0 dengan jumlah byte yang benar`() {
        // 8 titik = 1 byte per baris; tinggi 1 titik; data 0xFF. Header: lebar 1 byte (LE), tinggi 1 titik (LE).
        val bytes = EscPos.render(listOf(PrintLine(raster = "ff", rasterWidthDots = 8, rasterHeightDots = 1)), cut = false)
        assertTrue(bytes.contains(gsRasterHeader + byteArrayOf(1, 0, 1, 0, 0xFF.toByte())))
    }

    @Test fun `lebar lebih dari 255 byte ditulis little endian`() {
        // 2048 titik = 256 byte per baris, 1 baris. 256 = 0x0100 → LE: 0x00, 0x01.
        val data = "00".repeat(256)
        val bytes = EscPos.render(listOf(PrintLine(raster = data, rasterWidthDots = 2048, rasterHeightDots = 1)), cut = false)
        assertTrue(bytes.contains(gsRasterHeader + byteArrayOf(0, 1, 1, 0)))
    }

    @Test fun `data yang tidak sesuai ukuran dilewati tanpa header`() {
        val bytes = EscPos.render(listOf(PrintLine(raster = "ffff", rasterWidthDots = 8, rasterHeightDots = 1)), cut = false)
        assertFalse(bytes.contains(gsRasterHeader))
    }

    @Test fun `baris teks tidak menulis header raster`() {
        val bytes = EscPos.render(listOf(PrintLine(text = "HALO")), cut = false)
        assertFalse(bytes.contains(gsRasterHeader))
        assertTrue(String(bytes, Charsets.US_ASCII).contains("HALO"))
    }

    @Test fun `baris teks sama seperti sebelum fitur raster`() {
        val bytes = EscPos.render(listOf(PrintLine(text = "Subtotal", right = "25.000")), cut = false)
        assertEquals(EscPos.COLUMNS, String(bytes, Charsets.US_ASCII).substringAfter("Subtotal").substringBefore('\n').length + "Subtotal".length)
    }
}
