package com.warkop.core.network

import com.warkop.core.network.dto.AddLineRequestDto
import com.warkop.core.network.dto.ModifierSelectionDto
import com.warkop.core.network.dto.ProductPage
import com.warkop.core.network.dto.TabDto
import org.junit.Assert.*
import org.junit.Test

/** 089 — bentuk kawat jumlah per pilihan modifier: batas min/maks dari menu, jumlah di baris, dan badan permintaan tambah baris. */
class ModifierQtyDtosTest {
    private val json = ApiClient.json

    @Test fun `opsi membawa min dan maks jumlah, maks null berarti tanpa batas atas`() {
        val page = json.decodeFromString(ProductPage.serializer(), """
            {"data":[{"id":1,"name":"Nasi Goreng","modifier_groups":[{"id":5,"name":"Topping","options":[
              {"id":50,"name":"Telur","min_qty":1,"max_qty":3},
              {"id":51,"name":"Keju","min_qty":2,"max_qty":null},
              {"id":52,"name":"Kerupuk","min_qty":1,"max_qty":1}]}]}]}
        """.trimIndent())
        val o = page.data.single().modifierGroups.single().options
        assertEquals(1 to 3, o[0].minQty to o[0].maxQty)
        assertEquals(2, o[1].minQty); assertNull(o[1].maxQty)       // null eksplisit tetap null (tanpa batas atas)
        assertEquals(1 to 1, o[2].minQty to o[2].maxQty)
    }

    @Test fun `server lama tanpa kunci jumlah tetap terbaca sebagai 1 sampai 1`() {
        val page = json.decodeFromString(ProductPage.serializer(), """{"data":[{"id":1,"name":"Kopi","modifier_groups":[{"id":1,"name":"Gula","options":[{"id":2,"name":"Normal"}]}]}]}""")
        val o = page.data.single().modifierGroups.single().options.single()
        assertEquals(1, o.minQty)
        assertEquals(1, o.maxQty)
    }

    @Test fun `baris tab membawa jumlah dan label pilihan, server lama dibaca jumlah 1`() {
        val tab = json.decodeFromString(TabDto.serializer(), """{"id":9,"status":"open","lines":[
            {"id":1,"variant_id":3,"name":"Nasi Goreng","qty":1,"modifiers":[
              {"group":"Topping","option":"Telur","qty":2,"label":"Telur ×2","price_delta":"6000.00"},
              {"group":"Pedas","option":"Sedang","price_delta":"0.00"}]}]}""")
        val m = tab.lines.single().modifiers
        assertEquals(2, m[0].qty); assertEquals("Telur ×2", m[0].label)
        assertEquals(1, m[1].qty); assertNull(m[1].label)
    }

    @Test fun `badan tambah baris lama hanya berisi daftar id dan tidak membawa modifier_selections`() {
        val body = json.encodeToString(AddLineRequestDto.serializer(), AddLineRequestDto(variantId = 3, qty = 1, modifierOptionIds = listOf(10, 20)))
        assertTrue(body.contains("\"modifier_option_ids\":[10,20]"))
        assertFalse(body.contains("modifier_selections"))
    }

    @Test fun `badan dengan jumlah hanya berisi modifier_selections, tanpa modifier_option_ids (server menolak keduanya)`() {
        val body = json.encodeToString(
            AddLineRequestDto.serializer(),
            AddLineRequestDto(variantId = 3, qty = 1, modifierSelections = listOf(ModifierSelectionDto(10, 1), ModifierSelectionDto(20, 2))),
        )
        assertTrue(body.contains("\"modifier_selections\":[{\"option_id\":10,\"qty\":1},{\"option_id\":20,\"qty\":2}]"))
        assertFalse(body.contains("modifier_option_ids"))
    }
}
