package com.warkop.pos.data

import org.junit.Assert.assertEquals
import org.junit.Assert.assertNull
import org.junit.Test

/** Tanggal struk diambil dari waktu bayar server (paid_at), dalam zona toko — tidak pernah tanggal tetap. */
class ReceiptDateTest {
    @Test fun `tanggal struk memakai waktu bayar server dalam zona toko`() {
        // 09:23 UTC = 16:23 WIB (Asia/Jakarta)
        assertEquals("16/10/2026 16:23", OrderMapper.receiptDateTime(listOf("2026-10-16T09:23:54Z")))
    }

    @Test fun `pembayaran terakhir yang dipakai bila ada beberapa`() {
        val d = OrderMapper.receiptDateTime(listOf("2026-10-15T10:00:00Z", "2026-10-16T09:23:54Z", null))
        assertEquals("16/10/2026 16:23", d)
    }

    @Test fun `tanpa waktu bayar yang valid tidak ada tanggal`() {
        assertNull(OrderMapper.receiptDateTime(emptyList()))
        assertNull(OrderMapper.receiptDateTime(listOf(null, "bukan-tanggal")))
    }
}
