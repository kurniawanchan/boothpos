package com.warkop.manager.data.dto

import kotlinx.serialization.EncodeDefault
import kotlinx.serialization.ExperimentalSerializationApi
import kotlinx.serialization.SerialName
import kotlinx.serialization.Serializable

/** 094 / T077 — DTO Vendor & Bahan (`VendorResource`, `MaterialResource`, `VendorMaterialPriceResource`). */

@Serializable data class VendorDto(
    val id: Long,
    val code: String = "",
    val name: String,
    @SerialName("contact_phone") val contactPhone: String? = null,
    @SerialName("contact_email") val contactEmail: String? = null,
    val notes: String? = null,
    @SerialName("is_active") val isActive: Boolean = true,
    @SerialName("material_price_count") val materialPriceCount: Int? = null,
)

@OptIn(ExperimentalSerializationApi::class)
@Serializable data class VendorBody(
    /** Hanya saat buat; server mengabaikan `code` saat ubah. */
    val code: String? = null,
    val name: String,
    @EncodeDefault @SerialName("contact_phone") val contactPhone: String? = null,
    @EncodeDefault @SerialName("contact_email") val contactEmail: String? = null,
    @EncodeDefault val notes: String? = null,
    @EncodeDefault @SerialName("is_active") val isActive: Boolean = true,
)

@Serializable data class MaterialOutletBalance(
    @SerialName("outlet_id") val outletId: Long,
    @SerialName("outlet_code") val outletCode: String? = null,
    @SerialName("outlet_name") val outletName: String? = null,
    @SerialName("on_hand") val onHand: String = "0",
)

@Serializable data class VendorPriceDto(
    val id: Long,
    @SerialName("vendor_id") val vendorId: Long,
    @SerialName("vendor_name") val vendorName: String? = null,
    val price: String = "0.00",
    @SerialName("is_preferred") val isPreferred: Boolean = false,
    val notes: String? = null,
)

@Serializable data class ModifierUsageDto(val group: String? = null, val option: String? = null, val quantity: String? = null, @SerialName("option_active") val optionActive: Boolean = true)

@Serializable data class MaterialDto(
    val id: Long,
    val code: String = "",
    val name: String,
    val unit: String = "",
    val kind: String = "ingredient",
    @SerialName("standard_cost") val standardCost: String? = null,
    val notes: String? = null,
    @SerialName("is_active") val isActive: Boolean = true,
    /** Total semua outlet — JANGAN dipakai sebagai saldo outlet terpilih (bug web 2026-10-31). */
    @SerialName("current_stock") val currentStock: String = "0",
    /** Hanya di daftar (index); `show` tidak memuatnya. */
    @SerialName("outlet_stock") val outletStock: List<MaterialOutletBalance>? = null,
    @SerialName("vendor_price_count") val vendorPriceCount: Int? = null,
    @SerialName("vendor_prices") val vendorPrices: List<VendorPriceDto>? = null,
    @SerialName("used_by_modifier_options") val usedByModifierOptions: List<ModifierUsageDto>? = null,
)

@OptIn(ExperimentalSerializationApi::class)
@Serializable data class MaterialBody(
    val code: String? = null,
    val name: String,
    val unit: String,
    val kind: String,
    /** Hanya dikirim untuk `semi_finished` (web: kunci tidak ada untuk jenis lain). */
    @SerialName("standard_cost") val standardCost: Double? = null,
    @EncodeDefault val notes: String? = null,
    @EncodeDefault @SerialName("is_active") val isActive: Boolean = true,
)

@Serializable data class MaterialAdjustBody(@SerialName("qty_change") val qtyChange: Double, val reason: String, @SerialName("outlet_id") val outletId: Long)

@Serializable data class MaterialAdjustResult(@SerialName("stock_before") val stockBefore: String? = null, @SerialName("stock_after") val stockAfter: String? = null)

@Serializable data class UserRef(val id: Long? = null, val name: String? = null)
@Serializable data class MaterialMovementRef(val type: String? = null, @SerialName("order_number") val orderNumber: String? = null)

@Serializable data class MaterialMovementDto(
    val id: Long,
    @SerialName("material_name") val materialName: String? = null,
    val unit: String? = null,
    val type: String,
    @SerialName("outlet_code") val outletCode: String? = null,
    @SerialName("qty_change") val qtyChange: String = "0",
    @SerialName("stock_before") val stockBefore: String? = null,
    @SerialName("stock_after") val stockAfter: String? = null,
    val reference: MaterialMovementRef? = null,
    val reason: String? = null,
    val user: UserRef? = null,
    @SerialName("created_at") val createdAt: String? = null,
)

@OptIn(ExperimentalSerializationApi::class)
@Serializable data class VendorPriceBody(
    @SerialName("vendor_id") val vendorId: Long? = null,
    val price: String,
    @SerialName("is_preferred") val isPreferred: Boolean = false,
    @EncodeDefault val notes: String? = null,
)
