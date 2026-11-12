package com.boothpos.customer.feature.catalog.data

import com.boothpos.customer.core.data.domain.model.Paged
import com.boothpos.customer.core.data.local.CatalogCacheDao
import com.boothpos.customer.core.data.local.CatalogCacheLimits
import com.boothpos.customer.core.data.remote.toDomain
import com.boothpos.customer.core.network.error.ApiError
import com.boothpos.customer.core.network.error.ApiErrorCodes
import com.boothpos.customer.core.network.error.ApiResult
import com.boothpos.customer.core.network.error.SafeApiCall
import com.boothpos.customer.feature.catalog.domain.CatalogRepository
import com.boothpos.customer.feature.catalog.domain.Category
import com.boothpos.customer.feature.catalog.domain.ProductDetail
import com.boothpos.customer.feature.catalog.domain.ProductQuery
import com.boothpos.customer.feature.catalog.domain.ProductSummary
import java.time.Clock
import javax.inject.Inject
import kotlin.coroutines.cancellation.CancellationException
import kotlinx.coroutines.flow.first

/**
 * Katalog dari API publik + cache Room berbatas.
 *
 * Aturan cache (bantuan offline, bukan salinan katalog):
 * - hanya DAFTAR BAWAAN (tanpa pencarian/filter, urutan bawaan) yang menjadi daftar offline, dan
 *   hanya [OFFLINE_LIST_PAGES] halaman pertamanya; halaman 1 yang baru menggantikan daftar lama;
 * - hasil pencarian/filter tidak pernah disimpan (urutannya akan mencampur daftar offline);
 * - detail produk yang dibuka disimpan untuk dibuka lagi saat offline;
 * - DAO memangkas semuanya ke [CatalogCacheLimits.MAX_PRODUCTS] pada setiap penulisan;
 * - kegagalan menulis/membaca cache tidak pernah menggagalkan layar (cache hanya upaya-terbaik).
 */
class CatalogRepositoryImpl @Inject constructor(
    private val api: CatalogApi,
    private val safeApiCall: SafeApiCall,
    private val cache: CatalogCacheDao,
    private val clock: Clock,
) : CatalogRepository {

    override suspend fun products(query: ProductQuery, page: Int): ApiResult<Paged<ProductSummary>> {
        // Satu bentuk kueri untuk kawat DAN untuk keputusan cache ("   " sama dengan tanpa pencarian).
        val normalized = query.copy(search = query.search.trim().take(ProductQuery.MAX_SEARCH_LENGTH))
        val result = safeApiCall {
            val envelope = api.products(
                search = normalized.search.takeIf { it.isNotEmpty() },
                categoryId = normalized.categoryId,
                sort = normalized.sort.wireValue,
                page = page,
                perPage = PAGE_SIZE,
            )
            // Pemetaan di dalam blok: harga yang tidak terbaca menjadi ApiError.Unknown, bukan crash.
            Paged(items = envelope.data.map { it.toDomain() }, page = envelope.meta.toDomain())
        }
        if (result is ApiResult.Success && normalized.isDefault && page in 1..OFFLINE_LIST_PAGES) {
            writeCache {
                val now = clock.millis()
                val entities = result.value.items.mapIndexed { index, product ->
                    product.toEntity(listPosition = (page - 1) * PAGE_SIZE + index, cachedAt = now)
                }
                if (page == 1) cache.replaceListedProducts(entities) else cache.cacheListedProducts(entities)
            }
        }
        return result
    }

    override suspend fun categories(): ApiResult<List<Category>> {
        val result = safeApiCall { api.categories().data.map { it.toDomain() } }
        if (result is ApiResult.Success) {
            writeCache {
                cache.replaceCategories(result.value.mapIndexed { index, category -> category.toEntity(index) })
            }
        }
        return result
    }

    override suspend fun product(slug: String): ApiResult<ProductDetail> {
        val result = safeApiCall { api.product(slug).data.toDomain() }
        when (result) {
            is ApiResult.Success -> writeCache {
                val detail = result.value
                cache.cacheProductDetail(
                    product = detail.summary.toEntity(
                        listPosition = CatalogCacheLimits.UNLISTED_POSITION,
                        cachedAt = clock.millis(),
                    ),
                    description = detail.description,
                    variants = detail.variants.mapIndexed { index, variant ->
                        variant.toEntity(productSlug = detail.summary.slug, position = index)
                    },
                )
            }
            is ApiResult.Failure -> {
                // Server memastikan produk ini tidak lagi dijual: jangan sampai tampil lagi saat offline.
                val error = result.error
                if (error is ApiError.Http && error.code == ApiErrorCodes.NOT_FOUND) {
                    writeCache { cache.deleteProduct(slug) }
                }
            }
        }
        return result
    }

    override suspend fun cachedProducts(): List<ProductSummary> = readCache(emptyList()) {
        cache.observeListedProducts(OFFLINE_LIST_PAGES * PAGE_SIZE).first().mapNotNull { it.toDomain() }
    }

    override suspend fun cachedCategories(): List<Category> = readCache(emptyList()) {
        cache.observeCategories().first().map { it.toDomain() }
    }

    override suspend fun cachedProduct(slug: String): ProductDetail? = readCache(null) {
        cache.findProductWithDetail(slug)?.toDomain()
    }

    private suspend fun writeCache(block: suspend () -> Unit) {
        try {
            block()
        } catch (e: CancellationException) {
            throw e
        } catch (_: Exception) {
            // Mis. penyimpanan penuh: data dari server tetap ditampilkan, hanya tidak tersimpan.
        }
    }

    private suspend fun <T> readCache(fallback: T, block: suspend () -> T): T = try {
        block()
    } catch (e: CancellationException) {
        throw e
    } catch (_: Exception) {
        fallback
    }

    companion object {
        /** Ukuran halaman yang diminta (bawaan server; batas server 50). */
        const val PAGE_SIZE = 20

        /** Banyaknya halaman pertama daftar bawaan yang disimpan untuk offline (60 produk). */
        const val OFFLINE_LIST_PAGES = 3
    }
}
