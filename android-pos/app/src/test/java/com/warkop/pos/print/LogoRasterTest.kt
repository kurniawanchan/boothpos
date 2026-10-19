package com.warkop.pos.print

import org.junit.Assert.assertEquals
import org.junit.Assert.assertFalse
import org.junit.Assert.assertNull
import org.junit.Assert.assertTrue
import org.junit.Test

/** 059 / T009 — konversi logo ke raster 1-bit: lebar dibatasi 384, aspek dijaga, tinggi maksimum 96. */
class LogoRasterTest {
    private val white = 0xFFFFFFFF.toInt()
    private val black = 0xFF000000.toInt()

    private fun solid(w: Int, h: Int, color: Int) = IntArray(w * h) { color }

    @Test fun `lebar dibatasi 384 titik`() {
        val line = Logo.toRaster(800, 200, solid(800, 200, white))!!
        assertEquals(384, line.rasterWidthDots)
    }

    @Test fun `aspek gambar dijaga saat diperkecil`() {
        // 800x200 (4:1) menjadi 384x96 (4:1).
        val line = Logo.toRaster(800, 200, solid(800, 200, white))!!
        assertEquals(96, line.rasterHeightDots)
    }

    @Test fun `gambar putih menghasilkan semua bit nol`() {
        val line = Logo.toRaster(16, 4, solid(16, 4, white))!!
        assertTrue(line.raster!!.all { it == '0' })
    }

    @Test fun `gambar hitam menghasilkan semua bit satu`() {
        val line = Logo.toRaster(8, 2, solid(8, 2, black))!!
        assertEquals("ffff", line.raster)
    }

    @Test fun `panjang hex sama dengan baris kali byte per baris kali dua`() {
        // 10 titik lebar = 2 byte per baris; 3 baris; 2 karakter hex per byte.
        val line = Logo.toRaster(10, 3, solid(10, 3, black))!!
        assertEquals(2 * 3 * 2, line.raster!!.length)
    }

    @Test fun `tinggi di atas 96 ditolak oleh pemeriksaan ukuran`() {
        assertFalse(Logo.isRasterFit(10, 97))
        assertTrue(Logo.isRasterFit(384, 96))
    }

    @Test fun `gambar yang terlalu tinggi diperkecil sampai batas 96`() {
        val line = Logo.toRaster(40, 400, solid(40, 400, white))!!
        assertTrue(line.rasterHeightDots <= 96)
    }

    @Test fun `ukuran atau piksel tidak valid menghasilkan null`() {
        assertNull(Logo.toRaster(0, 0, IntArray(0)))
        assertNull(Logo.toRaster(4, 4, IntArray(3)))
    }

    @Test fun `piksel transparan dihitung putih`() {
        val transparent = 0x00000000
        val line = Logo.toRaster(8, 1, solid(8, 1, transparent))!!
        assertEquals("00", line.raster)
    }
}
