package com.warkop.pos.state

import com.warkop.core.domain.ApiError
import com.warkop.pos.data.SessionRecovery
import org.junit.Assert.assertFalse
import org.junit.Assert.assertTrue
import org.junit.Test

/** 057 / T037 — sesi PIN yang kedaluwarsa dibuang sekali (FR-010); galat lain tidak memicu pembuangan. */
class SessionRecoveryTest {
    @Test fun `401 dengan token sesi membuang sesi`() {
        assertTrue(SessionRecovery.dropsSession(ApiError.Unauthorized("Sesi berakhir"), hasSession = true))
    }

    @Test fun `401 tanpa token sesi tidak membuang apa pun`() {
        assertFalse(SessionRecovery.dropsSession(ApiError.Unauthorized("Sesi berakhir"), hasSession = false))
    }

    @Test fun `galat jaringan tidak membuang sesi`() {
        assertFalse(SessionRecovery.dropsSession(ApiError.Network(), hasSession = true))
    }
}
