package com.warkop.pos.ui

import com.warkop.pos.state.Dest
import com.warkop.pos.state.RailPreference
import org.junit.Assert.assertEquals
import org.junit.Assert.assertFalse
import org.junit.Assert.assertTrue
import org.junit.Test

/** 084 / US5 — urutan menu, jam berdetak, dan pilihan sidebar. */
class NavAndClockTest {
    @Test fun `menu berurutan Kasir Meja Pesanan Shift Koneksi`() {
        // 2026-11-01 — "Bahan" (Stok Bahan, hanya baca) di antara Shift dan Koneksi.
        assertEquals(listOf("Kasir", "Meja", "Pesanan", "Shift", "Bahan", "Koneksi"), NAV_SPECS.map { it.label })
        assertEquals(listOf(Dest.EDITOR, Dest.TABLES, Dest.ORDERS, Dest.SHIFT, Dest.MATERIALS, Dest.SYNC), NAV_SPECS.map { it.dest })
    }

    @Test fun `tidak ada istilah lama di menu`() {
        val labels = NAV_SPECS.map { it.label }
        assertFalse(labels.any { it in listOf("Counter", "Order", "Perangkat") })
    }

    @Test fun `jeda sampai batas menit berikutnya`() {
        assertEquals(60_000L, nextTickDelayMs(0L, 60_000L))
        assertEquals(1L, nextTickDelayMs(59_999L, 60_000L))
        assertEquals(30_000L, nextTickDelayMs(30_000L, 60_000L))
        assertEquals(5_000L, nextTickDelayMs(1_015_000L, 60_000L)) // 1.015.000 mod 60.000 = 55.000
    }

    @Test fun `jeda selalu antara satu milidetik dan satu langkah`() {
        for (now in listOf(0L, 1L, 59_999L, 60_000L, 123_456_789L, -1L)) {
            val d = nextTickDelayMs(now, 60_000L)
            assertTrue("now=$now d=$d", d in 1L..60_000L)
        }
    }

    @Test fun `jam berubah ketika waktu melewati batas menit`() {
        val tz = java.util.TimeZone.getDefault()
        val base = 1_700_000_000_000L - Math.floorMod(1_700_000_000_000L, 60_000L) // awal sebuah menit
        assertEquals(clockText(base), clockText(base + 59_999L))
        assertTrue(clockText(base) != clockText(base + 60_000L))
        assertTrue(tz.id.isNotEmpty())
    }

    @Test fun `format jam dua digit`() {
        assertTrue(Regex("\\d\\d:\\d\\d").matches(clockText(System.currentTimeMillis())))
    }

    @Test fun `sidebar bawaannya tampil dan pilihan tersimpan`() {
        var stored: Boolean? = null
        val pref = RailPreference({ stored }, { stored = it })
        assertTrue(pref.visible)
        pref.visible = false
        assertFalse(pref.visible)
        assertEquals(false, stored)
        pref.visible = true
        assertTrue(pref.visible)
    }
}
