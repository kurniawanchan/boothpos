package com.warkop.pos.ui

import com.warkop.pos.model.MenuCategory
import com.warkop.pos.model.Tone
import org.junit.Assert.assertEquals
import org.junit.Test

/** 055 / T019 — pilihan kategori tetap valid setelah daftar server dimuat ulang (FR-003, FR-004). */
class CategoryStripTest {
    private val kopi = MenuCategory("2", "Kopi", "K", Tone.INK)
    private val camilan = MenuCategory("5", "Camilan", "C", Tone.NEUTRAL)

    @Test fun `kategori yang masih ada tetap dipilih`() {
        assertEquals("5", keepOrPickCategory(listOf(kopi, camilan), current = "5"))
    }

    @Test fun `kategori yang sudah hilang diganti kategori pertama`() {
        assertEquals("2", keepOrPickCategory(listOf(kopi, camilan), current = "99"))
    }

    @Test fun `tanpa pilihan dipilih kategori pertama`() {
        assertEquals("2", keepOrPickCategory(listOf(kopi, camilan), current = ""))
    }

    @Test fun `daftar kosong menghasilkan kosong`() {
        assertEquals("", keepOrPickCategory(emptyList(), current = "2"))
    }
}
