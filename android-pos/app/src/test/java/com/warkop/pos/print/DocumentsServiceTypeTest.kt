package com.warkop.pos.print

import com.warkop.core.network.dto.ReceiptDto
import com.warkop.pos.data.ReceiptMapper
import com.warkop.pos.model.ReceiptLine
import com.warkop.pos.model.ReceiptView
import org.junit.Assert.assertEquals
import org.junit.Assert.assertNull
import org.junit.Assert.assertTrue
import org.junit.Test

/** 090 / US5 — cara layanan tercetak di struk ("Layanan  Dine-In/Take Away"); order lama dan server lama tidak mencetak baris itu. */
class DocumentsServiceTypeTest {
    private fun receipt(label: String?) = ReceiptView(
        storeName = "Warkop WJM", storeAddress = null, orderNumber = "O-1", receiptNo = "000001", queueNo = null, copyNo = 1,
        cashier = "Rina", paidAtText = "03/11/2026 10:00", taxLabel = "PPN", taxRatePercent = "10", subtotal = 10000, discount = 0,
        tax = 1000, total = 11000, paid = 11000, change = 0,
        items = listOf(ReceiptLine("Kopi", 1, 10000, null)), payments = listOf("Tunai" to 11000), serviceLabel = label,
    )

    @Test fun `struk mencetak cara layanan di blok identitas order`() {
        val lines = Documents.struk(receipt("Take Away"))
        val row = lines.single { it.text == "Layanan" }
        assertEquals("Take Away", row.right)
        val t = lines.map { it.text }
        assertTrue(t.indexOf("Layanan") > t.indexOf("No. struk") && t.indexOf("Layanan") < t.indexOf("Subtotal"))
    }

    @Test fun `tanpa cara layanan tidak ada baris Layanan`() {
        assertTrue(Documents.struk(receipt(null)).none { it.text == "Layanan" })
    }

    @Test fun `pemeta menerjemahkan nilai server dan mengabaikan retail atau nilai tak dikenal`() {
        assertEquals("Dine-In", ReceiptMapper.map(ReceiptDto(serviceType = "dine_in")).serviceLabel)
        assertEquals("Take Away", ReceiptMapper.map(ReceiptDto(serviceType = "takeaway")).serviceLabel)
        assertNull(ReceiptMapper.map(ReceiptDto(serviceType = "retail")).serviceLabel)
        assertNull(ReceiptMapper.map(ReceiptDto(serviceType = "apa-saja")).serviceLabel)
        assertNull(ReceiptMapper.map(ReceiptDto()).serviceLabel)
    }
}
