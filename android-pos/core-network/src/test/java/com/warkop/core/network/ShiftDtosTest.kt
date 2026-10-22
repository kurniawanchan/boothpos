package com.warkop.core.network

import com.warkop.core.network.dto.CashMovementDto
import com.warkop.core.network.dto.CashMovementRequest
import com.warkop.core.network.dto.ClosedSessionDto
import com.warkop.core.network.dto.CloseSessionRequest
import com.warkop.core.network.dto.DenominationQtyDto
import com.warkop.core.network.dto.FeaturesDto
import com.warkop.core.network.dto.OpenSessionRequest
import com.warkop.core.network.dto.XReportDto
import kotlinx.serialization.json.jsonPrimitive
import org.junit.Assert.*
import org.junit.Test

/** 048 / US3 — bentuk kawat shift & kas. Uji ini menjaga kontrak server: apa yang dikirim dan apa yang dibaca. */
class ShiftDtosTest {
    private val json = ApiClient.json

    @Test fun `buka shift dengan pecahan tidak mengirim opening_cash`() {
        val body = json.encodeToString(OpenSessionRequest.serializer(), OpenSessionRequest(
            openingDenominations = listOf(DenominationQtyDto(100000, 1), DenominationQtyDto(50000, 1)),
        ))
        assertTrue(body, body.contains("\"opening_denominations\":[{\"denomination\":100000,\"qty\":1},{\"denomination\":50000,\"qty\":1}]"))
        assertFalse(body, body.contains("opening_cash"))
    }

    @Test fun `buka shift bentuk lama tetap mengirim opening_cash saja`() {
        val body = json.encodeToString(OpenSessionRequest.serializer(), OpenSessionRequest(openingCash = 150000))
        assertEquals("{\"opening_cash\":150000}", body)
    }

    @Test fun `hitungan buta dikirim sebagai pecahan dan edc kosong dihilangkan`() {
        val body = json.encodeToString(CloseSessionRequest.serializer(), CloseSessionRequest(
            countedByDenomination = listOf(DenominationQtyDto(50000, 3), DenominationQtyDto(2000, 4)),
        ))
        assertEquals("{\"counted_by_denomination\":[{\"denomination\":50000,\"qty\":3},{\"denomination\":2000,\"qty\":4}]}", body)
    }

    @Test fun `total ketikan dikirim sebagai counted_total tanpa pecahan dan tanpa EDC`() {
        val body = json.encodeToString(CloseSessionRequest.serializer(), CloseSessionRequest(countedTotal = 250000))
        assertEquals("{\"counted_total\":250000}", body)
        assertTrue(body, !body.contains("edc"))
    }

    @Test fun `x-report membaca jam dan metode dengan uang sebagai string`() {
        val raw = """{"generated_at":"2026-10-15T10:00:00+07:00","session_id":7,"status":"open","opening_cash":"150000.00",
            "order_count":2,"total_sales":"36000.00","void_count":0,"void_total":"0.00","change_given":"50000.00",
            "cash_received":"250000.00","cash_in":"0.00","cash_out":"20000.00",
            "by_method":[{"method":"cash","count":2,"amount":"250000.00"}],
            "hourly":[{"hour":"09","order_count":2,"total":"36000.00"}],"unknown_future_field":true}"""
        val r = json.decodeFromString(XReportDto.serializer(), raw)
        assertEquals(7L, r.sessionId)
        assertEquals("20000.00", r.cashOut)
        assertEquals("09", r.hourly.single().hour?.jsonPrimitive?.content)
        assertEquals("cash", r.byMethod.single().method)
    }

    @Test fun `jam x-report boleh berupa angka dari server`() {
        val r = json.decodeFromString(XReportDto.serializer(), """{"hourly":[{"hour":23,"order_count":2,"total":"20000.00"}]}""")
        assertEquals("23", r.hourly.single().hour?.jsonPrimitive?.content)
    }

    @Test fun `x-report dari server tanpa expected_cash tetap terbaca (hitungan buta)`() {
        // Server tidak lagi mengirim kas seharusnya di x-report; DTO tidak boleh memerlukannya atau menebaknya.
        val r = json.decodeFromString(XReportDto.serializer(), """{"session_id":9,"status":"open","order_count":0}""")
        assertEquals(9L, r.sessionId)
        assertEquals("0.00", r.totalSales)
    }

    @Test fun `tutup shift menyimpan z-report dan selisih`() {
        val raw = """{"id":7,"status":"closed","expected_cash":"180000.00","counted_total":"175000.00","variance":"-5000.00",
            "edc_batch_total":null,"z_report":{"variance_tolerance":"5000.00","closed_at":"2026-10-15T15:00:00+07:00"}}"""
        val c = json.decodeFromString(ClosedSessionDto.serializer(), raw)
        assertEquals("-5000.00", c.variance)
        assertEquals("5000.00", c.zReport?.varianceTolerance)
    }

    @Test fun `pengaturan berupa angka tetap terbaca`() {
        val f = json.decodeFromString(FeaturesDto.serializer(), """{"tax_rate":0,"variance_tolerance":5000,"cash_out_approval_threshold":200000}""")
        assertEquals("5000", f.varianceTolerance.toString())
        assertEquals("200000", f.cashOutApprovalThreshold.toString())
    }

    @Test fun `kas masuk keluar dibaca dan permintaan tanpa catatan tidak mengirim reason_note`() {
        val m = json.decodeFromString(CashMovementDto.serializer(), """{"id":3,"direction":"out","reason_code":"petty_cash","reason_note":null,"amount":"20000.00","created_by_name":"Rina","created_at":"2026-10-15T10:05:00+07:00"}""")
        assertEquals("out", m.direction)
        assertEquals("20000.00", m.amount)

        val req = json.encodeToString(CashMovementRequest.serializer(), CashMovementRequest(direction = "out", reasonCode = "petty_cash", amount = 20000))
        assertEquals("{\"direction\":\"out\",\"reason_code\":\"petty_cash\",\"amount\":20000}", req)
    }
}
