package com.warkop.pos.print

import com.warkop.pos.model.ReceiptView
import org.junit.Assert.assertEquals
import org.junit.Assert.assertFalse
import org.junit.Assert.assertTrue
import org.junit.Test

/** 059 / T016, T022 — susunan identitas toko di struk: kontak setelah alamat, logo di atas nama, tanpa teks "null". */
class StrukIdentityTest {
    private fun receipt(
        person: String? = null,
        phone: String? = null,
        email: String? = null,
        logo: String? = null,
    ) = ReceiptView(
        storeName = "Warkop WJM",
        storeAddress = "Jl. Melati 1",
        orderNumber = "O-1",
        receiptNo = "000001",
        queueNo = null,
        copyNo = 1,
        cashier = "Budi",
        paidAtText = null,
        taxLabel = "PPN",
        taxRatePercent = "11",
        subtotal = 0,
        discount = 0,
        tax = 0,
        total = 0,
        paid = 0,
        change = 0,
        items = emptyList(),
        payments = emptyList(),
        storeContactPerson = person,
        storeContactPhone = phone,
        storeContactEmail = email,
        storeLogoUrl = logo,
    )

    private fun texts(lines: List<PrintLine>) = lines.map { it.text }

    @Test fun `kontak tercetak berurutan setelah alamat`() {
        val t = texts(Documents.struk(receipt(person = "Pak Budi", phone = "0812", email = "toko@x.id")))
        val i = t.indexOf("Jl. Melati 1")
        assertEquals(listOf("Pak Budi", "0812", "toko@x.id"), t.subList(i + 1, i + 4))
    }

    @Test fun `hanya telepon terisi menghasilkan satu baris kontak`() {
        val t = texts(Documents.struk(receipt(phone = "0812")))
        assertTrue(t.contains("0812"))
        assertFalse(t.contains("Pak Budi"))
        assertFalse(t.contains("toko@x.id"))
    }

    @Test fun `tanpa kontak tidak ada teks null`() {
        val t = texts(Documents.struk(receipt()))
        assertFalse(t.any { it.contains("null") })
        assertEquals("Jl. Melati 1", t[1])
    }

    @Test fun `logo berada di baris pertama di atas nama toko`() {
        val logo = PrintLine(align = PrintLine.Align.CENTER, raster = "ff", rasterWidthDots = 8, rasterHeightDots = 1)
        val lines = Documents.struk(receipt(), logo)
        assertEquals(logo, lines.first())
        assertEquals("Warkop WJM", lines[1].text)
    }

    @Test fun `tanpa logo baris pertama adalah nama toko`() {
        assertEquals("Warkop WJM", Documents.struk(receipt()).first().text)
    }

    @Test fun `logo gagal dimuat sama dengan struk tanpa logo`() {
        // printOne memberi null bila logo gagal; hasilnya harus identik dengan struk tanpa logo.
        assertEquals(Documents.struk(receipt()), Documents.struk(receipt(), null))
    }
}
