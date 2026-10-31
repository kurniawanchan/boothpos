package com.warkop.pos.data

import com.warkop.core.network.dto.PosCategoryDto
import com.warkop.core.network.dto.ProductDto
import com.warkop.core.network.dto.VariantDto
import org.junit.Assert.assertEquals
import org.junit.Assert.assertNull
import org.junit.Assert.assertTrue
import org.junit.Test

/** 075 / T008 — grid Counter: satu entri per produk, varian tetap unit order. */
class MenuMapperEntriesTest {
    private val cats = listOf(PosCategoryDto(1, code = "KP", name = "Kopi", displayOrder = 1))

    private fun variant(id: Long, name: String, price: String, stock: Int? = 5, outlet: Int? = null, track: Boolean = true,
                        image: String? = null, sku: String? = "SKU$id", active: Boolean = true) =
        VariantDto(id = id, variantName = name, sellPrice = price, imageUrl = image, isActive = active, sku = sku,
            currentStock = stock, outletStock = outlet, trackStock = track)

    private fun product(id: Long, name: String, vararg v: VariantDto, image: String? = null) =
        ProductDto(id = id, name = name, categoryId = 1, imageUrl = image, variants = v.toList())

    @Test fun `satu entri per produk dan setiap varian ada di tepat satu entri`() {
        val c = MenuMapper.toCatalog(listOf(
            product(1, "ABC Klepon", variant(10, "Hot", "7000.00"), variant(11, "Ice", "8000.00")),
            product(2, "Roti", variant(20, "", "5000.00")),
        ), cats)
        assertEquals(listOf("ABC Klepon", "Roti"), c.entries.map { it.name })
        assertEquals(listOf(2, 1), c.entries.map { it.variants.size })
        assertEquals(c.products.map { it.id }.sorted(), c.entries.flatMap { it.variants }.map { it.id }.sorted())
        assertTrue(c.entries[1].isSingle)
    }

    @Test fun `produk tanpa varian aktif tidak punya entri`() {
        val c = MenuMapper.toCatalog(listOf(
            product(1, "Mati", variant(10, "A", "1000.00", active = false)),
            product(2, "Kosong"),
        ), cats)
        assertTrue(c.entries.isEmpty())
        assertTrue(c.products.isEmpty())
    }

    @Test fun `rentang harga dan harga tunggal`() {
        val c = MenuMapper.toCatalog(listOf(
            product(1, "Beda", variant(10, "Hot", "7000.00"), variant(11, "Ice", "8000.00")),
            product(2, "Sama", variant(20, "A", "6000.00"), variant(21, "B", "6000.00")),
        ), cats)
        assertEquals(7000 to 8000, c.entries[0].minPrice to c.entries[0].maxPrice)
        assertEquals(6000 to 6000, c.entries[1].minPrice to c.entries[1].maxPrice)
    }

    @Test fun `harga outlet dipakai bila ada`() {
        val v = VariantDto(id = 10, sellPrice = "7000.00", outletPrice = "9000.00")
        val c = MenuMapper.toCatalog(listOf(product(1, "X", v)), cats)
        assertEquals(9000, c.entries[0].minPrice)
    }

    @Test fun `stok entri adalah jumlah varian terlacak, saldo outlet didahulukan, tanpa lacak = null`() {
        val c = MenuMapper.toCatalog(listOf(
            product(1, "A", variant(10, "x", "1000.00", stock = 99, outlet = 3), variant(11, "y", "1000.00", stock = 4)),
            product(2, "B", variant(20, "x", "1000.00", track = false), variant(21, "y", "1000.00", track = false)),
            product(3, "C", variant(30, "x", "1000.00", track = false), variant(31, "y", "1000.00", stock = 2)),
        ), cats)
        assertEquals(7, c.entries[0].stock)
        assertNull(c.entries[1].stock)
        assertEquals(2, c.entries[2].stock)
    }

    @Test fun `entri habis hanya bila semua variannya habis`() {
        val c = MenuMapper.toCatalog(listOf(product(1, "A", variant(10, "x", "1.00"), variant(11, "y", "1.00"))), cats)
        assertEquals(false, c.entries[0].isSoldOut(setOf("10")))
        assertEquals(true, c.entries[0].isSoldOut(setOf("10", "11")))
    }

