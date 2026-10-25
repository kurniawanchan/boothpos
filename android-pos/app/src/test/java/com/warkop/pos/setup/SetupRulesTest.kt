package com.warkop.pos.setup

import com.warkop.pos.data.OutletChoice
import com.warkop.pos.data.TerminalChoice
import org.junit.Assert.assertEquals
import org.junit.Assert.assertFalse
import org.junit.Assert.assertNull
import org.junit.Assert.assertTrue
import org.junit.Test

/** 073 / US1 — aturan layar penyiapan: kolom Terminal hanya setelah outlet dipilih, nama yang diketik menang, instalasi satu outlet. */
class SetupRulesTest {
    private val depan = OutletChoice(1, "WJM 3")
    private val kasir = TerminalChoice(7, "Kasir Depan")

    @Test fun `kolom Terminal tidak tampil sebelum outlet dipilih`() {
        assertFalse(SetupRules.showTerminalField(null))
        assertTrue(SetupRules.showTerminalField(depan))
    }

    @Test fun `tanpa outlet tidak bisa lanjut walau nama sudah diketik`() {
        assertFalse(SetupRules.canSubmit(null, "Kasir Baru", null))
        assertFalse(SetupRules.canSubmit(null, "", kasir))
    }

    @Test fun `nama kosong atau hanya spasi tidak bisa dikirim`() {
        assertFalse(SetupRules.canSubmit(depan, "", null))
        assertFalse(SetupRules.canSubmit(depan, "   ", null))
        assertEquals("", SetupRules.effectiveName("   ", null))
    }

    @Test fun `terminal yang dipilih dari daftar bisa dikirim tanpa mengetik`() {
        assertTrue(SetupRules.canSubmit(depan, "", kasir))
        assertEquals("Kasir Depan", SetupRules.effectiveName("", kasir))
    }

    @Test fun `nama yang diketik dipangkas dan menang atas pilihan walau outlet sudah punya terminal`() {
        assertEquals("Kasir Belakang", SetupRules.effectiveName("  Kasir Belakang  ", kasir))
        assertTrue(SetupRules.canSubmit(depan, "Kasir Belakang", kasir))
    }

    @Test fun `satu outlet langsung terpilih, beberapa outlet tidak`() {
        assertEquals(depan, SetupRules.preselect(listOf(depan)))
        assertNull(SetupRules.preselect(listOf(depan, OutletChoice(2, "WJM 2"))))
        assertNull(SetupRules.preselect(emptyList()))
    }
}
