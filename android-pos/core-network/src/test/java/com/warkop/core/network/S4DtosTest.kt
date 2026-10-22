package com.warkop.core.network

import com.warkop.core.network.dto.DiscountRequestDto
import com.warkop.core.network.dto.RefundRequestDto
import com.warkop.core.network.dto.ReprintRequestDto
import com.warkop.core.network.dto.ReprintResponseDto
import com.warkop.core.network.dto.SplitEqualRequestDto
import com.warkop.core.network.dto.SplitEqualResponseDto
import com.warkop.core.network.dto.SplitItemDto
import com.warkop.core.network.dto.SplitItemsRequestDto
import com.warkop.core.network.dto.SplitItemsResponseDto
import com.warkop.core.network.dto.PaymentInputDto
import org.junit.Assert.assertEquals
import org.junit.Assert.assertFalse
import org.junit.Assert.assertTrue
import org.junit.Test

/** 048 / S4 (US5) — bentuk kawat diskon, bagi rata, cetak ulang, dan refund sesuai kontrak api-new.md §S4. */
class S4DtosTest {
    private val json = ApiClient.json

    @Test fun `diskon baris membawa scope line, line_id, dan alasan, tanpa token`() {
        val body = json.encodeToString(DiscountRequestDto.serializer(), DiscountRequestDto(percent = 10.0, reasonCode = "promo_outlet", scope = "line", lineId = 3))
        assertTrue(body, body.contains("\"scope\":\"line\""))
        assertTrue(body, body.contains("\"line_id\":3"))
        assertTrue(body, body.contains("\"reason_code\":\"promo_outlet\""))
        assertFalse(body, body.contains("override_token"))
    }

    @Test fun `diskon order tanpa line_id, membawa token bila sudah disetujui`() {
        val body = json.encodeToString(DiscountRequestDto.serializer(), DiscountRequestDto(percent = 20.0, reasonCode = "karyawan", scope = "order", overrideToken = "tok"))
        assertTrue(body, body.contains("\"scope\":\"order\""))
        assertFalse(body, body.contains("line_id"))
        assertTrue(body, body.contains("\"override_token\":\"tok\""))
    }

    @Test fun `bagi rata selalu mengirim mode equal dan jumlah bagian`() {
        val body = json.encodeToString(SplitEqualRequestDto.serializer(), SplitEqualRequestDto(clientRef = "k-1", mode = "equal", parts = 3))
        assertEquals("{\"client_ref\":\"k-1\",\"mode\":\"equal\",\"parts\":3}", body)
    }

    @Test fun `respons bagi rata berupa objek index dan amount, sisa pembulatan di bagian terakhir`() {
        val r = json.decodeFromString(SplitEqualResponseDto.serializer(), """{"parts":[{"index":1,"amount":"3333.00"},{"index":2,"amount":"3333.00"},{"index":3,"amount":"3334.00"}]}""")
        assertEquals(3, r.parts.size)
        assertEquals(1, r.parts[0].index)
        assertEquals("3333.00", r.parts[0].amount)
        assertEquals("3334.00", r.parts[2].amount)
    }

    @Test fun `salinan pertama cetak ulang dikirim tanpa token, respons membaca copy_no`() {
        // 057 — `copy_no` wajib (nomor salinan dari server); tanpa token persetujuan untuk salinan pertama.
        assertEquals("{\"copy_no\":2}", json.encodeToString(ReprintRequestDto.serializer(), ReprintRequestDto(copyNo = 2)))
        val r = json.decodeFromString(ReprintResponseDto.serializer(), """{"copy_no":2}""")
        assertEquals(2, r.copyNo)
    }

    @Test fun `refund membawa alasan, catatan, dan token persetujuan`() {
        val body = json.encodeToString(RefundRequestDto.serializer(), RefundRequestDto(reasonCode = "salah_input", reason = "Pesanan ganda", overrideToken = "tok-r"))
        assertTrue(body, body.contains("\"reason_code\":\"salah_input\""))
        assertTrue(body, body.contains("\"reason\":\"Pesanan ganda\""))
        assertTrue(body, body.contains("\"override_token\":\"tok-r\""))
    }

    @Test fun `pembayaran tab membaca paid_at dari server untuk tanggal struk`() {
        val t = json.decodeFromString(com.warkop.core.network.dto.TabPaymentDto.serializer(), """{"id":2031,"method":"cash","amount":"66000.00","paid_at":"2026-10-16T09:23:54+00:00"}""")
        assertEquals("2026-10-16T09:23:54+00:00", t.paidAt)
    }

    @Test fun `bagi per item membawa baris yang dipindah dan pembayaran tunai`() {
        val body = json.encodeToString(
            SplitItemsRequestDto.serializer(),
            SplitItemsRequestDto(clientRef = "k-2", items = listOf(SplitItemDto(itemId = 6046, qty = 2)), payments = listOf(PaymentInputDto("cash", "16000.00"))),
        )
        assertEquals("{\"client_ref\":\"k-2\",\"items\":[{\"item_id\":6046,\"qty\":2}],\"payments\":[{\"method\":\"cash\",\"amount\":\"16000.00\"}]}", body)
    }

    @Test fun `respons bagi per item membaca bagian dibayar dan sisa dari server`() {
        val r = json.decodeFromString(SplitItemsResponseDto.serializer(), """{"paid_order":{"id":2050,"total_amount":"16000.00"},"tab":{"id":2044,"total_amount":"8000.00"}}""")
        assertEquals(2050L, r.paidOrder.id)
        assertEquals("16000.00", r.paidOrder.totalAmount)
        assertEquals("8000.00", r.tab.totalAmount)
    }
}
