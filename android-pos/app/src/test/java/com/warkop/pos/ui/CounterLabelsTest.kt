package com.warkop.pos.ui

import com.warkop.pos.model.FloorTab
import com.warkop.pos.model.FloorTable
import com.warkop.pos.model.OrderKind
import org.junit.Assert.assertEquals
import org.junit.Assert.assertNull
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
        assertEquals("Rp\u00A07.000 –\u00A0Rp\u00A08.000", priceLabel(7000, 8000))
    }

    /** 077 / US6 — satu-satunya tempat boleh membungkus adalah sebelum tanda strip; tiap harga tidak pernah terpotong. */
    @Test fun `rentang hanya bisa putus sebelum tanda strip dan kedua harga utuh`() {
        val label = priceLabel(7000, 15000)
        val breakable = label.indices.filter { label[it] == ' ' }
        assertEquals(1, breakable.size)
        assertEquals("–", label.substring(breakable.single() + 1, breakable.single() + 2))
        assertEquals(listOf("Rp\u00A07.000", "–\u00A0Rp\u00A015.000"), label.split(' '))
    }

    @Test fun `angka besar tetap membawa kedua harga lengkap`() {
        val label = priceLabel(1_250_000, 12_500_000)
        assertEquals(true, label.contains("Rp\u00A01.250.000"))
        assertEquals(true, label.contains("Rp\u00A012.500.000"))
    }

    @Test fun `harga tunggal satu baris dan rentang dua baris`() {
        assertEquals(1, priceLines(priceLabel(7000, 7000)))
        assertEquals(2, priceLines(priceLabel(7000, 8000)))
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

/** 084 — judul/keterangan/petunjuk layar Kasir dan pilihan meja (fungsi murni di CounterLabels.kt). */
class KasirScreenLabelsTest {
    @Test fun `judul counter hanya nomor antrean`() {
        assertEquals("12", kasirTitle(OrderKind.COUNTER, "12", null))
    }

    @Test fun `judul counter tanpa nomor jatuh ke Kasir dan bukan kosong`() {
        assertEquals("Kasir", kasirTitle(OrderKind.COUNTER, null, null))
        assertEquals("Kasir", kasirTitle(OrderKind.COUNTER, "  ", null))
    }

    @Test fun `judul pesanan meja tetap Meja dengan namanya`() {
        assertEquals("Meja 5", kasirTitle(OrderKind.DINE_IN, null, "5"))
    }

    @Test fun `keterangan counter dihapus dan dine in tetap`() {
        assertNull(kasirSubtitle(OrderKind.COUNTER, "Kasir 01"))
        assertEquals("Dine-in · Budi", kasirSubtitle(OrderKind.DINE_IN, "Budi"))
    }

    @Test fun `petunjuk kosong memakai kata Pilih`() {
        assertEquals("Pilih menu untuk menambah item", HINT_PICK_MENU)
        assertEquals("Pilih dan tahan menu untuk menandai habis.", HINT_HOLD_SOLD_OUT)
    }

    private fun tab(id: Long) = FloorTab(id, null, null, 0, 0, 1, false, null, null, 0, 0, "10:00")
    private fun table(id: Long, name: String, active: Boolean = true, tab: FloorTab? = null) = FloorTable(id, name, "Indoor", 4, active, tab)

    @Test fun `pilihan meja hanya meja aktif dan kosong ditambah meja pesanan itu sendiri`() {
        val tables = listOf(
            table(1, "1"), table(2, "2", tab = tab(9)), table(3, "3", active = false),
            table(4, "4", tab = tab(10)), table(5, "5"),
        )

        assertEquals(listOf("1", "4", "5"), selectableTables(tables, currentTable = "4").map { it.name })
        assertEquals(listOf("1", "5"), selectableTables(tables, currentTable = null).map { it.name })
    }

    // 2026-11-01 — item bersumber bahan: kartu menampilkan porsi dari bahan bila varian tidak punya stok sendiri.
    @Test fun `kartu memakai porsi bahan bila varian tanpa stok`() {
        val v = com.warkop.pos.model.Product("1", "soup", "c", "Indomie Kuah", 8000, stock = null)
        val e = com.warkop.pos.model.ProductEntry("789", "Indomie Kuah", "c", "Indomie", null, listOf(v), materialPortions = 17)
        assertEquals(17, entryStock(e))
        assertEquals(5, entryStock(e.copy(variants = listOf(v.copy(stock = 5)))))
        assertEquals(null, entryStock(e.copy(materialPortions = null)))
    }
}
