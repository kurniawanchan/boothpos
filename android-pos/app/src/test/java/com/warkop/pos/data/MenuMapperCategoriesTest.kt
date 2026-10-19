package com.warkop.pos.data

import com.warkop.core.network.dto.PosCategoryDto
import com.warkop.core.network.dto.ProductDto
import com.warkop.core.network.dto.VariantDto
import org.junit.Assert.assertEquals
import org.junit.Assert.assertTrue
import org.junit.Test

/** 055 / T012 — kategori tablet berasal dari daftar server (research R1), bukan dari produk. */
class MenuMapperCategoriesTest {
    private fun cat(id: Long, name: String, order: Int = id.toInt()) = PosCategoryDto(id, code = name.take(2), name = name, displayOrder = order)

    private fun product(id: Long, name: String, categoryId: Long?) = ProductDto(
        id = id, name = name, categoryId = categoryId, categoryName = null,
        variants = listOf(VariantDto(id = id * 10, variantName = "", sellPrice = "12000.00")),
    )

    @Test fun `kategori tanpa produk tetap tampil, urut seperti daftar server`() {
        val catalog = MenuMapper.toCatalog(
            products = listOf(product(1, "Americano", categoryId = 2)),
            serverCategories = listOf(cat(3, "Gantungan Kunci"), cat(2, "Kopi"), cat(4, "Pin Emamel")),
        )
        assertEquals(listOf("Gantungan Kunci", "Kopi", "Pin Emamel"), catalog.categories.map { it.name })
    }

    @Test fun `produk di kategori yang tidak ada di daftar server masuk Lainnya, tidak dibuang`() {
        val catalog = MenuMapper.toCatalog(
            products = listOf(product(1, "Americano", categoryId = 99)),
            serverCategories = listOf(cat(2, "Kopi")),
        )
        val other = catalog.categories.single { it.id == MenuMapper.LAINNYA }
        assertEquals("Lainnya", other.name)
        assertEquals(1, catalog.products.count { it.cat == MenuMapper.LAINNYA })
    }

    @Test fun `tidak ada kelompok Lainnya bila semua produk punya kategori yang dikenal`() {
        val catalog = MenuMapper.toCatalog(
            products = listOf(product(1, "Americano", categoryId = 2)),
            serverCategories = listOf(cat(2, "Kopi")),
        )
        assertTrue(catalog.categories.none { it.id == MenuMapper.LAINNYA })
    }

    @Test fun `kategori tidak diturunkan dari produk, kategori yang tidak dikirim server tidak muncul`() {
        val catalog = MenuMapper.toCatalog(
            products = listOf(product(1, "Americano", categoryId = 2)),
            serverCategories = listOf(cat(2, "Kopi")),
        )
        assertEquals(listOf("2"), catalog.categories.map { it.id })
    }
}
