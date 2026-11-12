package com.boothpos.customer.core.network.dto

import kotlinx.serialization.SerialName
import kotlinx.serialization.Serializable

/** `{ "data": … }` — bentuk jawaban tunggal di seluruh API pelanggan. */
@Serializable
data class DataEnvelope<T>(val data: T)

/** `{ "data": [...], "meta": {...} }` — daftar berhalaman (produk, pesanan). */
@Serializable
data class PagedEnvelope<T>(val data: List<T>, val meta: PageMetaDto)

@Serializable
data class PageMetaDto(
    @SerialName("current_page") val currentPage: Int,
    @SerialName("per_page") val perPage: Int,
    val total: Int,
    @SerialName("last_page") val lastPage: Int,
)
