package com.warkop.pos.data

import com.warkop.core.network.ApiClient
import com.warkop.core.network.dto.ReceiptDto
import com.warkop.pos.model.Order
import com.warkop.pos.model.OrderKind
import com.warkop.pos.ui.orderMoneyLines
import com.warkop.pos.ui.taxLabelText
import java.io.File
import kotlinx.serialization.json.Json
import kotlinx.serialization.json.JsonObject
import kotlinx.serialization.json.double
import kotlinx.serialization.json.int
import kotlinx.serialization.json.jsonArray
import kotlinx.serialization.json.jsonObject
import kotlinx.serialization.json.jsonPrimitive
import org.junit.Assert.assertEquals
import org.junit.Assert.assertTrue
import org.junit.Test

/**
 * 085 / US1 — paritas pajak. Android TIDAK menghitung pajak: angkanya datang dari server. Yang dijaga di sini adalah
 * bahwa angka server dipakai apa adanya (tanpa cadangan 10% dan tanpa pembulatan ke tarif lain) dan tarif yang tampil di
 * label adalah tarif dari server. Kasusnya dibaca dari fixture yang SAMA dengan tes server dan web.
 */
class TaxParityTest {
    private class Case(val base: Int, val rate: Double, val tax: Int)

    /** Gradle menjalankan tes dari folder modul; fixture ada di akar repo, jadi naik sampai ketemu. */
    private fun fixtureFile(): File {
        var dir: File? = File(System.getProperty("user.dir")).absoluteFile
        while (dir != null) {
            val f = File(dir, "qa-tests/fixtures/tax-parity-cases.json")
            if (f.exists()) return f
            dir = dir.parentFile
        }
        error("qa-tests/fixtures/tax-parity-cases.json tidak ditemukan dari ${System.getProperty("user.dir")}")
    }

    private val cases: List<Case> by lazy {
        Json.parseToJsonElement(fixtureFile().readText()).jsonObject["cases"]!!.jsonArray.map {
            val o: JsonObject = it.jsonObject
            Case(o["base"]!!.jsonPrimitive.int, o["rate"]!!.jsonPrimitive.double, o["tax"]!!.jsonPrimitive.int)
        }
    }

    private fun receipt(subtotal: Int, tax: Int, rate: Double, total: Int) = ReceiptMapper.map(
        ApiClient.json.decodeFromString(
            ReceiptDto.serializer(),
            """{"order_number":"O-1","tax_label":"PPN","tax_rate":"${"%.2f".format(java.util.Locale.US, rate)}",
               "subtotal":"$subtotal.00","tax_amount":"$tax.00","total_amount":"$total.00","payment_summary":[]}""",
        ),
    )

    private fun order(base: Int, tax: Int?) = Order(
        "C1", OrderKind.COUNTER, null, 0, "1", "Budi", "10:00",
        serverSubtotal = base, serverDiscount = 0, serverTax = tax, serverTotal = if (tax == null) null else base + tax,
    )

    @Test fun `fixture dibaca dan tidak kosong`() {
        assertTrue("fixture harus memuat kasus tarif non-10", cases.any { it.rate != 10.0 })
    }

    @Test fun `struk memakai pajak dan total server apa adanya untuk setiap kasus`() {
        for (c in cases.filter { it.base > 0 }) {
            val r = receipt(c.base, c.tax, c.rate, c.base + c.tax)
            assertEquals("pajak dasar=${c.base} tarif=${c.rate}", c.tax, r.tax)
            assertEquals("total dasar=${c.base} tarif=${c.rate}", c.base + c.tax, r.total)
        }
    }

    @Test fun `label struk menampilkan tarif dari server tanpa membulatkan ke 10`() {
        assertEquals("100", receipt(17000, 17000, 100.0, 34000).taxRatePercent)
        assertEquals("2.5", receipt(1001, 25, 2.5, 1026).taxRatePercent)
        assertEquals("5", receipt(60000, 3000, 5.0, 63000).taxRatePercent)
        assertEquals("0.5", receipt(1000, 5, 0.5, 1005).taxRatePercent)
    }

    @Test fun `pesanan memakai pajak server dan tanpa angka dari server pajaknya nol bukan 10 persen`() {
        assertEquals(17000, order(17000, 17000).tax)
        assertEquals(34000, order(17000, 17000).total)
        // Cadangan 10% lama dihapus di 083: tanpa angka server, pajak 0 (bukan base × 10%).
        assertEquals(0, order(17000, null).tax)
    }

    @Test fun `rincian pesanan menampilkan pajak server untuk setiap kasus berpajak`() {
        for (c in cases.filter { it.base > 0 && it.tax > 0 }) {
            val lines = orderMoneyLines(order(c.base, c.tax), TaxDisplay("PPN", c.rate))
            val tax = lines.first { it.label.startsWith("PPN") }
            assertEquals("Rp ${"%,d".format(java.util.Locale.GERMANY, c.tax)}", tax.value)
        }
    }

    @Test fun `teks label pajak memakai tarif apa adanya`() {
        assertEquals("PPN 100%", taxLabelText(TaxDisplay("PPN", 100.0)))
        assertEquals("PPN 2.5%", taxLabelText(TaxDisplay("PPN", 2.5)))
        assertEquals("Pajak", taxLabelText(null))
    }
}
