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

/** 075 / T011 — label kartu produk Counter. */
class ProductCardLabelsTest {
    @Test fun `harga tunggal atau rentang`() {
        assertEquals("Rp 7.000", priceLabel(7000, 7000))
        assertEquals("Rp 7.000–Rp 8.000", priceLabel(7000, 8000))
    }

    @Test fun `stok tidak dilacak tidak punya baris`() {
        assertEquals(null, stockLabel(null))
        assertEquals("Stok 0", stockLabel(0))
        assertEquals("Stok 12", stockLabel(12))
    }

    @Test fun `lencana varian dan inisial`() {
        assertEquals("2 varian", variantCountLabel(2))
        assertEquals("AK", initialsOf("ABC Klepon"))
        assertEquals("R", initialsOf(" roti "))
        assertEquals("?", initialsOf("  "))
    }
}

/** 075 / T024 — baris Diskon selalu ada; angka tetap dari server. */
class DiscountRowLabelsTest {
    @Test fun `tanpa potongan baris tetap ada dengan Rp 0`() {
        assertEquals("Diskon", discountRowLabel(0, 0, null))
        assertEquals("Rp 0", discountRowAmount(0))
    }

    @Test fun `dengan potongan label dan jumlah negatif`() {
        assertEquals("Potongan 10 % · Promo", discountRowLabel(6000, 10, "Promo"))
        assertEquals("-Rp 6.000", discountRowAmount(6000))
    }
}
