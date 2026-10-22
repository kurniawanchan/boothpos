package com.warkop.pos.data

import com.warkop.core.network.ApiClient
import com.warkop.core.network.dto.ReceiptDto
import org.junit.Assert.assertEquals
import org.junit.Assert.assertNull
import org.junit.Test

/** 048 / T038 — pemetaan struk server: nomor, antrean, pajak, total, dan pembayaran dibaca apa adanya. */
class ReceiptMapperTest {
    private fun dto(json: String) = ApiClient.json.decodeFromString(ReceiptDto.serializer(), json)

    @Test fun `struk membawa nomor, antrean, pajak, dan total dari server`() {
        val r = ReceiptMapper.map(dto("""{
            "store_name":"Warkop WJM 3","order_number":"O-2050","receipt_no":"000014","queue_no":"A-025","terminal_name":"Kasir Depan",
            "cashier_name":"Rina","tax_amount":"5400.00","tax_rate":"10.00","tax_label":"PPN",
            "subtotal":"60000.00","discount_amount":"6000.00","total_amount":"59400.00","paid_amount":"59400.00","change_amount":"0.00",
            "items":[{"name":"Kopi Susu","qty":5,"price":"12000.00","line_total":"60000.00","note":null}],
            "payment_summary":[{"method":"cash","amount":"59400.00","paid_at":"2026-10-16T10:42:15+00:00"}]
        }"""))
        assertEquals("000014", r.receiptNo)
        assertEquals("Kasir Depan", r.terminalName)
        assertEquals("A-025", r.queueNo)
        assertEquals(5400, r.tax)
        assertEquals("10", r.taxRatePercent)
        assertEquals("PPN", r.taxLabel)
        assertEquals(60000, r.subtotal)
        assertEquals(6000, r.discount)
        assertEquals(59400, r.total)
        assertEquals(59400, r.paid)
        assertEquals("16/10/2026 17:42", r.paidAtText)
        assertEquals(1, r.items.size)
        assertEquals(5, r.items[0].qty)
        assertEquals(listOf("Tunai" to 59400), r.payments)
    }

    @Test fun `nomor dan antrean yang belum ada tetap null, tidak diganti nilai tetap`() {
        val r = ReceiptMapper.map(dto("""{"order_number":"O-1","receipt_no":null,"queue_no":null,"payment_summary":[]}"""))
        assertNull(r.receiptNo)
        assertNull(r.queueNo)
        assertNull(r.paidAtText)
        assertEquals("PPN", r.taxLabel)
        assertEquals("0", r.taxRatePercent)
    }

    @Test fun `label metode pembayaran diterjemahkan`() {
        assertEquals("EDC", ReceiptMapper.methodLabel("edc"))
        assertEquals("QRIS", ReceiptMapper.methodLabel("qr_ewallet"))
        assertEquals("xyz", ReceiptMapper.methodLabel("xyz"))
    }
}
