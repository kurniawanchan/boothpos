package com.warkop.manager.data.dto

import kotlinx.serialization.SerialName
import kotlinx.serialization.Serializable
import kotlinx.serialization.json.JsonElement
import kotlinx.serialization.json.JsonObject
import kotlinx.serialization.json.JsonPrimitive

/**
 * 094 / T051 — DTO Dashboard (kontrak B1; skema `DashboardSummary`, `DashboardAttention`, `ShiftRow`, `DashboardOrders`
 * di `docs/openapi-pos-mvp.yaml`). Uang SELALU string dari server; klien tidak menghitung ulang angka apa pun.
 */

/** `value`/`comparison`/`v` bertipe campuran: string 2 desimal untuk uang, integer untuk hitungan. */
@Serializable data class KpiDto(
    val value: JsonPrimitive? = null,
    val comparison: JsonPrimitive? = null,
    @SerialName("change_pct") val changePct: Double? = null,
    /** Hanya `low_stock`: selisih jumlah item; naik itu buruk. */
    @SerialName("change_abs") val changeAbs: Int? = null,
    val scope: String? = null,
    val series: List<PointDto> = emptyList(),
)

@Serializable data class PointDto(val t: String, val v: JsonPrimitive? = null)

@Serializable data class ComparisonDto(val basis: String? = null, val from: String? = null, val to: String? = null, @SerialName("up_to") val upTo: String? = null)

@Serializable data class RevenueDto(
    @SerialName("net_sales") val netSales: String? = null,
    @SerialName("order_discount_total") val orderDiscountTotal: String? = null,
    @SerialName("tax_total") val taxTotal: String? = null,
    @SerialName("grand_total") val grandTotal: String? = null,
)

@Serializable data class DashboardSummaryDto(
    @SerialName("computed_at") val computedAt: String? = null,
    @SerialName("business_date") val businessDate: String? = null,
    val period: String = "today",
    val comparison: ComparisonDto? = null,
    val revenue: RevenueDto? = null,
    val kpis: Map<String, KpiDto> = emptyMap(),
)

/**
 * `query` SENGAJA [JsonElement]: larik PHP kosong dikodekan `[]` (array), bukan `{}` — mis. `cash_difference` mengirim
 * `"query":[]`. Sebagai JsonObject seluruh respons gagal diurai (BUG YANG DITEMUKAN & DIPERBAIKI saat uji di perangkat,
 * 2026-11-25: panel "Perlu tindakan" selalu "gangguan di server" walau respons 200).
 */
@Serializable data class AttentionLinkDto(val route: String, val query: JsonElement? = null)

@Serializable data class AttentionItemDto(
    val kind: String,
    val severity: String = "ok",
    val count: Int = 0,
    val amount: String? = null,
    @SerialName("amount_unknown_count") val amountUnknownCount: Int? = null,
    val link: AttentionLinkDto? = null,
)

@Serializable data class AttentionDto(
    @SerialName("computed_at") val computedAt: String? = null,
    val count: Int = 0,
    val items: List<AttentionItemDto> = emptyList(),
)

@Serializable data class IdName(val id: Long, val name: String)

@Serializable data class ShiftRowDto(
    val id: Long,
    val source: String = "web",
    @SerialName("terminal_name") val terminalName: String? = null,
    val cashier: IdName? = null,
    val outlet: IdName? = null,
    @SerialName("opened_at") val openedAt: String? = null,
    @SerialName("order_count") val orderCount: Int = 0,
    @SerialName("sales_total") val salesTotal: String? = null,
)

@Serializable data class OpenShiftsDto(val data: List<ShiftRowDto> = emptyList(), @SerialName("total_open") val totalOpen: Int = 0)

@Serializable data class MaterialCountsDto(
    val negative: Int = 0,
    val out: Int = 0,
    val low: Int = 0,
    @SerialName("at_risk_products") val atRiskProducts: Int = 0,
)

@Serializable data class MaterialParamsDto(@SerialName("window_days") val windowDays: Int = 7, @SerialName("risk_days") val riskDays: Int = 3)

/**
 * `stock_value`/`wasted_value`: kunci TIDAK ADA bagi peran tanpa menu `reports` (server menghapusnya), dan bisa null bila
 * ada tetapi tak terhitung. Dua keadaan itu dibedakan lewat [JsonObject] mentah di [DashboardRepository].
 */
@Serializable data class MaterialStatsDto(
    val counts: MaterialCountsDto = MaterialCountsDto(),
    @SerialName("low_stock_products") val lowStockProducts: Int = 0,
    @SerialName("stock_value") val stockValue: String? = null,
    @SerialName("wasted_value") val wastedValue: String? = null,
    val params: MaterialParamsDto = MaterialParamsDto(),
)

@Serializable data class DashboardOrderRowDto(
    val id: Long,
    @SerialName("order_number") val orderNumber: String,
    val type: String? = null,
    val table: String? = null,
    @SerialName("items_summary") val itemsSummary: String = "",
    val tab: String = "open",
    @SerialName("payment_state") val paymentState: String? = null,
    @SerialName("total_amount") val totalAmount: String? = null,
    val at: String? = null,
)

@Serializable data class OrderCountsDto(val all: Int = 0, val open: Int = 0, val partial: Int = 0, val paid: Int = 0, val cancelled: Int = 0)

@Serializable data class DashboardOrdersDto(
    val data: List<DashboardOrderRowDto> = emptyList(),
    val meta: PageMeta = PageMeta(),
    val counts: OrderCountsDto = OrderCountsDto(),
)
