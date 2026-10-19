package com.warkop.pos.data

import com.warkop.core.network.dto.TabDto
import com.warkop.pos.model.discountHeading
import org.junit.Assert.assertEquals
import org.junit.Assert.assertNull
import org.junit.Test

/** 057 / T047 — persen dan alasan diskon order dipetakan; diskon lama tampil nominal saja (FR-012, FR-013). */
class OrderMapperDiscountTest {
    @Test fun `persen dan alasan tersimpan dipetakan ke order`() {
        val order = OrderMapper.tab(TabDto(id = 9, discountPercent = 11.0, discountReasonLabel = "Promosi", discountAmount = "1320.00"), null)
        assertEquals(11, order.discPct)
        assertEquals("Promosi", order.discReason)
        assertEquals("Potongan 11 % · Promosi", discountHeading(order.discPct, order.discReason))
    }

    @Test fun `diskon lama tanpa persen dan alasan hanya menampilkan potongan`() {
        val order = OrderMapper.tab(TabDto(id = 10, discountPercent = null, discountReasonLabel = null, discountAmount = "500.00"), null)
        assertEquals(0, order.discPct)
        assertNull(order.discReason)
        assertEquals("Potongan", discountHeading(order.discPct, order.discReason))
    }
}
