package com.warkop.core.network

import com.warkop.core.network.dto.CancelTabRequestDto
import com.warkop.core.network.dto.FailPaymentRequestDto
import com.warkop.core.network.dto.PaymentChannelsPage
import com.warkop.core.network.dto.PaymentInputDto
import com.warkop.core.network.dto.ReasonCodesPage
import com.warkop.core.network.dto.SoldOutRequestDto
import com.warkop.core.network.dto.VerifyPaymentRequestDto
import com.warkop.core.network.dto.VoidLineRequestDto
import org.junit.Assert.*
import org.junit.Test

/** 048 / T056 — bentuk kawat aksi tab, 86, dan pembayaran EDC/QRIS. */
class ActionDtosTest {
    private val json = ApiClient.json

    @Test fun `void baris terkirim membawa alasan, status dapur, keputusan stok, dan token`() {
        val body = json.encodeToString(VoidLineRequestDto.serializer(), VoidLineRequestDto(
            reasonCode = "wrong_item", kitchenStatus = "made", stockDecision = "waste", overrideToken = "tok-v",
        ))
        assertEquals("{\"reason_code\":\"wrong_item\",\"kitchen_status\":\"made\",\"stock_decision\":\"waste\",\"override_token\":\"tok-v\"}", body)
    }

    @Test fun `void tanpa alasan bebas tidak mengirim reason`() {
        val body = json.encodeToString(VoidLineRequestDto.serializer(), VoidLineRequestDto(reasonCode = "other", kitchenStatus = "not_started", stockDecision = "return"))
        assertFalse(body, body.contains("reason\""))
    }

    @Test fun `batal tab membawa reason_code dan token`() {
        val body = json.encodeToString(CancelTabRequestDto.serializer(), CancelTabRequestDto(reasonCode = "customer_request", reason = "Pelanggan pergi", overrideToken = "t"))
        assertTrue(body, body.contains("\"reason_code\":\"customer_request\""))
        assertTrue(body, body.contains("\"reason\":\"Pelanggan pergi\""))
    }

    @Test fun `86 membawa reason_code_id dan daftar outlet bila ada`() {
        val body = json.encodeToString(SoldOutRequestDto.serializer(), SoldOutRequestDto(reasonCodeId = 7, outletIds = listOf(4)))
        assertEquals("{\"reason_code_id\":7,\"outlet_ids\":[4]}", body)
    }

    @Test fun `pembayaran EDC membawa kode approval dan konfirmasi duplikat hanya bila diisi`() {
        val edc = json.encodeToString(PaymentInputDto.serializer(), PaymentInputDto("edc", "50000.00", approvalCode = "ABC123", confirmDuplicate = true))
        assertTrue(edc, edc.contains("\"approval_code\":\"ABC123\""))
        assertTrue(edc, edc.contains("\"confirm_duplicate\":true"))
        val tunai = json.encodeToString(PaymentInputDto.serializer(), PaymentInputDto("cash", "8000.00"))
        assertEquals("{\"method\":\"cash\",\"amount\":\"8000.00\"}", tunai)
    }

    @Test fun `verifikasi QRIS dan tandai tidak ditemukan membawa referensi dan token`() {
        val v = json.encodeToString(VerifyPaymentRequestDto.serializer(), VerifyPaymentRequestDto("REF12345", "tok-q"))
        assertEquals("{\"reference\":\"REF12345\",\"override_token\":\"tok-q\"}", v)
        assertEquals("{}", json.encodeToString(FailPaymentRequestDto.serializer(), FailPaymentRequestDto()))
    }

    @Test fun `daftar alasan dan kanal pembayaran terbaca`() {
        val r = json.decodeFromString(ReasonCodesPage.serializer(), """{"data":[{"id":3,"category":"sold_out","code":"habis_bahan","label":"Bahan habis","is_active":true,"sort":1}]}""")
        assertEquals(3L, r.data.single().id)
        val c = json.decodeFromString(PaymentChannelsPage.serializer(), """{"data":[{"id":9,"type":"qr_ewallet","provider":"GoPay","account_name":"Warkop","account_number":"xx"}]}""")
        assertEquals("qr_ewallet", c.data.single().type)
    }

    @Test fun `kode galat aksi tab dan pembayaran dipetakan ke teks Indonesia`() {
        assertTrue(localMessage("duplicate_edc")!!.contains("2 menit"))
        assertTrue(localMessage("pending_qris")!!.contains("QRIS"))
        assertTrue(localMessage("has_payments")!!.contains("void per item"))
    }
}
