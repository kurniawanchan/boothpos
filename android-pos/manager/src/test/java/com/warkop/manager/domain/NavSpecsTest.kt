package com.warkop.manager.domain

import org.junit.Assert.assertEquals
import org.junit.Assert.assertNull
import org.junit.Assert.assertTrue
import org.junit.Test

class NavSpecsTest {
    @Test fun `menu tanpa kunci menu tidak tampil`() {
        val visible = NavSpecs.visible(setOf("dashboard", "products", "stock", "pos", "floor"))
        assertEquals(listOf(Dest.DASHBOARD, Dest.PRODUCTS, Dest.STOCK), visible.map { it.dest })
    }

    @Test fun `lantai dan tab tidak pernah menjadi menu walau kunci floor dipegang`() {
        assertTrue(NavSpecs.ALL.none { it.menuKey == "floor" })
    }

    @Test fun `tanpa kunci yang dikenal tidak ada menu dan tidak ada tujuan awal`() {
        assertTrue(NavSpecs.visible(setOf("pos", "session")).isEmpty())
        assertNull(NavSpecs.start(setOf("pos")))
    }

    @Test fun `tujuan awal dashboard bila boleh selain itu menu pertama yang boleh`() {
        assertEquals(Dest.DASHBOARD, NavSpecs.start(setOf("stock", "dashboard")))
        assertEquals(Dest.STOCK, NavSpecs.start(setOf("stock", "vendors")))
    }
}
