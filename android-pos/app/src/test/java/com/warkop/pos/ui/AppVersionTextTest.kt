package com.warkop.pos.ui

import org.junit.Assert.assertEquals
import org.junit.Test

/** 085 / US1 — sel versi di layar Koneksi: versi + penanda pembangunan, dan penjelasan jujur bila penanda tidak ada. */
class AppVersionTextTest {
    @Test fun `memuat versi dan penanda pembangunan`() {
        assertEquals(Triple("Versi aplikasi", "1.0.0", "Build a1b2c3d 2026-10-30"), appVersionCell("1.0.0", "a1b2c3d 2026-10-30"))
    }

    @Test fun `penanda dev atau kosong dijelaskan, tidak disembunyikan`() {
        assertEquals("Pembangunan tanpa penanda git (dev)", appVersionCell("1.0.0", "dev").third)
        assertEquals("Pembangunan tanpa penanda git (dev)", appVersionCell("1.0.0", "  ").third)
    }
}