    @Test fun `URL foto diarahkan ke alamat server tablet, varian jatuh ke foto produk`() {
        val c = MenuMapper.toCatalog(listOf(
            product(1, "A", variant(10, "x", "1.00", image = "http://localhost/storage/v.jpg"), variant(11, "y", "1.00"),
                image = "http://localhost/storage/p.jpg"),
        ), cats, serverBase = "http://192.168.1.5:8010")
        assertEquals("http://192.168.1.5:8010/storage/p.jpg", c.entries[0].imageUrl)
        assertEquals("http://192.168.1.5:8010/storage/v.jpg", c.entries[0].variants[0].imageUrl)
        assertEquals("http://192.168.1.5:8010/storage/p.jpg", c.entries[0].variants[1].imageUrl)
    }

    @Test fun `produk tanpa foto memakai foto varian pertama untuk kartu, tanpa keduanya null`() {
        val c = MenuMapper.toCatalog(listOf(
            product(1, "A", variant(10, "x", "1.00", image = "http://h/storage/v.jpg")),
            product(2, "B", variant(20, "x", "1.00")),
        ), cats)
        assertEquals("http://h/storage/v.jpg", c.entries[0].imageUrl)
        assertNull(c.entries[1].imageUrl)
    }

    @Test fun `kode dan nama varian terbawa`() {
        val c = MenuMapper.toCatalog(listOf(product(1, "A", variant(10, "Hot", "1.00", sku = "KLP-H"), variant(11, "Ice", "1.00"))), cats)
        assertEquals("KLP-H", c.entries[0].variants[0].code)
        assertEquals("Hot", c.entries[0].variants[0].variantName)
        assertEquals("A — Hot", c.products[0].name)
    }

    // 087 lanjutan (2026-11-01) — bahan per pilihan + porsi; item bersumber modifier menampilkan porsi dari bahan.
    @Test fun `pilihan membawa stok bahan dan item bersumber modifier punya porsi dari bahan`() {
        val rasa = com.warkop.core.network.dto.ModifierGroupDto(
            id = 5, name = "Rasa", isRequired = true, minSelect = 1, maxSelect = 2, portionPerOption = true,
            options = listOf(
                com.warkop.core.network.dto.ModifierOptionDto(50, "SOTO", portions = 17, materials = listOf(
                    com.warkop.core.network.dto.ModifierOptionMaterialDto(9, "Indomie Soto", "pcs", "1.0000", "17.000"))),
                com.warkop.core.network.dto.ModifierOptionDto(51, "AYAM BAWANG", portions = 0, materials = listOf(
                    com.warkop.core.network.dto.ModifierOptionMaterialDto(10, "Indomie Ayam Bawang", "pcs", "1.0000", "-1.000"))),
            ),
        )
        val kuah = ProductDto(id = 789, name = "Indomie Kuah", categoryId = 1, stockSource = "modifier",
            variants = listOf(variant(1, "Standard", "8000.00", stock = 0, track = false)), modifierGroups = listOf(rasa))
        val c = MenuMapper.toCatalog(listOf(kuah, product(2, "Roti", variant(20, "", "5000.00"))), cats)

        val g = c.groups.getValue("5")
        assertTrue(g.portionPerOption)
        assertEquals(17, g.options[0].portions)
        assertEquals(-1.0, g.options[1].materials.single().onHand!!, 0.0)
        assertEquals(17, c.entries.first { it.name == "Indomie Kuah" }.materialPortions)
        assertNull(c.entries.first { it.name == "Roti" }.materialPortions)
        assertNull(c.entries.first { it.name == "Indomie Kuah" }.stock)
    }

    @Test fun `grup tanpa batas atas dari server tetap tanpa batas di tablet`() {
        val topping = com.warkop.core.network.dto.ModifierGroupDto(id = 6, name = "Topping", minSelect = 0, maxSelect = null)
        val c = MenuMapper.toCatalog(listOf(ProductDto(id = 1, name = "Mie", categoryId = 1, variants = listOf(variant(1, "", "1000.00")), modifierGroups = listOf(topping))), cats)
        assertEquals(com.warkop.pos.model.ModGroup.UNLIMITED, c.groups.getValue("6").max)
    }
}
