package com.warkop.pos.ui

import com.warkop.pos.model.SystemMode
import org.junit.Assert.assertEquals
import org.junit.Assert.assertFalse
import org.junit.Assert.assertTrue
import org.junit.Test

/** 077 / US2 — aturan murni identitas toko: nama bawaan, kapan nama outlet tampil, kapan lencana DEMO tampil, dan inisial pengganti logo. */
class StoreMarkRuleTest {
    @Test fun `nama toko kosong atau spasi memakai nama bawaan`() {
        assertEquals(DEFAULT_STORE_NAME, storeTitle(null))
        assertEquals(DEFAULT_STORE_NAME, storeTitle("   "))
        assertEquals("Warkop WJM", storeTitle("  Warkop WJM "))
    }

    @Test fun `nama outlet hanya tampil bila lebih dari satu outlet`() {
        assertFalse(showOutletName(null))
        assertFalse(showOutletName(0))
        assertFalse(showOutletName(1))
        assertTrue(showOutletName(2))
    }

    @Test fun `lencana hanya untuk DEMO`() {
        assertTrue(showModeBadge(SystemMode.DEMO))
        assertFalse(showModeBadge(SystemMode.LIVE))
    }

    @Test fun `inisial pengganti logo dari dua kata pertama nama toko`() {
        assertEquals("WW", initialsOf("Warkop WJM"))
        assertEquals("K", initialsOf("Kopi"))
        assertEquals("?", initialsOf(""))
    }

    @Test fun `mode tak dikenal dari server dianggap LIVE`() {
        assertEquals(SystemMode.LIVE, SystemMode.parse(null))
        assertEquals(SystemMode.LIVE, SystemMode.parse("apa-saja"))
        assertEquals(SystemMode.DEMO, SystemMode.parse("DEMO"))
        assertEquals(SystemMode.DEMO, SystemMode.parse("demo"))
    }
}
