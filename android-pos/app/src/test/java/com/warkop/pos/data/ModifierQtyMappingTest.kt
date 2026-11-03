package com.warkop.pos.data

import com.warkop.core.network.ApiClient
import com.warkop.core.network.dto.PosCategoryDto
import com.warkop.core.network.dto.ProductDto
import com.warkop.core.network.dto.ReceiptDto
import com.warkop.core.network.dto.TabDto
import com.warkop.core.network.dto.TicketDto
import com.warkop.core.network.dto.VariantDto
import org.junit.Assert.assertEquals
import org.junit.Assert.assertNull
import org.junit.Assert.assertTrue
import org.junit.Test

/** 089 — jumlah per pilihan modifier dari server ke model tablet: batas menu, baris tab, struk, dan KOT. */
class ModifierQtyMappingTest {
    private val json = ApiClient.json

    @Test fun `menu membawa batas jumlah tiap opsi, bawaan 1 sampai 1`() {
        val dto = json.decodeFromString(ProductDto.serializer(), """{"id":1,"name":"Nasi Goreng","category_id":1,
            "variants":[{"id":10,"variant_name":"","sell_price":"20000.00","current_stock":5}],
            "modifier_groups":[{"id":5,"name":"Topping","options":[
              {"id":50,"name":"Telur","price_delta":"3000.00","min_qty":1,"max_qty":3},
              {"id":51,"name":"Keju","price_delta":"2000.00","min_qty":2,"max_qty":null},
              {"id":52,"name":"Kerupuk","price_delta":"1000.00"}]}]}""")
        val cat = MenuMapper.toCatalog(listOf(dto), listOf(PosCategoryDto(1, code = "MK", name = "Makanan", displayOrder = 1)))
        val o = cat.groups.values.single().options
        assertEquals(1 to 3, o[0].minQty to o[0].maxQty); assertTrue(o[0].allowsQty)
        assertEquals(2, o[1].minQty); assertNull(o[1].maxQty); assertTrue(o[1].allowsQty)
        assertEquals(1 to 1, o[2].minQty to o[2].maxQty); assertTrue(!o[2].allowsQty)
    }

    @Test fun `baris tab membawa jumlah pilihan dan labelnya`() {
        val tab = json.decodeFromString(TabDto.serializer(), """{"id":9,"status":"open","lines":[
            {"id":1,"variant_id":3,"name":"Nasi Goreng","qty":1,"unit_price":"26000.00","line_total":"26000.00","modifiers":[
              {"group":"Topping","option":"Telur","qty":2,"label":"Telur ×2","price_delta":"6000.00"},
              {"group":"Pedas","option":"Sedang","price_delta":"0.00"}]}]}""")
        val line = OrderMapper.tab(tab, myDeviceId = null).lines.single()
        assertEquals(listOf(2, 1), line.mods.map { it.qty })
        assertEquals("Telur ×2, Sedang", line.modLabel)
        assertEquals(26000, line.lineTotal)
    }

    @Test fun `struk memakai label server, atau menyusunnya dari jumlah bila server lama`() {
        fun item(mods: String) = json.decodeFromString(ReceiptDto.serializer(), """{"store_name":"W","order_number":"O-1","receipt_no":"1",
            "subtotal":"1.00","total_amount":"1.00","items":[{"name":"Nasi","qty":1,"price":"1.00","line_total":"1.00","modifiers":$mods}]}""")
        assertEquals(listOf("Telur ×2", "Sedang"), ReceiptMapper.map(item("""[{"option":"Telur","qty":2,"label":"Telur ×2"},{"option":"Sedang"}]""")).items.single().mods)
        assertEquals(listOf("Telur ×3"), ReceiptMapper.map(item("""[{"option":"Telur","qty":3}]""")).items.single().mods)
    }

    @Test fun `KOT mencetak jumlah pilihan di dalam Grup Pilihan`() {
        val kot = json.decodeFromString(TicketDto.serializer(), """{"id":7,"items":[{"qty":1,"name":"Nasi","modifiers":[
            {"group":"Topping","option":"Telur","qty":2,"label":"Telur ×2","price_delta":"6000.00"},
            {"group":"","option":"Sedang"}]}]}""")
        assertEquals(listOf("Topping: Telur ×2", "Sedang"), TicketMapper.kot(kot).items.single().modifiers)
    }

    @Test fun `jumlah ≠ 1 dikirim sebagai modifier_selections, selain itu daftar id lama`() {
        val counted = addLineBody(3, 1, "", listOf(10, 20), mapOf(10L to 1, 20L to 2))
        assertTrue(counted.modifierOptionIds.isEmpty())
        assertEquals(listOf(10L to 1, 20L to 2), counted.modifierSelections!!.map { it.optionId to it.qty })

        val plain = addLineBody(3, 1, "catatan", listOf(10, 20), mapOf(10L to 1, 20L to 1))
        assertEquals(listOf(10L, 20L), plain.modifierOptionIds)
        assertNull(plain.modifierSelections)
        assertEquals("catatan", plain.note)

        val none = addLineBody(3, 2, " ", emptyList(), emptyMap())
        assertTrue(none.modifierOptionIds.isEmpty()); assertNull(none.modifierSelections); assertNull(none.note)
    }
}
