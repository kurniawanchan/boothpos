package com.warkop.core.network

import com.warkop.core.network.dto.HeartbeatRequestDto
import com.warkop.core.network.dto.HeartbeatResponseDto
import org.junit.Assert.assertEquals
import org.junit.Assert.assertNull
import org.junit.Test

/** 048 / US6 — bentuk kawat heartbeat sesuai kontrak: body {app_version, clock_ms, queue_depth}. */
class HeartbeatDtosTest {
    private val json = ApiClient.json

    @Test fun `permintaan heartbeat membawa versi, jam perangkat, dan antrean`() {
        val body = json.encodeToString(HeartbeatRequestDto.serializer(), HeartbeatRequestDto(appVersion = "1.0.0", clockMs = 1792125401349L, queueDepth = 0))
        assertEquals("{\"app_version\":\"1.0.0\",\"clock_ms\":1792125401349,\"queue_depth\":0}", body)
    }

    @Test fun `respons heartbeat membaca selisih jam negatif dan rilis kosong`() {
        val r = json.decodeFromString(HeartbeatResponseDto.serializer(), """{"server_time_ms":1792125401000,"skew_seconds":-3,"menu_release":null}""")
        assertEquals(1792125401000L, r.serverTimeMs)
        assertEquals(-3, r.skewSeconds)
        assertNull(r.menuRelease)
    }

    @Test fun `respons heartbeat membaca nama rilis menu`() {
        val r = json.decodeFromString(HeartbeatResponseDto.serializer(), """{"server_time_ms":1,"skew_seconds":0,"menu_release":"v2026.10.1"}""")
        assertEquals("v2026.10.1", r.menuRelease)
    }
}
