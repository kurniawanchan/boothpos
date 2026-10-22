package com.warkop.pos.data

import com.warkop.core.network.ApiClient
import com.warkop.core.network.dto.TabDto
import org.junit.Assert.assertEquals
import org.junit.Assert.assertFalse
import org.junit.Assert.assertNull
import org.junit.Assert.assertTrue
import org.junit.Test

/** 048 / S4 (US5) — pemetaan tab dari server: total dibaca apa adanya; refund ditandai refunded_at, status tetap "completed". */
class OrderMapperS4Test {
    private fun tab(extra: String = "") = ApiClient.json.decodeFromString(
        TabDto.serializer(),
        """{"id":2040,"status":"completed","subtotal":"9000.00","discount_amount":"1000.00","tax_amount":"0.00","total_amount":"8000.00","paid_amount":"8000.00"$extra}""",
    )

    @Test fun `diskon dan total dibaca dari server, tidak dihitung ulang di klien`() {
        val o = OrderMapper.tab(tab(), myDeviceId = null)
        assertEquals(1000, o.discount)
        assertEquals(8000, o.total)
        assertEquals(8000, o.paid)
    }

    @Test fun `refunded_at menandai refund, status tetap completed, angka refund dari server`() {
        val o = OrderMapper.tab(
            tab(",\"refunded_at\":\"2026-10-16T06:00:00+07:00\",\"refund_total\":\"8000.00\",\"cash_returned\":\"8000.00\""),
            myDeviceId = null,
        )
        assertTrue(o.refunded)
        assertTrue(o.closed)
        assertEquals(8000, o.refundTotal)
        assertEquals(8000, o.cashReturned)
    }

    @Test fun `tanpa refunded_at bukan refund, meski status completed`() {
        val o = OrderMapper.tab(tab(), myDeviceId = null)
        assertFalse(o.refunded)
        assertNull(o.refundTotal)
        assertNull(o.cashReturned)
        assertTrue(o.closed)
    }
}
