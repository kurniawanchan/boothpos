package com.warkop.manager.domain

import com.warkop.core.domain.ApiError
import com.warkop.manager.data.dto.AdjustBody
import com.warkop.manager.data.dto.ProductBody
import com.warkop.manager.data.dto.ProductSavedDto
import com.warkop.manager.data.dto.ProductVariantDto
import com.warkop.manager.data.dto.VariantBody
import kotlinx.coroutines.runBlocking
import org.junit.Assert.assertEquals
import org.junit.Assert.assertFalse
import org.junit.Assert.assertNull
import org.junit.Assert.assertTrue
import org.junit.Test

/** 094 / T066 — simpan bertahap: langkah sukses tidak diulang, tidak ada produk kedua. */
class ProductSaveOrchestratorTest {
    /** Server palsu yang mencatat panggilan dan bisa digagalkan per langkah. */
    private class FakeApi : ProductSaveApi {
        val calls = mutableListOf<String>()
        val fail = mutableSetOf<String>()
        var nextVariantId = 100L
        var adjusted: AdjustBody? = null

        private fun call(name: String) { calls += name; if (name in fail) throw ApiError.Network() }

        override suspend fun create(body: ProductBody): ProductSavedDto {
            call("create")
            return ProductSavedDto(7, body.variants!!.map { ProductVariantDto(nextVariantId++, variantName = it.variantName) })
        }
        override suspend fun update(id: Long, body: ProductBody) = ProductSavedDto(id).also { call("update") }
        override suspend fun createVariant(productId: Long, body: VariantBody) = ProductVariantDto(nextVariantId++).also { call("createVariant") }
        override suspend fun updateVariant(id: Long, body: VariantBody) = call("updateVariant:$id")
        override suspend fun productImage(id: Long, uri: String) = call("productImage")
        override suspend fun variantImage(id: Long, uri: String) = call("variantImage:$id")
        override suspend fun adjust(body: AdjustBody) { call("adjust"); adjusted = body }
    }

    private val api = FakeApi()
    private val orch = ProductSaveOrchestrator(api, canSeeCost = true, multi = true, outletId = 3)

    private fun newForm() = ProductForm(
        outletIds = setOf(3), homeOutletId = 3, categoryId = 1, name = "Kopi Susu", photo = "content://p",
        variants = listOf(
            VariantRow(key = "a", name = "Panas", sellPrice = "18000", currentStock = "10", photo = "content://a"),
            VariantRow(key = "b", name = "Dingin", sellPrice = "20000", isActive = false),
        ),
    )

    @Test fun `buat sukses penuh - id terpetakan per urutan, varian nonaktif lewat PUT, stok awal beralasan tetap`() = runBlocking {
        val out = orch.save(newForm())
        assertTrue(out.ok)
        assertEquals(7L, out.form.id)
        assertEquals(listOf(100L, 101L), out.form.variants.map { it.id })
        assertEquals(listOf("create", "updateVariant:101", "productImage", "variantImage:100", "adjust"), api.calls)
        assertEquals(ProductForm.OPENING_REASON, api.adjusted!!.reason)
        assertEquals(listOf(100L to 10), api.adjusted!!.items.map { it.variantId to it.qtyChange })
        assertNull(out.form.photo)
        assertEquals("10", out.form.variants[0].originalStock)
    }

    @Test fun `gagal foto lalu simpan ulang - hanya foto yang diulang, tanpa POST produk kedua`() = runBlocking {
        api.fail += "productImage"
        val first = orch.save(newForm())
        assertFalse(first.ok)
        assertEquals(SaveStep.PRODUCT_PHOTO, first.failures.single().step)
        assertEquals("content://p", first.form.photo)

        api.fail.clear(); api.calls.clear()
        val second = orch.save(first.form)
        assertTrue(second.ok)
        assertEquals(listOf("productImage"), api.calls)
    }

    @Test fun `gagal stok lalu simpan ulang - stok awal tetap tanpa alasan dari pengguna`() = runBlocking {
        api.fail += "adjust"
        val first = orch.save(newForm())
        assertEquals(SaveStep.STOCK, first.failures.single().step)
        assertTrue(first.form.localErrors().isEmpty()) // alasan stok TIDAK diminta untuk produk yang dibuat di sini
        api.fail.clear(); api.calls.clear()
        assertTrue(orch.save(first.form).ok)
        assertEquals(listOf("adjust"), api.calls)
        assertEquals(ProductForm.OPENING_REASON, api.adjusted!!.reason)
    }

    @Test fun `gagal buat produk - tidak ada langkah lain, simpan ulang membuat sekali`() = runBlocking {
        api.fail += "create"
        val first = orch.save(newForm())
        assertNull(first.form.id)
        assertEquals(listOf("create"), api.calls)
        api.fail.clear()
        orch.save(first.form)
        assertEquals(2, api.calls.count { it == "create" }) // percobaan gagal + satu yang berhasil, tidak lebih
    }

    @Test fun `ubah - hanya varian yang berubah dikirim ulang, produk tak berubah tidak di-PUT lagi`() = runBlocking {
        val edit = ProductForm(
            id = 7, outletIds = setOf(3), homeOutletId = 3, categoryId = 1, name = "Kopi",
            variants = listOf(VariantRow(key = "a", id = 100, name = "Panas", sellPrice = "18000"), VariantRow(key = "b", id = 101, name = "Dingin", sellPrice = "20000")),
        )
        val first = orch.save(edit)
        assertEquals(listOf("update", "updateVariant:100", "updateVariant:101"), api.calls)
        api.calls.clear()
        val changed = first.form.copy(variants = first.form.variants.map { if (it.key == "b") it.copy(sellPrice = "21000") else it })
        orch.save(changed)
        assertEquals(listOf("updateVariant:101"), api.calls)
    }

    @Test fun `stock_source modifier - tidak ada koreksi stok`() = runBlocking {
        orch.save(newForm().copy(stockSource = "modifier"))
        assertFalse("adjust" in api.calls)
    }

    @Test fun `ubah stok tanpa alasan ditolak di klien, harga modal BOM lengkap tidak dikirim`() {
        val f = ProductForm(id = 7, homeOutletId = 3, categoryId = 1, name = "K", variants = listOf(VariantRow(id = 1, sellPrice = "1", originalStock = "5", currentStock = "4")))
        assertTrue("stock_adjustment_reason" in f.localErrors())
        assertNull(orch.variantBody(VariantRow(sellPrice = "1", costPrice = "900", bomComplete = true)).costPrice)
        assertNull(ProductSaveOrchestrator(api, canSeeCost = false, multi = false, outletId = 3).variantBody(VariantRow(sellPrice = "1")).costPrice)
        assertNull(ProductSaveOrchestrator(api, canSeeCost = true, multi = false, outletId = 3).productBody(f).outletIds)
    }
}
