package com.warkop.pos.state

import com.warkop.core.network.ApiClient
import com.warkop.core.network.dto.HeartbeatResponseDto
import com.warkop.pos.data.HeartbeatMapper
import org.junit.Assert.assertEquals
import org.junit.Assert.assertFalse
import org.junit.Assert.assertNull
import org.junit.Assert.assertTrue
import org.junit.Test

/** 048 / US6 — pemetaan DTO heartbeat, aturan peringatan selisih jam (> 5 dtk), dan nilai terakhir saat gagal. */
class HeartbeatRulesTest {
    private fun dto(json: String) = ApiClient.json.decodeFromString(HeartbeatResponseDto.serializer(), json)

    @Test fun `pemetaan DTO membawa selisih jam dan rilis, dan rilis kosong menjadi null`() {
        val r = HeartbeatMapper.result(dto("""{"server_time_ms":1000,"skew_seconds":7,"menu_release":"  "}"""))
        assertEquals(1000L, r.serverTimeMs)
        assertEquals(7, r.skewSeconds)
        assertNull(r.menuRelease)
    }

    @Test fun `pemetaan DTO mempertahankan nama rilis`() {
        val r = HeartbeatMapper.result(dto("""{"server_time_ms":1,"skew_seconds":0,"menu_release":"v2026.10.1"}"""))
        assertEquals("v2026.10.1", r.menuRelease)
    }

    @Test fun `peringatan jam hanya di atas 5 detik, ke dua arah`() {
        assertFalse(skewWarns(null))
        assertFalse(skewWarns(0))
        assertFalse(skewWarns(5))
        assertFalse(skewWarns(-5))
        assertTrue(skewWarns(6))
        assertTrue(skewWarns(-6))
    }

    @Test fun `heartbeat gagal mempertahankan nilai terakhir yang baik`() {
        val ok = HeartbeatState().onSuccess(HeartbeatResult(serverTimeMs = 1000, skewSeconds = 4, menuRelease = "v1"), nowMs = 2000)
        val failed = ok.onFailure()
        assertFalse(failed.reachable)
        assertEquals(2000L, failed.lastOkAtMs)
        assertEquals(4, failed.skewSeconds)
        assertEquals("v1", failed.menuRelease)
    }
}
