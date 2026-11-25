package com.warkop.manager.ui.products

import com.warkop.core.domain.ApiError
import com.warkop.manager.data.dto.CarriedOutletDto
import com.warkop.manager.data.dto.ProductDetailDto
import com.warkop.manager.data.dto.ProductVariantDto
import com.warkop.manager.domain.ProductForm
import com.warkop.manager.domain.ProductFormMapping
import com.warkop.manager.domain.SaveStep
import com.warkop.manager.domain.StepFailure
import com.warkop.manager.domain.VariantRow
import org.junit.Assert.assertEquals
import org.junit.Assert.assertFalse
import org.junit.Assert.assertNull
import org.junit.Assert.assertTrue
import org.junit.Test

/** 094 / T070 — 422 tiap langkah ke kolom yang benar; alasan stok hanya saat stok berubah dan sumber stok varian. */
class ProductFormViewModelTest {
    private fun v422(vararg pairs: Pair<String, String>) = ApiError.Validation("x", pairs.associate { it.first to listOf(it.second) })

    @Test fun `422 produk memakai kunci server apa adanya`() {
        val s = ProductFormMapping.status(listOf(StepFailure(SaveStep.PRODUCT, v422("name" to "Nama terlalu panjang.", "variants.1.sell_price" to "Harga wajib."))))
        assertEquals("Nama terlalu panjang.", s.error("name"))
        assertEquals("Harga wajib.", s.error("variants.1.sell_price"))
        assertNull(s.message)
    }

    @Test fun `422 langkah varian dipindah ke baris varian itu`() {
        val s = ProductFormMapping.status(listOf(StepFailure(SaveStep.VARIANT, v422("variant_name" to "Wajib diisi."), variantIndex = 2)))
        assertEquals("Wajib diisi.", s.error("variants.2.variant_name"))
        assertEquals("Wajib diisi.", s.errorUnder("variants.2"))
    }

    @Test fun `422 stok - alasan ke kolom alasan, lainnya pesan umum`() {
        val s = ProductFormMapping.status(listOf(StepFailure(SaveStep.STOCK, v422("reason" to "Wajib diisi.", "items.0.qty_change" to "Tidak boleh nol."))))
        assertEquals("Wajib diisi.", s.error("stock_adjustment_reason"))
        assertTrue(s.message!!.contains("Tidak boleh nol."))
        assertFalse(s.fieldErrors.keys.any { it.startsWith("stock.") })
    }

    @Test fun `galat non-422 diberi label langkah dan baris`() {
        val s = ProductFormMapping.status(listOf(StepFailure(SaveStep.VARIANT_PHOTO, ApiError.Network("Tidak tersambung."), variantIndex = 0)))
        assertEquals("Foto varian baris 1: Tidak tersambung.", s.message)
    }

    @Test fun `alasan stok wajib hanya bila stok berubah, sumber varian, dan mode ubah`() {
        val base = ProductForm(id = 1, homeOutletId = 1, categoryId = 1, name = "A", variants = listOf(VariantRow(id = 5, sellPrice = "1", originalStock = "3", currentStock = "3")))
        assertFalse("stock_adjustment_reason" in base.localErrors())
        val changed = base.copy(variants = listOf(base.variants[0].copy(currentStock = "4")))
        assertTrue("stock_adjustment_reason" in changed.localErrors())
        assertFalse("stock_adjustment_reason" in changed.copy(stockSource = "modifier").localErrors())
        assertFalse("stock_adjustment_reason" in changed.copy(stockReason = "opname").localErrors())
        assertFalse("stock_adjustment_reason" in changed.copy(id = null).localErrors())
    }

    @Test fun `nama varian wajib hanya bila lebih dari satu varian, outlet wajib saat buat`() {
        val one = ProductForm(homeOutletId = 1, categoryId = 1, name = "A", outletIds = setOf(1), variants = listOf(VariantRow(sellPrice = "1")))
        assertTrue(one.localErrors().isEmpty())
        val two = one.copy(variants = one.variants + VariantRow(sellPrice = "2"))
        assertEquals(setOf("variants.0.variant_name", "variants.1.variant_name"), two.localErrors().keys)
        assertTrue("outlet_ids" in one.copy(outletIds = emptySet()).localErrors())
    }

    @Test fun `isi dari detail - outlet dari carried, uang jadi angka ketik, stok asal dari outlet_stock`() {
        val d = ProductDetailDto(
            id = 9, homeOutletId = 1, categoryId = 2, name = "Kopi", prepMinutes = 5,
            outlets = listOf(CarriedOutletDto(1, name = "WJM3", carried = true), CarriedOutletDto(2, name = "WJM2", carried = false)),
            variants = listOf(ProductVariantDto(11, variantName = "Panas", sellPrice = "18000.00", costPrice = null, outletStock = 7, bomComplete = true)),
        )
        val f = ProductFormMapping.fromDetail(d, selectedOutletId = 1)
        assertEquals(setOf(1L), f.outletIds)
        assertEquals("18000", f.variants[0].sellPrice)
        assertEquals("0", f.variants[0].costPrice)
        assertEquals("7", f.variants[0].originalStock)
        assertEquals("5", f.prepMinutes)
        assertTrue(f.variants[0].bomComplete)
        assertEquals(setOf(3L), ProductFormMapping.fromDetail(d.copy(outlets = null), selectedOutletId = 3).outletIds)
    }

    @Test fun `uang dikirim dua desimal seperti toMoneyString web`() {
        assertEquals("15000.00", ProductForm.money("15000"))
        assertEquals("0.00", ProductForm.money("abc"))
        assertEquals("ABC", ProductForm.cleanSegment("a1bcd"))
    }
}
