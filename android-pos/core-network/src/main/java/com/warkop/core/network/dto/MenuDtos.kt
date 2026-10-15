package com.warkop.core.network.dto

import kotlinx.serialization.SerialName
import kotlinx.serialization.Serializable

@Serializable data class ModifierOptionDto(val id: Long, val name: String, @SerialName("price_delta") val priceDelta: String = "0.00")

@Serializable data class ModifierGroupDto(
    val id: Long,
    val name: String,
    @SerialName("is_required") val isRequired: Boolean = false,
    @SerialName("min_select") val minSelect: Int = 0,
    @SerialName("max_select") val maxSelect: Int = 1,
    val options: List<ModifierOptionDto> = emptyList(),
)

@Serializable data class VariantDto(
    val id: Long,
    @SerialName("variant_name") val variantName: String = "",
    @SerialName("sell_price") val sellPrice: String = "0.00",
    @SerialName("outlet_price") val outletPrice: String? = null,
    @SerialName("image_url") val imageUrl: String? = null,
    @SerialName("is_active") val isActive: Boolean = true,
)

@Serializable data class ProductDto(
    val id: Long,
    val name: String,
    @SerialName("category_id") val categoryId: Long? = null,
    @SerialName("category_name") val categoryName: String? = null,
    @SerialName("image_url") val imageUrl: String? = null,
    @SerialName("is_active") val isActive: Boolean = true,
    val variants: List<VariantDto> = emptyList(),
    @SerialName("modifier_groups") val modifierGroups: List<ModifierGroupDto> = emptyList(),
)

@Serializable data class PageMeta(
    @SerialName("current_page") val currentPage: Int = 1,
    @SerialName("last_page") val lastPage: Int = 1,
)

@Serializable data class ProductPage(val data: List<ProductDto> = emptyList(), val meta: PageMeta = PageMeta())

@Serializable data class SoldOutDto(
    @SerialName("product_id") val productId: Long,
    @SerialName("variant_ids") val variantIds: List<Long> = emptyList(),
)

@Serializable data class AvailabilityDto(
    val etag: String = "",
    @SerialName("sold_out") val soldOut: List<SoldOutDto> = emptyList(),
    @SerialName("discontinued_ids") val discontinuedIds: List<Long> = emptyList(),
)

@Serializable data class SessionDto(
    val id: Long,
    @SerialName("opened_at") val openedAt: String? = null,
    @SerialName("opening_cash") val openingCash: String = "0.00",
    val status: String = "open",
)

@Serializable data class OpenSessionRequest(@SerialName("opening_cash") val openingCash: Long)
