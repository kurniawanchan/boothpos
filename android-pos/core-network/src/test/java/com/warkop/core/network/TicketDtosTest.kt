package com.warkop.core.network

import com.warkop.core.network.dto.SendResponseDto
import org.junit.Assert.assertEquals
import org.junit.Assert.assertTrue
import org.junit.Test

/**
 * 050 / T019 — respons kirim ke dapur: `tickets` terbaca per stasiun, dan respons lama tanpa `tickets` tetap terbaca
 * (bawaan kosong). Modifier dari server membawa `price_delta`, tetapi itu tidak dipakai untuk KOT.
 */
class TicketDtosTest {
    private val json = ApiClient.json

    private val tabJson = """{"id":118,"order_number":"O-118","status":"open","subtotal":"0.00","discount_amount":"0.00",
        "tax_amount":"0.00","tax_rate":"0.00","total_amount":"0.00","paid_amount":"0.00","change_amount":"0.00",
        "balance":"0.00","lines":[]}"""

    @Test fun `respons kirim membaca tiket per stasiun`() {
        val r = json.decodeFromString(SendResponseDto.serializer(), """{
            "tab": $tabJson,
            "tickets": [{
                "id": 2201, "round_no": 1, "sent_at": "2026-10-16T10:42:00+07:00",
                "station": {"id": 3, "name": "Dapur"}, "order": {"id": 118, "order_number": "O-118"},
                "table": {"id": 4, "name": "Meja 4"}, "tab_label": null,
                "items": [{"id": 9, "name": "Kopi Susu", "qty": 2,
                    "modifiers": [{"group": "Gula", "option": "Less", "price_delta": "0.00"}],
                    "note": "tanpa es", "status": "new", "cancel_reason": null}]
            }]
        }""")
        assertEquals(1, r.tickets.size)
        val ticket = r.tickets.first()
        assertEquals(2201L, ticket.id)
        assertEquals("Dapur", ticket.station?.name)
        assertEquals("Meja 4", ticket.table?.name)
        assertEquals("Kopi Susu", ticket.items.first().name)
        assertEquals("Gula", ticket.items.first().modifiers.first().group)
        assertEquals("Less", ticket.items.first().modifiers.first().option)
        assertEquals("tanpa es", ticket.items.first().note)
    }

    @Test fun `respons lama tanpa tiket tetap terbaca dengan daftar kosong`() {
        val r = json.decodeFromString(SendResponseDto.serializer(), """{"tab": $tabJson}""")
        assertTrue(r.tickets.isEmpty())
        assertEquals("O-118", r.tab.orderNumber)
    }

    @Test fun `item tanpa catatan dan modifier tetap terbaca`() {
        val r = json.decodeFromString(SendResponseDto.serializer(), """{
            "tab": $tabJson,
            "tickets": [{"id": 5, "items": [{"id": 1, "name": "Roti", "qty": 1}]}]
        }""")
        val item = r.tickets.first().items.first()
        assertTrue(item.modifiers.isEmpty())
        assertEquals(null, item.note)
    }
}
