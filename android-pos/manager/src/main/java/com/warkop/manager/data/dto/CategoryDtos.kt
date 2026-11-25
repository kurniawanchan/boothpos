package com.warkop.manager.data.dto

import kotlinx.serialization.EncodeDefault
import kotlinx.serialization.ExperimentalSerializationApi
import kotlinx.serialization.SerialName
import kotlinx.serialization.Serializable

/** 094 / T056 — `CategoryResource`. `product_count` hanya ada di daftar (withCount). */
@Serializable data class CategoryDto(
    val id: Long,
    val code: String,
    val name: String,
    @SerialName("image_url") val imageUrl: String? = null,
    @SerialName("parent_id") val parentId: Long? = null,
    @SerialName("display_order") val displayOrder: Int = 0,
    @SerialName("is_active") val isActive: Boolean = true,
    @SerialName("station_id") val stationId: Long? = null,
    @SerialName("product_count") val productCount: Int? = null,
)

/**
 * Badan simpan kategori, cermin payload `CategoriesView.vue::saveCategory`. `code` hanya saat BUAT (server: `prohibited`
 * saat ubah). `parent_id`/`station_id` null DIKIRIM eksplisit (kosongkan induk/stasiun), jadi `EncodeDefault`.
 */
@OptIn(ExperimentalSerializationApi::class)
@Serializable data class CategoryBody(
    val code: String? = null,
    val name: String,
    @EncodeDefault @SerialName("parent_id") val parentId: Long? = null,
    @SerialName("display_order") val displayOrder: Int = 0,
    @SerialName("is_active") val isActive: Boolean = true,
    @EncodeDefault @SerialName("station_id") val stationId: Long? = null,
)

/** `StationResource` — dipakai pemilih stasiun kategori/produk. */
@Serializable data class StationDto(
    val id: Long,
    val name: String,
    @SerialName("outlet_id") val outletId: Long? = null,
    @SerialName("display_order") val displayOrder: Int = 0,
    @SerialName("is_active") val isActive: Boolean = true,
)

@Serializable data class DataList<T>(val data: List<T> = emptyList())
