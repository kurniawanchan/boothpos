package com.warkop.core.network.dto

import kotlinx.serialization.SerialName
import kotlinx.serialization.Serializable

/** 055 / T008 — `GET /pos/categories`: kategori aktif menurut mode sistem; `product_count` 0 valid. */
@Serializable data class PosCategoryDto(
    val id: Long,
    val code: String = "",
    val name: String,
    @SerialName("display_order") val displayOrder: Int = 0,
    @SerialName("product_count") val productCount: Int = 0,
)

@Serializable data class PosCategoriesPage(val data: List<PosCategoryDto> = emptyList())
