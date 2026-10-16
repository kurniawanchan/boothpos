package com.warkop.core.network

import com.warkop.core.network.dto.ReceiptDto
import org.junit.Assert.assertEquals
import org.junit.Assert.assertNull
import org.junit.Test

/** 048 / T038 — bentuk kawat struk: field baru (receipt_no, queue_no, tax) terbaca; yang belum ada bernilai null. */
class ReceiptDtoTest {
    private val json = ApiClient.json

    @Test fun `struk membaca nomor struk, antrean, dan pajak`() {
        val r = json.decodeFromString(ReceiptDto.serializer(), """{"receipt_no":"T01-000014","queue_no":"A-025","tax_amount":"5400.00","tax_rate":"10.00","total_amount":"59400.00"}""")
        assertEquals("T01-000014", r.receiptNo)
        assertEquals("A-025", r.queueNo)
        assertEquals("5400.00", r.taxAmount)
        assertEquals("59400.00", r.totalAmount)
    }

    @Test fun `struk tanpa nomor antrean membaca null`() {
        val r = json.decodeFromString(ReceiptDto.serializer(), """{"receipt_no":null,"queue_no":null}""")
        assertNull(r.queueNo)
        assertNull(r.receiptNo)
    }
}
