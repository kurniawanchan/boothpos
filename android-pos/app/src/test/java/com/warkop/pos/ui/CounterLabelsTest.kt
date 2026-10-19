package com.warkop.pos.ui

import org.junit.Assert.assertEquals
import org.junit.Test

/** 055 / T042 — Kirim menyimpan jumlah (keputusan), Bayar tanpa nominal, pelayan tetap "Cetak pre-bill". */
class CounterLabelsTest {
    @Test fun `kirim menampilkan jumlah item yang belum dikirim`() {
        assertEquals("Kirim 0", kirimLabel(0))
        assertEquals("Kirim 3", kirimLabel(3))
    }

    @Test fun `bayar tanpa nominal`() {
        assertEquals("Bayar", bayarLabel(waiter = false))
    }

    @Test fun `pelayan tetap melihat cetak pre-bill`() {
        assertEquals("Cetak pre-bill", bayarLabel(waiter = true))
    }

    /** 058 / T003 — tombol QRIS bernama "Bayar", tanpa "menunggu verifikasi" dan tanpa nominal. */
    @Test fun `bayar untuk tombol QRIS tanpa tulisan verifikasi`() {
        assertEquals("Bayar", bayarLabel(waiter = false))
        assertEquals(false, bayarLabel(waiter = false).contains("verifikasi"))
    }
}
