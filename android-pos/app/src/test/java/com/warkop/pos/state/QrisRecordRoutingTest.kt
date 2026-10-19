package com.warkop.pos.state

import org.junit.Assert.assertEquals
import org.junit.Test

/** 058 / T005 — setelah pembayaran QRIS tercatat: penjualan tutup → struk dan selesai; masih ada sisa → tampilkan sisa. */
class QrisRecordRoutingTest {
    @Test fun `penjualan yang sudah tutup langsung selesai`() {
        assertEquals(QrisNext.Finish, qrisAfterPayment(closed = true, remaining = 0))
    }

    @Test fun `masih ada sisa tampilkan sisanya`() {
        assertEquals(QrisNext.ShowRemaining(5000), qrisAfterPayment(closed = false, remaining = 5000))
    }
}
