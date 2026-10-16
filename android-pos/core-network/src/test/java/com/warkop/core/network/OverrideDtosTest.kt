package com.warkop.core.network

import com.warkop.core.network.dto.ApproverDto
import com.warkop.core.network.dto.CashMovementRequest
import com.warkop.core.network.dto.CloseSessionRequest
import com.warkop.core.network.dto.DenominationQtyDto
import com.warkop.core.network.dto.OverrideRequest
import com.warkop.core.network.dto.OverrideResponse
import kotlinx.serialization.builtins.ListSerializer
import kotlinx.serialization.json.buildJsonObject
import kotlinx.serialization.json.put
import org.junit.Assert.*
import org.junit.Test

/** 048 / US4 — bentuk kawat persetujuan. Kontrak: klien kirim `subject`, server menghitung hash-nya. */
class OverrideDtosTest {
    private val json = ApiClient.json

    @Test fun `permintaan persetujuan membawa subject apa adanya dan pin`() {
        val subject = buildJsonObject { put("direction", "out"); put("reason_code", "supplier"); put("amount", 250000) }
        val body = json.encodeToString(OverrideRequest.serializer(), OverrideRequest("cash_out", subject, 3, "123456"))
        assertTrue(body, body.contains("\"action\":\"cash_out\""))
        assertTrue(body, body.contains("\"subject\":{\"direction\":\"out\",\"reason_code\":\"supplier\",\"amount\":250000}"))
        assertTrue(body, body.contains("\"approver_user_id\":3"))
        assertTrue(body, body.contains("\"pin\":\"123456\""))
        assertFalse("klien tidak boleh mengirim hash", body.contains("subject_hash"))
    }

    @Test fun `daftar penyetuju terbaca tanpa peminta`() {
        val raw = """[{"id":3,"name":"Dewi","role":"Supervisor"},{"id":5,"name":"Budi","role":"Outlet Manager"}]"""
        val list = json.decodeFromString(ListSerializer(ApproverDto.serializer()), raw)
        assertEquals(2, list.size)
        assertEquals("Supervisor", list[0].role)
        assertEquals(5L, list[1].id)
    }

    @Test fun `jawaban persetujuan membawa token dan waktu habis`() {
        val r = json.decodeFromString(OverrideResponse.serializer(), """{"override_token":"tok-1","expires_at":"2026-10-16T10:00:00+07:00"}""")
        assertEquals("tok-1", r.overrideToken)
        assertEquals("2026-10-16T10:00:00+07:00", r.expiresAt)
    }

    @Test fun `kas keluar tanpa token tidak mengirim override_token`() {
        val body = json.encodeToString(CashMovementRequest.serializer(), CashMovementRequest(direction = "out", reasonCode = "petty_cash", amount = 20000))
        assertFalse(body, body.contains("override_token"))
    }

    @Test fun `kas keluar dengan token membawa override_token`() {
        val body = json.encodeToString(CashMovementRequest.serializer(), CashMovementRequest(direction = "out", reasonCode = "supplier", amount = 250000, overrideToken = "tok-2"))
        assertTrue(body, body.contains("\"override_token\":\"tok-2\""))
    }

    @Test fun `tutup shift dengan token selisih membawa variance_override_token`() {
        val body = json.encodeToString(CloseSessionRequest.serializer(), CloseSessionRequest(
            countedByDenomination = listOf(DenominationQtyDto(100000, 2)), varianceOverrideToken = "tok-3",
        ))
        assertTrue(body, body.contains("\"variance_override_token\":\"tok-3\""))
        val plain = json.encodeToString(CloseSessionRequest.serializer(), CloseSessionRequest(countedByDenomination = emptyList()))
        assertFalse(plain, plain.contains("variance_override_token"))
    }
}
