package com.warkop.pos.print

import com.warkop.core.network.dto.ReceiptDto
import com.warkop.core.network.dto.TicketDto
import com.warkop.pos.data.ReceiptMapper
import com.warkop.pos.data.TicketMapper
import com.warkop.pos.model.PrintableItem
import com.warkop.pos.model.PrintableKot
import com.warkop.pos.model.ReceiptLine
import com.warkop.pos.model.ReceiptView
import org.junit.Assert.assertEquals
import org.junit.Assert.assertFalse
import org.junit.Assert.assertNull
import org.junit.Assert.assertTrue
import org.junit.Test

/**
 * 078 / US4 — catatan kasir untuk SELURUH order tercetak di struk (di bawah item, sebelum total) dan di KOT (di bawah judul, sebelum
 * item). Tanpa catatan: tidak ada label atau baris kosong. Catatan per item tetap "Catatan: …" dan tidak tertukar.
 */
class DocumentsOrderNoteTest {
    private fun receipt(note: String?) = ReceiptView(
        storeName = "Warkop WJM", storeAddress = null, orderNumber = "O-1", receiptNo = "000001", queueNo = null, copyNo = 1,
        cashier = "Rina", paidAtText = "27/10/2026 10:00", taxLabel = "PPN", taxRatePercent = "10", subtotal = 10000, discount = 0,
        tax = 1000, total = 11000, paid = 11000, change = 0,
        items = listOf(ReceiptLine("Kopi", 1, 10000, "tanpa gula")), payments = listOf("Tunai" to 11000), orderNote = note,
    )

    private fun kot(note: String?) = PrintableKot(
        ticketId = 1, stationName = "Dapur", tableName = "Meja 4", tabLabel = null, orderNumber = "O-1", roundNo = 1, sentAtText = "10:00",
        items = listOf(PrintableItem(qty = 2, name = "Nasi Goreng", modifiers = emptyList(), note = "pedas")), orderNote = note,
    )

    private fun texts(lines: List<PrintLine>) = lines.map { it.text }

    @Test fun `struk mencetak catatan order di bawah item dan sebelum total`() {
        val lines = Documents.struk(receipt("Ambil jam 3"))
        val t = texts(lines)
        val label = t.indexOf("Catatan order:")
        assertTrue("label ada", label >= 0)
        assertTrue("label tebal", lines[label].bold)
        assertEquals("Ambil jam 3", t[label + 1])
        assertTrue("di bawah item", label > t.indexOfFirst { it.startsWith("1 x Kopi") })
        assertTrue("sebelum subtotal", label < t.indexOf("Subtotal"))
    }

    @Test fun `catatan item dan catatan order tidak tertukar pada struk`() {
        val t = texts(Documents.struk(receipt("Ambil jam 3")))
        assertTrue(t.contains("   Catatan: tanpa gula"))
        assertTrue(t.contains("Catatan order:"))
    }

    @Test fun `struk tanpa catatan tidak memuat label atau baris kosong tambahan`() {
        val withNote = texts(Documents.struk(receipt("x"))).size
        for (blank in listOf<String?>(null, "", "   ")) {
            val t = texts(Documents.struk(receipt(blank)))
            assertFalse(t.any { it.contains("Catatan order") })
            assertEquals(withNote - 2, t.size)
        }
    }

    @Test fun `kot mencetak catatan order tebal di bawah judul dan sebelum item`() {
        val lines = Documents.kot(kot("Alergi kacang"), cashier = "Rina")
        val t = texts(lines)
        val label = t.indexOf("CATATAN ORDER:")
        assertTrue(label >= 0)
        assertTrue(lines[label].bold)
        assertEquals("Alergi kacang", t[label + 1])
        assertTrue(label > t.indexOf("KOT"))
        assertTrue(label < t.indexOfFirst { it.startsWith("2 x Nasi Goreng") })
        assertTrue("catatan item tetap", t.contains("   Catatan: pedas"))
    }

    @Test fun `kot tanpa catatan tidak berubah`() {
        val t = texts(Documents.kot(kot(null), cashier = "Rina"))
        assertFalse(t.any { it.contains("CATATAN ORDER") })
    }

    @Test fun `catatan panjang dibungkus dalam lebar kertas tanpa kehilangan kata`() {
        val note = "Tolong pisahkan es batu dan sirup, kemas terpisah, jangan digabung dengan kantong makanan panas ya"
        val rows = EscPos.wrap(note, EscPos.COLUMNS)
        assertTrue(rows.all { it.length <= EscPos.COLUMNS })
        assertEquals(note.split(" ").filter { it.isNotEmpty() }, rows.joinToString(" ").split(" "))
    }

    @Test fun `karakter yang tak didukung diganti tanda tanya tanpa gagal cetak`() {
        val bytes = EscPos.render(Documents.struk(receipt("Es 🧊 manis — tanpa gula\nbaris dua")))
        assertTrue(bytes.isNotEmpty())
        assertEquals("Es ?? manis ? tanpa gula baris dua", EscPos.sanitize("Es 🧊 manis — tanpa gula\nbaris dua"))
    }

    @Test fun `mapper struk mengambil notes dan menolak spasi kosong`() {
        assertEquals("Ambil jam 3", ReceiptMapper.map(ReceiptDto(notes = " Ambil jam 3 ")).orderNote)
        assertNull(ReceiptMapper.map(ReceiptDto(notes = "  ")).orderNote)
        assertNull(ReceiptMapper.map(ReceiptDto()).orderNote)
    }

    @Test fun `mapper tiket mengambil order_note dan menolak spasi kosong`() {
        assertEquals("Alergi kacang", TicketMapper.kot(TicketDto(orderNote = "Alergi kacang")).orderNote)
        assertNull(TicketMapper.kot(TicketDto(orderNote = " ")).orderNote)
        assertNull(TicketMapper.kot(TicketDto()).orderNote)
    }

    /** 078 / T033 — jalur nyata: tiket dari server (dengan `order_note` terbaru) → mapper → KOT tercetak; cetak ulang struk memakai `fetchReceipt` (server), jadi selalu catatan terbaru. */
    @Test fun `tiket dari server dengan catatan sampai ke baris KOT tercetak`() {
        val printed = Documents.kot(TicketMapper.kot(TicketDto(id = 7, orderNote = "Alergi kacang")), cashier = null)
        assertTrue(texts(printed).containsAll(listOf("CATATAN ORDER:", "Alergi kacang")))
    }
}
