package com.warkop.pos.data

import com.warkop.core.network.dto.PosCategoryDto
import com.warkop.core.network.dto.ProductDto
import com.warkop.core.network.dto.StockLimitingDto
import com.warkop.core.network.dto.VariantDto
import kotlinx.serialization.json.Json
import org.junit.Assert.assertEquals
import org.junit.Assert.assertNull
import org.junit.Test

/**
 * 092 US4 — stok menu beresep dihitung SERVER dan dikirim lewat `outlet_stock`/`track_stock` yang sudah dibaca tablet;
 * Kotlin tidak menghitung ulang dari bahan. Bidang baru (`stock_basis`, `stock_limiting`) hanya tambahan, jadi payload
 * LAMA tanpa bidang itu harus tetap terbaca persis seperti dulu.
 */
class MenuMapperRecipeStockTest {
    private val cats = listOf(PosCategoryDto(1, code = "MK", name = "Makanan", displayOrder = 1))
    private val json = Json { ignoreUnknownKeys = true }

    private fun map(v: VariantDto) = MenuMapper.toCatalog(listOf(ProductDto(id = 1, name = "Nasi Goreng", categoryId = 1, variants = listOf(v))), cats)

    @Test fun `varian beresep memakai stok menu dari server, bukan stok varian mentah`() {
        val c = map(VariantDto(id = 10, sellPrice = "20000.00", currentStock = 999, outletStock = 10, trackStock = true,
            stockBasis = "recipe", stockLimiting = StockLimitingDto(name = "Telur", unit = "butir", onHand = "10.000", perPortion = "1.000")))
        val p = c.products.single()
        assertEquals(10, p.stock)
        assertEquals("recipe", p.stockBasis)
        assertEquals("Telur", p.limitingMaterial)
        assertEquals(10, c.entries.single().stock)
    }

    @Test fun `stok menu 0 tetap angka 0 bukan tanpa stok`() {
        val c = map(VariantDto(id = 10, sellPrice = "20000.00", currentStock = 5, outletStock = 0, trackStock = true, stockBasis = "recipe"))
        assertEquals(0, c.products.single().stock)
    }

    @Test fun `menu beresep tanpa bahan pembatas aktif tidak punya angka`() {
        val c = map(VariantDto(id = 10, sellPrice = "20000.00", currentStock = 5, outletStock = null, trackStock = false, stockBasis = "recipe"))
        assertNull(c.products.single().stock)
    }

    @Test fun `payload lama tanpa bidang baru terbaca seperti dulu`() {
        val old = json.decodeFromString(VariantDto.serializer(), """{"id":10,"sell_price":"20000.00","current_stock":4,"outlet_stock":3,"track_stock":true}""")
        assertEquals("variant", old.stockBasis)
        assertNull(old.stockLimiting)
        val p = map(old).products.single()
        assertEquals(3, p.stock)
        assertEquals("variant", p.stockBasis)
        assertNull(p.limitingMaterial)
    }

    @Test fun `payload server baru terbaca lengkap`() {
        val v = json.decodeFromString(VariantDto.serializer(),
            """{"id":10,"sell_price":"20000.00","current_stock":999,"outlet_stock":10,"track_stock":true,"stock_basis":"recipe","stock_limiting":{"material_id":7,"name":"Telur","unit":"butir","on_hand":"10.000","per_portion":"1.000"}}""")
        assertEquals("recipe", v.stockBasis)
        assertEquals("Telur", v.stockLimiting?.name)
        assertEquals(10, map(v).products.single().stock)
    }

    // 093 — bahan penghambat bisa berupa PRODUK (varian bahan): `material_id` null, `variant_id` + `ingredient_type` terisi.
    // Tablet tidak membaca kunci baru dan tidak menghitung stok sendiri: angka tetap `outlet_stock` dari server.
    @Test fun `bahan penghambat berupa produk terbaca dan angkanya tetap dari server`() {
        val v = json.decodeFromString(VariantDto.serializer(),
            """{"id":10,"sell_price":"20000.00","current_stock":999,"outlet_stock":45,"track_stock":true,"stock_basis":"recipe","stock_limiting":{"ingredient_type":"variant","material_id":null,"variant_id":77,"name":"Telur","unit":"pcs","on_hand":"45.000","per_portion":"1.000"}}""")
        assertEquals("Telur", v.stockLimiting?.name)
        val p = map(v).products.single()
        assertEquals(45, p.stock)
        assertEquals("Telur", p.limitingMaterial)
    }

    @Test fun `kartu produk bahan memakai outlet_stock server yang sudah dikurangi tahanan`() {
        val v = json.decodeFromString(VariantDto.serializer(),
            """{"id":20,"sell_price":"3000.00","current_stock":50,"outlet_stock":45,"track_stock":true,"stock_basis":"variant"}""")
        val c = map(v)
        assertEquals(45, c.products.single().stock)
        assertEquals("variant", c.products.single().stockBasis)
    }
}
