package com.warkop.pos.print

import com.warkop.pos.model.Order
import com.warkop.pos.model.OrderKind
import com.warkop.pos.model.OrderLine
import com.warkop.pos.model.PrintableItem
import com.warkop.pos.model.PrintableKot
import com.warkop.pos.model.ReceiptLine
import com.warkop.pos.model.ReceiptView
import org.junit.Assert.assertEquals
import org.junit.Assert.assertFalse
import org.junit.Assert.assertTrue
import org.junit.Test

/**
 * 050 / T027, T034, T035, T041, T053, T056 — isi dokumen cetak. Aturan yang dijaga:
 *  - KOT dan BATAL tidak memuat harga (FR-014).
 *  - Struk memakai angka server apa adanya, dan SALINAN hanya muncul dari salinan 2 (FR-010).
 *  - Pra-bill menandai BELUM LUNAS dan tidak punya baris pembayaran (contracts A3).
 *  - Tes cetak punya baris tepat selebar kertas (quickstart V2).
 */
class DocumentsTest {

    private val kot = PrintableKot(
        ticketId = 2201, stationName = "Dapur", tableName = "Meja 4", tabLabel = null, orderNumber = "O-2201",
        roundNo = 1, sentAtText = "10:42",
        items = listOf(
            PrintableItem(qty = 2, name = "Kopi Susu", modifiers = listOf("Gula: Less"), note = "tanpa es"),
            PrintableItem(qty = 1, name = "Roti Bakar", modifiers = emptyList(), note = null),
        ),
    )

    private fun receipt(copyNo: Int = 1, discount: Int = 0, change: Int = 0) = ReceiptView(
        storeName = "Warkop WJM 3", storeAddress = "Jl. Contoh 1", orderNumber = "O-2050", receiptNo = "T01-000014",
        queueNo = "A-025", copyNo = copyNo, cashier = "Rina", paidAtText = "16/10/2026 10:42",
        taxLabel = "PPN", taxRatePercent = "10", subtotal = 60000, discount = discount, tax = 5400, total = 59400,
        paid = 60000, change = change,
        items = listOf(ReceiptLine("Kopi Susu", 5, 60000, "tanpa es")),
        payments = listOf("Tunai" to 60000),
    )

    private fun texts(lines: List<PrintLine>): List<String> = lines.map { it.text }
    private fun rights(lines: List<PrintLine>): List<String> = lines.mapNotNull { it.right }

    @Test fun `kot memakai judul KOT besar dan tanpa harga`() {
        val lines = Documents.kot(kot, cashier = "Rina")
        assertEquals("KOT", lines.first().text)
        assertTrue(lines.first().big)
        assertFalse("KOT tidak memuat harga", (lines.map { it.text } + rights(lines)).any { it.contains("Rp") })
    }

    @Test fun `kot tanpa stasiun ditandai jelas`() {
        val lines = Documents.kot(kot.copy(stationName = null), cashier = null)
        assertTrue(texts(lines).contains("Tanpa stasiun"))
    }

    @Test fun `kot mencetak modifier dan catatan tiap item`() {
        val t = texts(Documents.kot(kot, cashier = null))
        assertTrue(t.contains("2 x Kopi Susu"))
        assertTrue(t.contains("   Gula: Less"))
        assertTrue(t.contains("   Catatan: tanpa es"))
        assertTrue(t.contains("1 x Roti Bakar"))
    }

    @Test fun `batal memuat item dan alasan tanpa harga`() {
        val item = PrintableItem(qty = 2, name = "Kopi Susu", modifiers = emptyList(), note = null)
        val lines = Documents.batal("Dapur", "Meja 4", "O-2201", item, "Salah pesan")
        assertEquals("BATAL", lines.first().text)
        assertTrue(lines.first().big)
        val t = texts(lines)
        assertTrue(t.contains("BATAL 2 x Kopi Susu"))
        assertTrue(t.contains("   Alasan: Salah pesan"))
        assertFalse((t + rights(lines)).any { it.contains("Rp") })
    }

    @Test fun `struk memakai total server dan tidak menghitung ulang`() {
        val lines = Documents.struk(receipt())
        val total = lines.first { it.text == "TOTAL" }
        assertEquals("59400", total.right!!.filter { it.isDigit() })
        assertTrue(lines.any { it.text == "Bayar" })
    }

    @Test fun `struk tanpa diskon tidak mencetak baris diskon`() {
        assertFalse(texts(Documents.struk(receipt(discount = 0))).contains("Diskon"))
    }

    @Test fun `struk dengan diskon mencetak baris diskon`() {
        assertTrue(texts(Documents.struk(receipt(discount = 6000))).contains("Diskon"))
    }

    @Test fun `salinan pertama tidak diberi label SALINAN`() {
        assertFalse(texts(Documents.struk(receipt(copyNo = 1))).any { it.startsWith("SALINAN") })
    }

    @Test fun `salinan kedua diberi label SALINAN 2`() {
        assertTrue(texts(Documents.struk(receipt(copyNo = 2))).contains("SALINAN 2"))
    }

    @Test fun `kembalian hanya muncul bila ada`() {
        assertFalse(texts(Documents.struk(receipt(change = 0))).contains("Kembali"))
        assertTrue(texts(Documents.struk(receipt(change = 600))).contains("Kembali"))
    }

    @Test fun `struk tidak memuat nomor telepon atau e-mail`() {
        val all = texts(Documents.struk(receipt())) + rights(Documents.struk(receipt()))
        assertFalse(all.any { it.contains("@") })
        assertFalse(all.any { it.contains("08") && it.count { c -> c.isDigit() } >= 9 })
    }

    @Test fun `pra-bill menandai belum lunas dan tanpa baris pembayaran`() {
        val order = Order(
            id = "77", kind = OrderKind.DINE_IN, table = "Meja 3", pax = 2, queue = null, waiter = "Rina", openedAt = "10:00",
            lines = listOf(
                OrderLine(id = 1, itemId = "v1", name = "Kopi", basePrice = 12000, qty = 2),
                OrderLine(id = 2, itemId = "v2", name = "Batal", basePrice = 9000, qty = 1, voided = true),
            ),
        )
        val lines = Documents.prebill(order)
        val t = texts(lines)
        assertTrue(t.contains("BELUM LUNAS"))
        assertEquals(1, t.count { it.contains("Kopi") })
        assertFalse("baris batal tidak ikut tercetak", t.any { it.contains("Batal") })
        assertFalse(t.any { it.contains("Bayar") || it.contains("Kembali") })
    }

    @Test fun `tes cetak punya baris contoh tepat selebar kertas`() {
        val lines = Documents.testSlip("10/10/2026 10:00")
        assertTrue(lines.any { it.text.length == EscPos.COLUMNS })
        assertTrue(lines.none { it.text.length > EscPos.COLUMNS })
    }
}
