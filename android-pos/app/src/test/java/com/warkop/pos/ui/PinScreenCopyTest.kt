package com.warkop.pos.ui

import com.warkop.pos.model.StaffUser
import org.junit.Assert.assertEquals
import org.junit.Assert.assertNull
import org.junit.Test

/** 078 / US2 — teks alasan dan aturan pilihan awal layar PIN, dipisah dari Compose supaya bisa diuji. */
class PinScreenCopyTest {
    private val sari = StaffUser("1", "Sari", "Kasir", "CSH", photoUrl = "http://x/lama.jpg")
    private val budi = StaffUser("2", "Budi", "Kasir", "CSH")

    @Test fun `alasan staf tanpa PIN`() {
        assertEquals("Belum ada PIN", NO_PIN_REASON)
    }

    @Test fun `pilihan tetap dan dimuat ulang dengan data terbaru saat staf berubah`() {
        val fresh = sari.copy(photoUrl = "http://x/baru.jpg")
        assertEquals(fresh, selectedAfterRosterChange(sari, listOf(fresh, budi)))
    }

    @Test fun `pilihan hilang dari daftar jatuh ke staf pertama yang bisa masuk`() {
        assertEquals(budi, selectedAfterRosterChange(sari, listOf(budi)))
    }

    @Test fun `tanpa pilihan awal memilih staf pertama`() {
        assertEquals(sari, selectedAfterRosterChange(null, listOf(sari, budi)))
    }

    @Test fun `tidak ada staf yang bisa masuk berarti tidak ada pilihan`() {
        assertNull(selectedAfterRosterChange(sari, emptyList()))
        assertNull(selectedAfterRosterChange(null, emptyList()))
    }
}
