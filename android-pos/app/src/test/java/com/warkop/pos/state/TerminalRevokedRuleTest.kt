package com.warkop.pos.state

import com.warkop.core.domain.ApiError
import org.junit.Assert.assertEquals
import org.junit.Assert.assertFalse
import org.junit.Assert.assertTrue
import org.junit.Test

/** 073 / FR-018 — mengenali pencabutan terminal dan memutuskan kapan tablet harus kembali ke penyiapan. */
class TerminalRevokedRuleTest {
    @Test fun `422 pada device_id berarti terminal dicabut`() {
        assertTrue(isTerminalRevoked(ApiError.Validation("x", mapOf("device_id" to listOf("dicabut")))))
    }

    @Test fun `422 pada kolom lain, dan galat lain, bukan pencabutan`() {
        assertFalse(isTerminalRevoked(ApiError.Validation("x", mapOf("pax" to listOf("salah")))))
        assertFalse(isTerminalRevoked(ApiError.Conflict("x", "shift_required")))
        assertFalse(isTerminalRevoked(ApiError.Network()))
        assertFalse(isTerminalRevoked(ApiError.TooMany("x", "pin_locked", null)))
    }

    @Test fun `saat mengerjakan order, pencabutan menunggu order selesai`() {
        listOf(Dest.EDITOR, Dest.TENDER, Dest.DONE).forEach { assertEquals(RevokedStep.FINISH_ORDER, revokedStepAt(it)) }
    }

    @Test fun `di luar alur order, pencabutan keluar sekarang`() {
        listOf(Dest.TABLES, Dest.ORDERS, Dest.SHIFT, Dest.OPEN_SHIFT, Dest.PIN).forEach { assertEquals(RevokedStep.EXIT_NOW, revokedStepAt(it)) }
    }

    @Test fun `setelah dicabut, keluar dari alur order berarti kembali ke penyiapan`() {
        assertTrue(exitsOnNavigate(true, Dest.TABLES))
        assertTrue(exitsOnNavigate(true, Dest.ORDERS))
    }

    @Test fun `setelah dicabut, berpindah di dalam alur order atau ke penyiapan tidak memicu keluar`() {
        assertFalse(exitsOnNavigate(true, Dest.TENDER))
        assertFalse(exitsOnNavigate(true, Dest.DONE))
        assertFalse(exitsOnNavigate(true, Dest.SETUP))
    }

    @Test fun `tanpa pencabutan navigasi tidak pernah memicu keluar`() {
        Dest.values().forEach { assertFalse(exitsOnNavigate(false, it)) }
    }
}
