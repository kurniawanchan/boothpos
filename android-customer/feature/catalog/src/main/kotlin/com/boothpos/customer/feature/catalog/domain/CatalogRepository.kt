package com.boothpos.customer.feature.catalog.domain

import com.boothpos.customer.core.data.domain.model.Paged
import com.boothpos.customer.core.network.error.ApiResult

/**
 * Katalog publik toko. Tidak memerlukan sesi. Pemanggilan berhasil sekaligus memperbarui cache
 * lokal berbatas; fungsi `cached…` membaca cache itu untuk keadaan offline.
 */
interface CatalogRepository {
    /** Satu halaman daftar produk (`page` mulai dari 1). Katalog tidak pernah diambil seluruhnya. */
    suspend fun products(query: ProductQuery, page: Int): ApiResult<Paged<ProductSummary>>

    suspend fun categories(): ApiResult<List<Category>>

    /** 404 `not_found` berarti produk tidak lagi dijual; cache produk itu ikut dibuang. */
    suspend fun product(slug: String): ApiResult<ProductDetail>

    /** Halaman-halaman pertama daftar bawaan yang terakhir berhasil dimuat, dalam urutan server. */
    suspend fun cachedProducts(): List<ProductSummary>

    suspend fun cachedCategories(): List<Category>

    /** Null bila detail produk itu belum pernah dibuka (kartu saja tidak cukup: tanpa varian). */
    suspend fun cachedProduct(slug: String): ProductDetail?
}
