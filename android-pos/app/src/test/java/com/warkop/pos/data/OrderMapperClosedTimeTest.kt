package com.warkop.pos.data

import com.warkop.core.network.dto.ClosedOrderDto
import org.junit.Assert.assertFalse
import org.junit.Assert.assertNull
import org.junit.Assert.assertTrue
import org.junit.Test

/** 057 / T031 — waktu tutup dari server; tanpa waktu tidak ada teks "null" (FR-007, SC-005). */
class OrderMapperClosedTimeTest {
    @Test fun `waktu tutup dari server tampil sebagai HH_MM`() {
        val order = OrderMapper.closed(ClosedOrderDto(id = 4, closedAt = "2026-10-18T14:02:00+07:00"))
        assertTrue(order.closedAt != null && Regex("\\d\\d:\\d\\d").matches(order.closedAt!!))
    }

    @Test fun `tanpa waktu tutup nilainya null dan tidak pernah teks null`() {
        val order = OrderMapper.closed(ClosedOrderDto(id = 5, closedAt = null))
        assertNull(order.closedAt)
        assertFalse(order.closedAt.orEmpty().contains("null"))
    }
}
