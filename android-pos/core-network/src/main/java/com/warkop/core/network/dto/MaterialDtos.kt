package com.warkop.core.network.dto

import kotlinx.serialization.SerialName
import kotlinx.serialization.Serializable

/**
 * 087 lanjutan (2026-11-01) — stok bahan untuk tablet. Angka bahan tetap STRING desimal dari server (pecahan boleh,
 * minus boleh untuk penjualan); klien hanya menampilkan.
 */
@Serializable data class MaterialWarningDto(
    @SerialName("material_id") val materialId: Long,
    val material: String = "",
    val unit: String = "",
    @SerialName("outlet_id") val outletId: Long? = null,
    val outlet: String? = null,
    @SerialName("stock_before") val stockBefore: String = "0",
    val required: String = "0",
    @SerialName("stock_after") val stockAfter: String = "0",
)

@Serializable data class MaterialOutletStockDto(
    @SerialName("outlet_id") val outletId: Long,
    @SerialName("outlet_code") val outletCode: String? = null,
    @SerialName("outlet_name") val outletName: String? = null,
    @SerialName("on_hand") val onHand: String = "0",
)

@Serializable data class MaterialDto(
    val id: Long,
    val code: String = "",
    val name: String = "",
    val unit: String = "",
    val kind: String = "ingredient",
    @SerialName("is_active") val isActive: Boolean = true,
    @SerialName("current_stock") val currentStock: String = "0",
    @SerialName("outlet_stock") val outletStock: List<MaterialOutletStockDto> = emptyList(),
)

@Serializable data class MaterialPage(val data: List<MaterialDto> = emptyList(), val meta: PageMeta = PageMeta())

@Serializable data class MaterialMovementRefDto(
    val type: String? = null,
    val id: Long? = null,
    @SerialName("order_id") val orderId: Long? = null,
    @SerialName("order_number") val orderNumber: String? = null,
)

@Serializable data class MaterialMovementUserDto(val id: Long, val name: String = "")

@Serializable data class MaterialMovementDto(
    val id: Long,
    @SerialName("material_id") val materialId: Long? = null,
    @SerialName("material_code") val materialCode: String? = null,
    @SerialName("material_name") val materialName: String? = null,
    val unit: String? = null,
    val type: String = "",
    @SerialName("outlet_code") val outletCode: String? = null,
    @SerialName("qty_change") val qtyChange: String = "0",
    @SerialName("stock_before") val stockBefore: String = "0",
    @SerialName("stock_after") val stockAfter: String = "0",
    val reference: MaterialMovementRefDto? = null,
    val reason: String? = null,
    val user: MaterialMovementUserDto? = null,
    @SerialName("created_at") val createdAt: String? = null,
)

@Serializable data class MaterialMovementPage(val data: List<MaterialMovementDto> = emptyList(), val meta: PageMeta = PageMeta())
