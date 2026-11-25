package com.warkop.manager.data.dto

import kotlinx.serialization.EncodeDefault
import kotlinx.serialization.ExperimentalSerializationApi
import kotlinx.serialization.SerialName
import kotlinx.serialization.Serializable

/** 094 / T087 — DTO Pengaturan Restoran (`DiningTableResource`, `StationResource`, `ModifierGroupResource`). */

@Serializable data class DiningTableDto(
    val id: Long,
    val name: String,
    @SerialName("outlet_id") val outletId: Long? = null,
    val area: String = "",
    @Serializable(with = FlexIntSerializer::class) val seats: Int? = 4,
    @SerialName("display_order") val displayOrder: Int = 0,
    @SerialName("is_active") val isActive: Boolean = true,
    @SerialName("has_open_tab") val hasOpenTab: Boolean = false,
    @SerialName("qr_ready") val qrReady: Boolean = false,
)

@Serializable data class TableMeta(
    @SerialName("current_page") val currentPage: Int = 1,
    @SerialName("per_page") val perPage: Int = 25,
    val total: Int = 0,
    @SerialName("last_page") val lastPage: Int = 1,
    val areas: List<String> = emptyList(),
)

@Serializable data class TablePage(val data: List<DiningTableDto> = emptyList(), val meta: TableMeta = TableMeta())

/** `outlet_id` hanya bila outlet BERUBAH (pindah outlet; `OutletMoveGuard` bisa menolak 409). */
@OptIn(ExperimentalSerializationApi::class)
@Serializable data class TableBody(
    val name: String,
    @EncodeDefault val area: String? = null,
    val seats: Int,
    @SerialName("display_order") val displayOrder: Int,
    @SerialName("is_active") val isActive: Boolean,
    @SerialName("outlet_id") val outletId: Long? = null,
)

@Serializable data class StationBody(
    val name: String,
    @SerialName("display_order") val displayOrder: Int,
    @SerialName("is_active") val isActive: Boolean,
    @SerialName("outlet_id") val outletId: Long? = null,
)

@Serializable data class QrUrlDto(@SerialName("table_id") val tableId: Long? = null, val url: String? = null, @SerialName("table_name") val tableName: String? = null)
@Serializable data class QrBatchDto(val items: List<QrUrlDto> = emptyList())
@Serializable data class QrBatchBody(val ids: List<Long>)

@Serializable data class OptionMaterialDto(@SerialName("material_id") val materialId: Long, val name: String? = null, val unit: String? = null, val quantity: String = "1")

@Serializable data class ModifierOptionDto(
    val id: Long,
    val name: String,
    @SerialName("price_delta") val priceDelta: String = "0.00",
    @SerialName("min_qty") val minQty: Int = 1,
    @SerialName("max_qty") val maxQty: Int? = 1,
    @SerialName("display_order") val displayOrder: Int = 0,
    @SerialName("is_active") val isActive: Boolean = true,
    val materials: List<OptionMaterialDto> = emptyList(),
)

@Serializable data class ModifierGroupDto(
    val id: Long,
    val name: String,
    @SerialName("selection_type") val selectionType: String = "single",
    @SerialName("is_required") val isRequired: Boolean = false,
    @SerialName("min_select") val minSelect: Int = 0,
    @SerialName("max_select") val maxSelect: Int? = 1,
    @SerialName("portion_per_option") val portionPerOption: Boolean = false,
    @SerialName("is_active") val isActive: Boolean = true,
    val options: List<ModifierOptionDto> = emptyList(),
    @SerialName("product_ids") val productIds: List<Long> = emptyList(),
    @SerialName("category_ids") val categoryIds: List<Long> = emptyList(),
)

/** Badan grup modifier dibangun sebagai JsonObject di `ModifierGroupForm.toJson()` — ada/tidaknya kunci bermakna. */

/** `PUT /modifier-groups/{id}/attachments` — PENGGANTIAN PENUH; kedua daftar selalu dikirim (boleh `[]`). */
@OptIn(ExperimentalSerializationApi::class)
@Serializable data class AttachBody(@EncodeDefault @SerialName("product_ids") val productIds: List<Long> = emptyList(), @EncodeDefault @SerialName("category_ids") val categoryIds: List<Long> = emptyList())
