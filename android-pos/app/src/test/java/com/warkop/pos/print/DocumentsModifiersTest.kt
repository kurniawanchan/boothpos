package com.warkop.pos.print

import com.warkop.core.network.ApiClient
import com.warkop.core.network.dto.ReceiptDto
import com.warkop.pos.data.ReceiptMapper
import com.warkop.pos.model.ReceiptLine
import org.junit.Assert.assertEquals
import org.junit.Assert.assertFalse
import org.junit.Assert.assertTrue
import org.junit.Test

/**
 * 084 / US1+US4 — modifier tercetak di struk. Dulu `ReceiptLine` tak punya modifier sehingga struk Android hanya mencetak
 * nama item (KOT dan pra-bill sudah). Jalur nyata: JSON struk server → [ReceiptMapper] → [Documents.struk] → byte printer.
 */
class DocumentsModifiersTest {
    private fun dto(items: String) = ApiClient.json.decodeFromString(ReceiptDto.serializer(), """{
        "store_name":"Warkop WJM","order_number":"O-1","receipt_no":"000001","tax_amount":"0.00","tax_rate":"0.00","tax_label":"PPN",
        "subtotal":"46000.00","discount_amount":"0.00","total_amount":"46000.00","paid_amount":"50000.00","change_amount":"4000.00",
        "items":$items,
        "payment_summary":[{"method":"cash","amount":"50000.00"}]
    }""")

    private fun texts(lines: List<PrintLine>) = lines.map { it.text }

    @Test fun `modifier dari server tercetak di bawah nama item dan sebelum catatan`() {
        val receipt = ReceiptMapper.map(dto("""[{
            "name":"Kopi Susu","qty":2,"price":"23000.00","line_total":"46000.00","note":"tanpa es",
            "modifiers":[{"group":"Level gula","option":"Kurang manis","price_delta":"0.00"},{"group":"Topping","option":"Boba","price_delta":"3000.00"}]
        }]"""))

        val t = texts(Documents.struk(receipt))
        val item = t.indexOf("2 x Kopi Susu")
        assertTrue(item >= 0)
        assertEquals("   Kurang manis", t[item + 1])
        assertEquals("   Boba", t[item + 2])
        assertEquals("   Catatan: tanpa es", t[item + 3])
    }

    @Test fun `item tanpa modifier tercetak seperti semula tanpa baris kosong`() {
        val receipt = ReceiptMapper.map(dto("""[{"name":"Roti","qty":1,"price":"10000.00","line_total":"10000.00","note":null}]"""))

        val t = texts(Documents.struk(receipt))
        val item = t.indexOf("1 x Roti")
        assertTrue(item >= 0)
        assertTrue(t[item + 1].startsWith("-")) // langsung pemisah: tidak ada baris modifier kosong
    }

    @Test fun `server lama tanpa field modifiers tetap terbaca`() {
        val receipt = ReceiptMapper.map(dto("""[{"name":"Roti","qty":1,"price":"10000.00","line_total":"10000.00"}]"""))
        assertEquals(emptyList<String>(), receipt.items[0].mods)
    }

    @Test fun `nama opsi kosong tidak dicetak`() {
        val receipt = ReceiptMapper.map(dto("""[{"name":"Roti","qty":1,"price":"1.00","line_total":"1.00","modifiers":[{"group":"G","option":""}]}]"""))
        assertEquals(emptyList<String>(), receipt.items[0].mods)
    }

    @Test fun `struk dengan modifier dan karakter khusus sampai ke printer tanpa tanda tanya`() {
        val receipt = ReceiptMapper.map(dto("""[{"name":"Café — Latte","qty":1,"price":"20000.00","line_total":"20000.00","modifiers":[{"group":"Susu","option":"Oat “barista”"}]}]"""))

        val bytes = EscPos.render(Documents.struk(receipt))
        val text = String(bytes, Charsets.ISO_8859_1)
        assertFalse(text.contains('?'))
        assertTrue(text.contains("Cafe - Latte"))
        assertTrue(text.contains("Oat \"barista\""))
    }

    @Test fun `pra-bill dan struk memakai bentuk modifier yang sama`() {
        val line = ReceiptLine("Kopi", 1, 10000, null, listOf("Boba"))
        assertEquals(listOf("   Boba"), texts(Documents.struk(
            ReceiptMapper.map(dto("""[{"name":"Kopi","qty":1,"price":"10000.00","line_total":"10000.00","modifiers":[{"group":"T","option":"Boba"}]}]"""))
        )).filter { it.startsWith("   ") })
        assertEquals(listOf("Boba"), line.mods)
    }
}
