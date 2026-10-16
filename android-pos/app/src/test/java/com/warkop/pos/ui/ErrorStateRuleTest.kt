package com.warkop.pos.ui

import com.warkop.core.domain.ApiError
import org.junit.Assert.assertEquals
import org.junit.Assert.assertFalse
import org.junit.Assert.assertTrue
import org.junit.Test

/** 048 / T039 — aturan keadaan gagal: skema usang, offline, dan pesan ramah. */
class ErrorStateRuleTest {
    @Test fun `skema server usang menampilkan Server perlu diperbarui`() {
        assertEquals("Server perlu diperbarui", friendlyLoadError(ApiError.SchemaOutdated("apa pun dari server")))
    }

    @Test fun `kegagalan jaringan menampilkan pesan ramah dengan petunjuk koneksi`() {
        val msg = friendlyLoadError(ApiError.Network())
        assertTrue(msg, msg.contains("Periksa koneksi"))
    }

    @Test fun `galat server biasa memakai pesannya, tanpa kosong`() {
        assertEquals("Terjadi gangguan di server. Coba lagi sebentar lagi.", friendlyLoadError(ApiError.Server()))
    }

    @Test fun `hanya kegagalan jaringan yang dianggap offline`() {
        assertTrue(isOfflineError(ApiError.Network()))
        assertFalse(isOfflineError(ApiError.SchemaOutdated("x")))
        assertFalse(isOfflineError(ApiError.Server()))
    }
}
