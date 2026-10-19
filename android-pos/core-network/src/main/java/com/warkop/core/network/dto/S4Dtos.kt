package com.warkop.core.network.dto

import kotlinx.serialization.SerialName
import kotlinx.serialization.Serializable

/**
 * 048 / S4 (US5) — diskon order atau satu baris. Kontrak: contracts/api-new.md §S4.
 * Batas per peran ditegakkan server; di atas batas tanpa [overrideToken] → 409 `override_required`.
 * [scope] = "order" atau "line"; [lineId] hanya untuk "line". Persentase tidak dihitung ulang di klien.
 */
@Serializable data class DiscountRequestDto(
    val percent: Double,
    @SerialName("reason_code") val reasonCode: String,
    val scope: String,
    @SerialName("line_id") val lineId: Int? = null,
    @SerialName("override_token") val overrideToken: String? = null,
)

/**
 * 048 / S4 — bagi rata tanpa pembayaran. `mode` TIDAK diberi nilai bawaan: klien memakai
 * `encodeDefaults = false`, sehingga bawaan tidak akan ikut terkirim.
 */
@Serializable data class SplitEqualRequestDto(
    @SerialName("client_ref") val clientRef: String,
    val mode: String,
    val parts: Int,
)

/** Satu bagian bagi rata dari server: nomor urut dan jumlah ("123.00"). Bagian terakhir membawa sisa pembulatan. */
@Serializable data class SplitPartDto(
    val index: Int,
    val amount: String,
)

/** Respons bagi rata (S4): `parts` = objek {index, amount} berurutan, bukan string biasa. */
@Serializable data class SplitEqualResponseDto(val parts: List<SplitPartDto> = emptyList())

/** Satu baris yang dipindah ke bagian yang dibayar. `item_id` = id baris order di server. */
@Serializable data class SplitItemDto(
    @SerialName("item_id") val itemId: Int,
    val qty: Int,
)

/**
 * 048 — bagi per item (kontrak yang sudah ada, `POST /tabs/{id}/split`). Server mewajibkan minimal satu pembayaran
 * untuk bagian yang dipindah; klien mengirim tunai sebesar bagian itu.
 */
@Serializable data class SplitItemsRequestDto(
    @SerialName("client_ref") val clientRef: String,
    val items: List<SplitItemDto>,
    val payments: List<PaymentInputDto>,
)

/** Respons bagi per item: bagian yang dibayar (`paid_order`) dan sisa bill (`tab`), keduanya TabResource dari server. */
@Serializable data class SplitItemsResponseDto(
    @SerialName("paid_order") val paidOrder: TabDto,
    val tab: TabDto,
)

/** 048 / S4 — cetak ulang; salinan pertama gratis, berikutnya wajib [overrideToken] (persetujuan `reprint`). */
@Serializable data class ReprintRequestDto(
    // 057 / US3 — nomor salinan dari server (receipt_copies + 2); wajib, sama dengan aturan ReprintRequest di server.
    @SerialName("copy_no") val copyNo: Int,
    @SerialName("override_token") val overrideToken: String? = null,
)

/** Respons cetak ulang: `copy_no` (int) dari server. */
@Serializable data class ReprintResponseDto(
    @SerialName("copy_no") val copyNo: Int = 1,
)

/** 048 / S4 — refund penuh order lunas; online saja; wajib [overrideToken] (persetujuan `refund`). */
@Serializable data class RefundRequestDto(
    @SerialName("reason_code") val reasonCode: String,
    val reason: String? = null,
    @SerialName("override_token") val overrideToken: String? = null,
)
