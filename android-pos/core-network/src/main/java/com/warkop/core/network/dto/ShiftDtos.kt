package com.warkop.core.network.dto

import kotlinx.serialization.SerialName
import kotlinx.serialization.Serializable
import kotlinx.serialization.json.JsonElement

// 048 / S2 — bentuk respons shift dan kas (SessionController, ShiftReportService, CashMovementController).
// Uang berupa string desimal ("150000.00"), diubah di ShiftMapper. Pengaturan (`/settings/features`) berupa ANGKA,
// karena itu disimpan sebagai JsonElement dan dibaca lewat ShiftMapper.

/** Pecahan uang dengan jumlah lembar/keping. Dipakai untuk kas awal dan hitungan buta saat tutup. */
@Serializable data class DenominationQtyDto(val denomination: Int, val qty: Int)

/**
 * POST /sessions. Isi salah satu: pecahan (server menurunkan `opening_cash`) atau `opening_cash` saja (klien lama).
 * `encodeDefaults = false` + `explicitNulls = false` di ApiClient: kolom yang null tidak ikut dikirim.
 */
@Serializable data class OpenSessionRequest(
    @SerialName("opening_cash") val openingCash: Long? = null,
    @SerialName("opening_denominations") val openingDenominations: List<DenominationQtyDto>? = null,
)

/**
 * POST /sessions/{id}/close — hitungan per pecahan (server menjumlahkan) ATAU total ketikan `counted_total` (065);
 * isi salah satu, tidak keduanya. Total batch EDC tidak ada lagi (065): server mengabaikannya.
 */
@Serializable data class CloseSessionRequest(
    @SerialName("counted_by_denomination") val countedByDenomination: List<DenominationQtyDto>? = null,
    @SerialName("counted_total") val countedTotal: Long? = null,
    /** 048 / S3 — token persetujuan selisih di atas toleransi; null bila selisih tidak melampaui batas. */
    @SerialName("variance_override_token") val varianceOverrideToken: String? = null,
)

/** Keadaan shift setelah tutup: CashierSession dengan `z_report` tersimpan. */
@Serializable data class ClosedSessionDto(
    val id: Long,
    val status: String = "closed",
    @SerialName("expected_cash") val expectedCash: String? = null,
    @SerialName("counted_total") val countedTotal: String? = null,
    val variance: String? = null,
    @SerialName("z_report") val zReport: ZReportDto? = null,
)

@Serializable data class ZReportDto(
    @SerialName("variance_tolerance") val varianceTolerance: String? = null,
    @SerialName("closed_at") val closedAt: String? = null,
)

/** GET /sessions/{id}/x-report — angka dihitung server, tidak disimpan. */
@Serializable data class XReportDto(
    @SerialName("generated_at") val generatedAt: String? = null,
    @SerialName("session_id") val sessionId: Long = 0,
    val status: String = "open",
    @SerialName("opening_cash") val openingCash: String = "0.00",
    @SerialName("order_count") val orderCount: Int = 0,
    @SerialName("total_sales") val totalSales: String = "0.00",
    @SerialName("void_count") val voidCount: Int = 0,
    @SerialName("void_total") val voidTotal: String = "0.00",
    @SerialName("change_given") val changeGiven: String = "0.00",
    @SerialName("cash_received") val cashReceived: String = "0.00",
    @SerialName("cash_in") val cashIn: String = "0.00",
    @SerialName("cash_out") val cashOut: String = "0.00",
    // 065 — `expected_cash` kini ada di x-report/summary (keputusan pemilik: hitungan buta dihentikan).
    @SerialName("expected_cash") val expectedCash: String? = null,
    @SerialName("by_method") val byMethod: List<MethodTotalDto> = emptyList(),
    val hourly: List<HourlyTotalDto> = emptyList(),
)

@Serializable data class MethodTotalDto(val method: String = "", val count: Int = 0, val amount: String = "0.00")

/** `hour` = "09" menurut kontrak, tetapi server kadang mengirim angka (23): diterima keduanya lewat JsonElement. */
@Serializable data class HourlyTotalDto(
    val hour: JsonElement? = null,
    @SerialName("order_count") val orderCount: Int = 0,
    val total: String = "0.00",
)

@Serializable data class CashMovementDto(
    val id: Long,
    val direction: String = "in",
    @SerialName("reason_code") val reasonCode: String = "",
    @SerialName("reason_note") val reasonNote: String? = null,
    val amount: String = "0.00",
    @SerialName("created_by_name") val createdByName: String? = null,
    @SerialName("created_at") val createdAt: String? = null,
)

@Serializable data class CashMovementPage(val data: List<CashMovementDto> = emptyList())

/** POST /sessions/{id}/cash-movements. Jumlah bilangan bulat rupiah (server menerima angka). */
@Serializable data class CashMovementRequest(
    val direction: String,
    @SerialName("reason_code") val reasonCode: String,
    @SerialName("reason_note") val reasonNote: String? = null,
    val amount: Long,
    /** 048 / S3 — token persetujuan untuk kas keluar di atas ambang; null bila tidak ada. */
    @SerialName("override_token") val overrideToken: String? = null,
)

/**
 * GET /settings/features — hanya dua angka yang dipakai layar kas. Nilainya ANGKA di JSON; disimpan sebagai
 * JsonElement agar tidak gagal dekode bila server mengirim angka atau string.
 */
@Serializable data class FeaturesDto(
    @SerialName("variance_tolerance") val varianceTolerance: JsonElement? = null,
    @SerialName("cash_out_approval_threshold") val cashOutApprovalThreshold: JsonElement? = null,
    // 055 / T036 — label dan tarif pajak dari server (FR-015); tidak lagi "Pajak 10%" tertulis di layar.
    @SerialName("tax_label") val taxLabel: String? = null,
    @SerialName("tax_rate") val taxRate: JsonElement? = null,
)
