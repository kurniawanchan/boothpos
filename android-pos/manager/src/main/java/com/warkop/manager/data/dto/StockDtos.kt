package com.warkop.manager.data.dto

import kotlinx.serialization.SerialName
import kotlinx.serialization.Serializable

/** 094 / T058 — DTO Stok (kontrak B6; skema `StockMovement`, `VariantLookup`, `StockTransferController`). */

@Serializable data class OutletRef(val id: Long, val code: String? = null, val name: String)

/** `GET /me/scope` — outlet yang boleh dilihat; `multi` = lebih dari satu (fitur transfer hanya bila true). */
@Serializable data class ScopeDto(val outlets: List<OutletRef> = emptyList(), @SerialName("can_see_all") val canSeeAll: Boolean = false, val multi: Boolean = false)

@Serializable data class MovementRefDto(val type: String, val id: Long? = null, val number: String? = null)

@Serializable data class StockMovementDto(
    val id: Long,
    @SerialName("variant_id") val variantId: Long? = null,
    val sku: String? = null,
    val type: String,
    @SerialName("qty_change") val qtyChange: Int = 0,
    @SerialName("stock_before") val stockBefore: Int? = null,
    @SerialName("stock_after") val stockAfter: Int? = null,
    val reason: String? = null,
    @SerialName("user_name") val userName: String? = null,
    @SerialName("variant_name") val variantName: String? = null,
    @SerialName("product_name") val productName: String? = null,
    @SerialName("outlet_name") val outletName: String? = null,
    val reference: MovementRefDto? = null,
    @SerialName("created_at") val createdAt: String? = null,
)

@Serializable data class LowStockDto(
    @SerialName("variant_id") val variantId: Long,
    val sku: String? = null,
    @SerialName("product_name") val productName: String? = null,
    @SerialName("outlet_name") val outletName: String? = null,
    @SerialName("current_stock") val currentStock: Int = 0,
    @SerialName("low_stock_alert") val lowStockAlert: Int? = null,
)

/** `GET /variants/lookup?stock_outlet=` — `outlet_stock` = saldo di outlet itu. */
@Serializable data class VariantLookupDto(
    @SerialName("variant_id") val variantId: Long,
    val sku: String = "",
    val label: String = "",
    @SerialName("current_stock") val currentStock: Int? = null,
    @SerialName("outlet_stock") val outletStock: Int? = null,
)

@Serializable data class AdjustItem(@SerialName("variant_id") val variantId: Long, @SerialName("qty_change") val qtyChange: Int)

@Serializable data class AdjustBody(val reason: String, @SerialName("outlet_id") val outletId: Long, val items: List<AdjustItem>)

/** Saldo bahan per outlet (`MaterialResource.outlet_stock`); jumlah desimal sebagai string 3 angka. */
@Serializable data class MaterialOutletStockDto(@SerialName("outlet_id") val outletId: Long, @SerialName("on_hand") val onHand: String = "0")

@Serializable data class MaterialBriefDto(
    val id: Long,
    val code: String = "",
    val name: String,
    val unit: String? = null,
    @SerialName("outlet_stock") val outletStock: List<MaterialOutletStockDto> = emptyList(),
)

@Serializable data class TransferCanDto(val receive: Boolean = false, val cancel: Boolean = false)

@Serializable data class TransferSummaryDto(
    val id: Long,
    val number: String,
    val status: String,
    @SerialName("has_discrepancy") val hasDiscrepancy: Boolean = false,
    val note: String? = null,
    @SerialName("from_outlet") val fromOutlet: OutletRef? = null,
    @SerialName("to_outlet") val toOutlet: OutletRef? = null,
    @SerialName("line_count") val lineCount: Int = 0,
    @SerialName("dispatched_at") val dispatchedAt: String? = null,
    val can: TransferCanDto = TransferCanDto(),
)

@Serializable data class TransferItemRef(val id: Long, val sku: String? = null, val name: String)

@Serializable data class TransferLineDto(
    val id: Long,
    val kind: String,
    val item: TransferItemRef? = null,
    @SerialName("qty_sent") val qtySent: String? = null,
    @SerialName("qty_received") val qtyReceived: String? = null,
    val shortfall: String? = null,
    @SerialName("discrepancy_reason") val discrepancyReason: String? = null,
    val warnings: List<String> = emptyList(),
)

@Serializable data class TransferStepDto(val step: String, val by: String? = null, val at: String? = null)

@Serializable data class TransferDetailDto(
    val id: Long,
    val number: String,
    val status: String,
    @SerialName("has_discrepancy") val hasDiscrepancy: Boolean = false,
    val note: String? = null,
    @SerialName("from_outlet") val fromOutlet: OutletRef? = null,
    @SerialName("to_outlet") val toOutlet: OutletRef? = null,
    val can: TransferCanDto = TransferCanDto(),
    val lines: List<TransferLineDto> = emptyList(),
    val steps: List<TransferStepDto> = emptyList(),
)

@Serializable data class TransferLineBody(val kind: String, @SerialName("item_id") val itemId: Long, val qty: Double)

@Serializable data class NewTransferBody(
    @SerialName("from_outlet_id") val fromOutletId: Long,
    @SerialName("to_outlet_id") val toOutletId: Long,
    val note: String? = null,
    @SerialName("client_ref") val clientRef: String,
    val lines: List<TransferLineBody>,
)

@Serializable data class ReceiveLineBody(@SerialName("line_id") val lineId: Long, @SerialName("qty_received") val qtyReceived: Double, val reason: String? = null)

@Serializable data class ReceiveBody(val lines: List<ReceiveLineBody>)
