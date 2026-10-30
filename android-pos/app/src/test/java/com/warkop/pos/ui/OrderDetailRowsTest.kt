package com.warkop.pos.ui

import com.warkop.pos.data.TaxDisplay
import com.warkop.pos.model.FloorTab
import com.warkop.pos.model.FloorTable
import com.warkop.pos.model.Order
import com.warkop.pos.model.OrderKind
import com.warkop.pos.model.Tender
import com.warkop.pos.model.TenderMethod
import com.warkop.pos.model.TenderStatus
import org.junit.Assert.assertEquals
import org.junit.Assert.assertFalse
import org.junit.Assert.assertTrue
import org.junit.Test

/** 084 / US2 — rincian uang lengkap pesanan Selesai dan pilihan meja untuk pesanan selesai. */
class OrderDetailRowsTest {
    private fun order(tax: Int? = 5_500, discount: Int? = 0, change: Int? = 0, tenders: List<Tender> = emptyList()) = Order(
        "C1", OrderKind.COUNTER, null, 0, "12", "Budi", "10:00", closed = true, tenders = tenders,
        serverSubtotal = 50_000, serverDiscount = discount, serverTax = tax, serverTotal = 50_000 - (discount ?: 0) + (tax ?: 0), serverChange = change,
    )
    private fun cash(amount: Int) = Tender(1, TenderMethod.CASH, amount, TenderStatus.OK)

    private fun labels(o: Order, t: TaxDisplay? = TaxDisplay("PPN", 11.0)) = orderMoneyLines(o, t).map { it.label }

    @Test fun `rincian memuat subtotal pajak dan total berurutan`() {
        assertEquals(listOf("Subtotal", "PPN 11%", "Total"), labels(order()))
    }

    @Test fun `diskon tampil hanya bila ada`() {
        assertFalse("Diskon" in labels(order(discount = 0)))
        val lines = orderMoneyLines(order(discount = 5_000), TaxDisplay("PPN", 11.0))
        assertEquals("− Rp 5.000", lines.first { it.label == "Diskon" }.value)
    }

    @Test fun `pajak disembunyikan saat tax_show mati tetapi total tetap memuatnya`() {
        val o = order()
        val lines = orderMoneyLines(o, TaxDisplay("PPN", 11.0, show = false))
        assertFalse(lines.any { it.label.startsWith("PPN") })
        assertEquals("Rp 55.500", lines.first { it.label == "Total" }.value)
    }

    @Test fun `pesanan tanpa pajak tidak punya baris pajak`() {
        assertFalse(labels(order(tax = 0)).any { it.startsWith("PPN") })
    }

    @Test fun `pembayaran per metode dan kembalian ikut tampil`() {
        val lines = orderMoneyLines(order(change = 4_500, tenders = listOf(cash(60_000))), TaxDisplay("PPN", 11.0))
        assertTrue(lines.any { it.label == "Tunai" && it.value == "Rp 60.000" })
        assertEquals("Rp 4.500", lines.first { it.label == "Kembalian" }.value)
    }

    @Test fun `hanya baris total yang besar`() {
        assertEquals(listOf("Total"), orderMoneyLines(order(), TaxDisplay("PPN", 11.0)).filter { it.big }.map { it.label })
    }

    @Test fun `label pajak tanpa tarif memakai nama saja`() {
        assertEquals("Pajak", taxLabelText(null))
        assertEquals("Servis", taxLabelText(TaxDisplay("Servis", null)))
        assertEquals("PPN 11.5%", taxLabelText(TaxDisplay("PPN", 11.5)))
    }

    private fun tab(id: Long) = FloorTab(id, null, null, 0, 0, 1, false, null, null, 0, 0, "10:00")
    private fun table(name: String, active: Boolean = true, tab: FloorTab? = null) = FloorTable(name.hashCode().toLong(), name, "Indoor", 4, active, tab)

    @Test fun `pesanan selesai boleh dipindah ke meja yang sedang dipakai pesanan lain`() {
        val tables = listOf(table("1"), table("2", tab = tab(9)), table("3", active = false))
        assertEquals(listOf("1"), selectableTables(tables, currentTable = null).map { it.name })
        assertEquals(listOf("1", "2"), selectableTables(tables, currentTable = null, allowOccupied = true).map { it.name })
    }
}
