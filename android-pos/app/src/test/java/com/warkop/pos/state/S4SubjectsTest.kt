package com.warkop.pos.state

import kotlinx.serialization.json.jsonPrimitive
import org.junit.Assert.assertEquals
import org.junit.Assert.assertFalse
import org.junit.Assert.assertNull
import org.junit.Test

/** 048 / S4 (US5) — subjek persetujuan diskon, cetak ulang, dan refund memuat kolom yang dikontrak. */
class S4SubjectsTest {
    @Test fun `subjek diskon baris memuat tab, lingkup, baris, persentase, dan alasan`() {
        val s = discountSubject(tabId = 2044, scope = "line", lineId = 6046, percent = 10.0, reasonCode = "promo_outlet")
        assertEquals(2044L, s["tab_id"]!!.jsonPrimitive.content.toLong())
        assertEquals("line", s["scope"]!!.jsonPrimitive.content)
        assertEquals(6046, s["line_id"]!!.jsonPrimitive.content.toInt())
        assertEquals(10.0, s["percent"]!!.jsonPrimitive.content.toDouble(), 0.0)
        assertEquals("promo_outlet", s["reason_code"]!!.jsonPrimitive.content)
    }

    @Test fun `subjek diskon order tidak memuat line_id`() {
        val s = discountSubject(tabId = 7, scope = "order", lineId = null, percent = 20.0, reasonCode = "karyawan")
        assertEquals("order", s["scope"]!!.jsonPrimitive.content)
        assertFalse(s.containsKey("line_id"))
        assertNull(s["line_id"])
    }

    @Test fun `subjek cetak ulang memuat order_id dan copy_no`() {
        val s = reprintSubject(orderId = 2040, copyNo = 2)
        assertEquals(2040L, s["order_id"]!!.jsonPrimitive.content.toLong())
        assertEquals(2, s["copy_no"]!!.jsonPrimitive.content.toInt())
    }

    @Test fun `subjek refund memuat order, alasan, dan catatan bila ada`() {
        val s = refundSubject(orderId = 2041, reasonCode = "salah_input", reason = "Pesanan ganda")
        assertEquals(2041L, s["order_id"]!!.jsonPrimitive.content.toLong())
        assertEquals("salah_input", s["reason_code"]!!.jsonPrimitive.content)
        assertEquals("Pesanan ganda", s["reason"]!!.jsonPrimitive.content)
        assertNull(refundSubject(orderId = 2041, reasonCode = "salah_input", reason = null)["reason"])
    }
}
