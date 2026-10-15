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

/** POST /sessions/{id}/close — hitungan buta (server menjumlahkan) + total batch EDC bila ada. */
@Serializable data class CloseSessionRequest(
    @SerialName("counted_by_denomination") val countedByDenomination: List<DenominationQtyDto>,
    @SerialName("edc_batch_total") val edcBatchTotal: Long? = null,
)

/** Keadaan shift setelah tutup: CashierSession dengan `z_report` tersimpan. */
@Serializable data class ClosedSessionDto(
    val id: Long,
    val status: String = "closed",
    @SerialName("expected_cash") val expectedCash: String? = null,
    @SerialName("counted_total") val countedTotal: String? = null,
    val variance: String? = null,
    @SerialName("edc_batch_total") val edcBatchTotal: String? = null,
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
    // 048 — TIDAK ada `expected_cash` di x-report/summary (hitungan buta). Kas seharusnya hanya di jawaban tutup shift.
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
)

/**
 * GET /settings/features — hanya dua angka yang dipakai layar kas. Nilainya ANGKA di JSON; disimpan sebagai
 * JsonElement agar tidak gagal dekode bila server mengirim angka atau string.
 */
@Serializable data class FeaturesDto(
    @SerialName("variance_tolerance") val varianceTolerance: JsonElement? = null,
    @SerialName("cash_out_approval_threshold") val cashOutApprovalThreshold: JsonElement? = null,
)
