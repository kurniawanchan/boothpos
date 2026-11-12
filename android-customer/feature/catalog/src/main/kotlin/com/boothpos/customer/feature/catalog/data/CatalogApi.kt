package com.boothpos.customer.feature.catalog.data

import com.boothpos.customer.core.network.dto.DataEnvelope
import com.boothpos.customer.core.network.dto.PagedEnvelope
import kotlinx.serialization.SerialName
import kotlinx.serialization.Serializable
import retrofit2.http.GET
import retrofit2.http.Path
import retrofit2.http.Query

// Bentuk kawat kontrak §6. Field yang tidak dikenal diabaikan (Json proyek: ignoreUnknownKeys),
// jadi server boleh menambah field tanpa merusak aplikasi lama.

@Serializable
data class CategoryDto(
    val id: Long,
    val name: String,
    @SerialName("image_url") val imageUrl: String? = null,
)

@Serializable
data class CategoryRefDto(val id: Long, val name: String)

@Serializable
data class ProductCardDto(
    val slug: String,
    val name: String,
    @SerialName("image_url") val imageUrl: String? = null,
    val category: CategoryRefDto,
    @SerialName("seller_name") val sellerName: String,
    @SerialName("price_min") val priceMin: String,
    @SerialName("price_max") val priceMax: String,
    @SerialName("in_stock") val inStock: Boolean,
)

@Serializable
data class VariantDto(
    val id: Long,
    val name: String,
    @SerialName("image_url") val imageUrl: String? = null,
    val price: String,
    @SerialName("in_stock") val inStock: Boolean,
    @SerialName("max_qty") val maxQty: Int,
)

/** `ProductDetail` = `ProductCard` + deskripsi + varian. */
@Serializable
data class ProductDetailDto(
    val slug: String,
    val name: String,
    @SerialName("image_url") val imageUrl: String? = null,
    val category: CategoryRefDto,
    @SerialName("seller_name") val sellerName: String,
    @SerialName("price_min") val priceMin: String,
    @SerialName("price_max") val priceMax: String,
    @SerialName("in_stock") val inStock: Boolean,
    val description: String? = null,
    val variants: List<VariantDto> = emptyList(),
)

/** Endpoint publik: tanpa token (AuthInterceptor hanya menempelkan Bearer ke `…/customer/…`). */
interface CatalogApi {
    @GET("storefront/categories")
    suspend fun categories(): DataEnvelope<List<CategoryDto>>

    /** Parameter bernilai null tidak dikirim sama sekali. */
    @GET("storefront/products")
    suspend fun products(
        @Query("q") search: String?,
        @Query("category_id") categoryId: Long?,
        @Query("sort") sort: String,
        @Query("page") page: Int,
        @Query("per_page") perPage: Int,
    ): PagedEnvelope<ProductCardDto>

    @GET("storefront/products/{slug}")
    suspend fun product(@Path("slug") slug: String): DataEnvelope<ProductDetailDto>
}
