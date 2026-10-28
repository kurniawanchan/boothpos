package com.warkop.pos.ui

import com.warkop.pos.model.StaffUser
import org.junit.Assert.assertEquals
import org.junit.Assert.assertFalse
import org.junit.Assert.assertTrue
import org.junit.Test

/**
 * 078 / US2 — satu baris per ORANG: tiap id muncul tepat sekali (tidak ada "nama ganda" seperti blok pengguna terpilih 077), baris
 * terpilih adalah baris yang DIPERBESAR, dan staf tanpa PIN tampil redup tanpa bisa dipilih.
 */
class StaffRowsRuleTest {
    private val sari = StaffUser("1", "Sari", "Kasir", "CSH")
    private val budi = StaffUser("2", "Budi", "Kasir", "CSH")
    private val tanpaPin = StaffUser("3", "Rina", "Kasir", "CSH", hasPin = false)

    @Test fun `setiap id muncul tepat sekali`() {
        val rows = staffRows(listOf(sari, budi, tanpaPin), selectedId = "1")
        assertEquals(listOf("1", "2", "3"), rows.map { it.user.id })
    }

    @Test fun `hanya baris terpilih yang diperbesar`() {
        val rows = staffRows(listOf(sari, budi), selectedId = "2")
        assertEquals(listOf(false, true), rows.map { it.expanded })
    }

    @Test fun `dua orang bernama sama tetap dua baris`() {
        val kembar = StaffUser("9", "Sari", "Supervisor", "SPV")
        val rows = staffRows(listOf(sari, kembar), selectedId = "1")
        assertEquals(2, rows.size)
        assertEquals(listOf("Kasir", "Supervisor"), rows.map { it.user.role })
        assertEquals(listOf(true, false), rows.map { it.expanded })
    }

    @Test fun `staf tanpa PIN tidak bisa dipilih dan tidak pernah diperbesar`() {
        val rows = staffRows(listOf(sari, tanpaPin), selectedId = "3")
        assertFalse(rows[1].selectable)
        assertFalse(rows[1].expanded)
        assertTrue(rows[0].selectable)
    }

    @Test fun `tanpa pilihan atau daftar kosong`() {
        assertEquals(listOf(false, false), staffRows(listOf(sari, budi), selectedId = null).map { it.expanded })
        assertTrue(staffRows(emptyList(), selectedId = "1").isEmpty())
    }
}
