package com.warkop.core.network

import com.warkop.core.network.dto.MaterialMovementPage
import com.warkop.core.network.dto.MaterialPage
import com.warkop.core.network.dto.ProductPage
import com.warkop.core.network.dto.SplitItemsResponseDto
import com.warkop.core.network.dto.TabDto
import org.junit.Assert.*
import org.junit.Test

/** 087 lanjutan (2026-11-01) — bentuk kawat stok bahan untuk tablet: pilihan modifier, peringatan minus, Stok Bahan. */
class MaterialDtosTest {
    private val json = ApiClient.json

    @Test fun `opsi modifier membawa bahan, porsi, dan grup membawa tiap pilihan = 1 porsi`() {
        val page = json.decodeFromString(ProductPage.serializer(), """
            {"data":[{"id":789,"name":"Indomie Kuah","stock_source":"modifier","variants":[],
              "modifier_groups":[{"id":5,"name":"Rasa","is_required":true,"min_select":1,"max_select":2,"portion_per_option":true,
                "options":[{"id":50,"name":"SOTO","price_delta":"0.00","portions":17,
                  "materials":[{"material_id":9,"name":"Indomie Soto","unit":"pcs","quantity":"1.0000","on_hand":"17.000"}]},
                  {"id":51,"name":"Sedang","price_delta":"0.00","portions":null,"materials":[]}]}]}],
             "meta":{"current_page":1,"last_page":1}}
        """.trimIndent())
        val p = page.data.single()
        assertEquals("modifier", p.stockSource)
        val g = p.modifierGroups.single()
        assertTrue(g.portionPerOption)
        assertEquals(17, g.options[0].portions)
        assertEquals("17.000", g.options[0].materials.single().onHand)
        assertNull(g.options[1].portions)
    }

    @Test fun `server lama tanpa bidang baru tetap terbaca dengan bawaan aman`() {
        val page = json.decodeFromString(ProductPage.serializer(), """{"data":[{"id":1,"name":"Kopi","modifier_groups":[{"id":1,"name":"Gula","options":[{"id":2,"name":"Normal"}]}]}]}""")
        val p = page.data.single()
        assertEquals("variant", p.stockSource)
        assertFalse(p.modifierGroups.single().portionPerOption)
        assertNull(p.modifierGroups.single().options.single().portions)
    }

    @Test fun `pelunasan dan split membawa peringatan bahan minus di tingkat atas`() {
        val w = """{"material_id":10,"material":"Indomie Ayam Bawang","unit":"pcs","outlet_id":14,"outlet":"Warkop WJM Demo","stock_before":"0.000","required":"1.000","stock_after":"-1.000"}"""
        val tab = json.decodeFromString(TabDto.serializer(), """{"id":7,"material_warnings":[$w]}""")
        assertEquals("Warkop WJM Demo", tab.materialWarnings.single().outlet)
        assertEquals("-1.000", tab.materialWarnings.single().stockAfter)
        val split = json.decodeFromString(SplitItemsResponseDto.serializer(), """{"paid_order":{"id":8},"tab":{"id":7},"material_warnings":[$w]}""")
        assertEquals(1, split.materialWarnings.size)
        assertTrue(json.decodeFromString(TabDto.serializer(), """{"id":7}""").materialWarnings.isEmpty())
    }

    @Test fun `daftar bahan dan buku besar terbaca`() {
        val list = json.decodeFromString(MaterialPage.serializer(), """
            {"data":[{"id":10,"code":"INDM-AB","name":"Indomie Ayam Bawang","unit":"pcs","kind":"packaging","is_active":true,
              "current_stock":"14.000","outlet_stock":[{"outlet_id":14,"outlet_code":"WDM","outlet_name":"Warkop WJM Demo","on_hand":"-1.000"}]}],
             "meta":{"current_page":1,"last_page":1}}
        """.trimIndent())
        assertEquals("-1.000", list.data.single().outletStock.single().onHand)
        val moves = json.decodeFromString(MaterialMovementPage.serializer(), """
            {"data":[{"id":7,"material_id":10,"material_code":"INDM-AB","material_name":"Indomie Ayam Bawang","unit":"pcs","type":"sale",
              "outlet_id":14,"outlet_code":"WDM","qty_change":"-1.000","stock_before":"0.000","stock_after":"-1.000",
              "reference":{"type":"order_item","id":6174,"order_id":3,"order_number":"TRX-20261031-0003"},"reason":null,
              "user":{"id":1,"name":"Kasir A"},"created_at":"2026-10-31T13:58:33+00:00"}],
             "meta":{"current_page":1,"last_page":1}}
        """.trimIndent())
        val m = moves.data.single()
        assertEquals("TRX-20261031-0003", m.reference?.orderNumber)
        assertEquals("Kasir A", m.user?.name)
    }

    // 2026-11-01 — BUG: `max_select: null` (tanpa batas) dulu dipaksa coerceInputValues menjadi 1.
    @Test fun `max_select null tetap null (tanpa batas), kunci hilang tetap 1`() {
        val g = json.decodeFromString(ProductPage.serializer(), """{"data":[{"id":1,"name":"Mie","modifier_groups":[
            {"id":1,"name":"Topping","max_select":null,"options":[]},{"id":2,"name":"Gula","options":[]}]}]}""").data.single().modifierGroups
        assertNull(g[0].maxSelect)
        assertEquals(1, g[1].maxSelect)
    }
}
