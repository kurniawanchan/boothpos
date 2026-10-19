package com.warkop.core.network.dto

import kotlinx.serialization.SerialName
import kotlinx.serialization.Serializable

// 048 / S3 — aksi tab, 86, pembayaran EDC/QRIS. Nilai field harus sama dengan `subject` pada POST /overrides;
// field null tidak dikirim (ApiClient: explicitNulls = false), dan subject juga tidak memuatnya.

/** DELETE /tabs/{id}/items/{item} (baris terkirim). Badan DELETE dikirim lewat @HTTP(hasBody = true). */
@Serializable data class VoidLineRequestDto(
    @SerialName("reason_code") val reasonCode: String? = null,
    @SerialName("kitchen_status") val kitchenStatus: String? = null,
    @SerialName("stock_decision") val stockDecision: String? = null,
    val reason: String? = null,
    @SerialName("override_token") val overrideToken: String? = null,
)

@Serializable data class CancelTabRequestDto(
    @SerialName("reason_code") val reasonCode: String,
    val reason: String? = null,
    @SerialName("override_token") val overrideToken: String? = null,
)

@Serializable data class SoldOutRequestDto(
    @SerialName("reason_code_id") val reasonCodeId: Long,
    val note: String? = null,
    @SerialName("outlet_ids") val outletIds: List<Long>? = null,
    @SerialName("override_token") val overrideToken: String? = null,
)

@Serializable data class VerifyPaymentRequestDto(
    val reference: String,
    @SerialName("override_token") val overrideToken: String? = null,
)

@Serializable data class FailPaymentRequestDto(
    @SerialName("override_token") val overrideToken: String? = null,
)

/** Kode alasan dari GET /reason-codes?category=… (id dipakai sebagai reason_code_id untuk 86). */
@Serializable data class ReasonCodeDto(
    val id: Long,
    val category: String = "",
    val code: String = "",
    val label: String = "",
    @SerialName("is_active") val isActive: Boolean = true,
    val sort: Int = 0,
)

@Serializable data class ReasonCodesPage(val data: List<ReasonCodeDto> = emptyList())

@Serializable data class PaymentChannelDto(
    val id: Long,
    val type: String = "",
    val provider: String? = null,
    @SerialName("account_name") val accountName: String? = null,
    // 056 / T003 — URL gambar QR statis dari server; null bila belum diunggah.
    @SerialName("qr_image_url") val qrImageUrl: String? = null,
    // 056 amandemen — pengguna berhak mengganti QR (menu settings); tablet menampilkan kontrol hanya bila true.
    @SerialName("can_manage_qr") val canManageQr: Boolean = false,
)

@Serializable data class PaymentChannelsPage(val data: List<PaymentChannelDto> = emptyList())
