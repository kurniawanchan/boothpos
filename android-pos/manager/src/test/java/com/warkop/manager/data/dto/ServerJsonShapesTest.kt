package com.warkop.manager.data.dto

import com.warkop.core.network.ApiClient
import org.junit.Assert.assertEquals
import org.junit.Assert.assertNull
import org.junit.Test

/**
 * 094 — bentuk JSON NYATA dari server dev (bukan contoh rekaan). Larik PHP kosong dikodekan `[]`, jadi kolom objek
 * yang bisa kosong harus diterima sebagai array juga.
 */
class ServerJsonShapesTest {
    @Test fun `attention - query kosong berupa array tidak menggagalkan respons (bug 2026-11-25)`() {
        // Keluaran AttentionService::items di DB dev, 2026-11-25 (disingkat, kunci tetap).
        val json = """{"computed_at":"2026-11-25T21:42:25+07:00","business_date":"2026-11-25","count":0,"items":[
            {"kind":"payments_pending","severity":"ok","count":0,"amount":"0.00","meta":{"by_method":[]},"link":{"route":"sales","query":{"pstate":"pending"}}},
            {"kind":"void_after_fire","severity":"ok","count":0,"amount":"0.00","meta":{"share_of_gross_pct":null},"link":{"route":"activity-log","query":{"action":"item_cancelled","date":"2026-11-25"}},"amount_unknown_count":0},
            {"kind":"cash_difference","severity":"ok","count":0,"amount":"0.00","meta":{"tolerance":"5000.00","session_id":null},"link":{"route":"session","query":[]}},
            {"kind":"low_stock","severity":"ok","count":0,"amount":null,"meta":[],"link":{"route":"stock","query":{"low":1}}}]}"""
        val dto = ApiClient.json.decodeFromString(AttentionDto.serializer(), json)
        assertEquals(4, dto.items.size)
        assertEquals("session", dto.items[2].link?.route)
        assertNull(dto.items[3].amount)
    }

    @Test fun `angka tanpa cast diterima sebagai angka atau string`() {
        val v = ApiClient.json.decodeFromString(ProductVariantDto.serializer(), """{"id":1,"low_stock_alert":"5","outlet_stock":2.5}""")
        assertEquals(5, v.lowStockAlert)
        assertEquals(2, v.outletStock)
        val d = ApiClient.json.decodeFromString(ProductDetailDto.serializer(), """{"id":1,"name":"A","prep_minutes":null}""")
        assertNull(d.prepMinutes)
    }
}
