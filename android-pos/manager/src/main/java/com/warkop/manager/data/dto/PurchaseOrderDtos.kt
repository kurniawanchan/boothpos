package com.warkop.manager.data.dto

import kotlinx.serialization.EncodeDefault
import kotlinx.serialization.ExperimentalSerializationApi
import kotlinx.serialization.SerialName
import kotlinx.serialization.Serializable

/** 094 / T077 — DTO PO (`PurchaseOrderController::present`). Uang = string 2 desimal; `qty` string mentah. */

@Serializable data class PoItemDto(
    val id: Long? = null,
    @SerialName("line_type") val lineType: String = "material",
    @SerialName("material_id") val materialId: Long? = null,
    @SerialName("material_name") val materialName: String? = null,
    @SerialName("product_id") val productId: Long? = null,
    @SerialName("product_name") val productName: String? = null,
    @SerialName("used_in_bom_count") val usedInBomCount: Int = 0,
    val description: String? = null,
    val qty: String = "0",
    @SerialName("unit_price") val unitPrice: String = "0.00",
    @SerialName("line_total") val lineTotal: String? = null,
)

@Serializable data class PoPaymentDto(val id: Long, val method: String = "cash", val amount: String = "0.00", val notes: String? = null, @SerialName("paid_at") val paidAt: String? = null)

@Serializable data class PurchaseOrderDto(
    val id: Long,
    @SerialName("po_number") val poNumber: String = "",
    @SerialName("vendor_id") val vendorId: Long? = null,
    @SerialName("vendor_name") val vendorName: String? = null,
    @SerialName("outlet_id") val outletId: Long? = null,
    @SerialName("outlet_name") val outletName: String? = null,
    val status: String = "draft",
    @SerialName("total_amount") val totalAmount: String = "0.00",
    @SerialName("paid_amount") val paidAmount: String = "0.00",
    val notes: String? = null,
    @SerialName("cancel_reason") val cancelReason: String? = null,
    @SerialName("created_at") val createdAt: String? = null,
    val items: List<PoItemDto> = emptyList(),
    val payments: List<PoPaymentDto> = emptyList(),
)

@OptIn(ExperimentalSerializationApi::class)
@Serializable data class PoItemBody(
    @SerialName("line_type") val lineType: String,
    @EncodeDefault @SerialName("material_id") val materialId: Long? = null,
    @EncodeDefault @SerialName("product_id") val productId: Long? = null,
    @EncodeDefault val description: String? = null,
    val qty: Double,
    @SerialName("unit_price") val unitPrice: Double,
)

/** `items` hanya dikirim selama draft (server 409 bila dikirim di status lain). */
@OptIn(ExperimentalSerializationApi::class)
@Serializable data class PoBody(
    @SerialName("vendor_id") val vendorId: Long,
    @SerialName("outlet_id") val outletId: Long,
    @EncodeDefault val notes: String? = null,
    val items: List<PoItemBody>? = null,
)

@OptIn(ExperimentalSerializationApi::class)
@Serializable data class PoStatusBody(val status: String, @EncodeDefault @SerialName("cancel_reason") val cancelReason: String? = null)

/** Hanya tunai: metode non-tunai butuh `channel_id` + `proof_token` (PaymentRecorder) yang tidak disediakan layar ini. */
@OptIn(ExperimentalSerializationApi::class)
@Serializable data class PoPaymentBody(val method: String = "cash", val amount: Double, @EncodeDefault val notes: String? = null)
