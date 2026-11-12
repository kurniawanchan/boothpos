package com.boothpos.customer.feature.catalog.presentation

import com.boothpos.customer.core.data.domain.model.PageInfo
import com.boothpos.customer.core.data.domain.model.Paged
import com.boothpos.customer.core.network.error.ApiError
import com.boothpos.customer.core.network.error.ApiResult
import com.boothpos.customer.feature.catalog.domain.CatalogRepository
import com.boothpos.customer.feature.catalog.domain.Category
import com.boothpos.customer.feature.catalog.domain.ProductDetail
import com.boothpos.customer.feature.catalog.domain.ProductQuery
import com.boothpos.customer.feature.catalog.domain.ProductSummary
import com.boothpos.customer.feature.catalog.domain.ProductVariant
import java.math.BigDecimal

/** Data contoh katalog yang jelas-jelas palsu. */
internal object CatalogTestData {

    fun product(
        index: Int,
        name: String = "Produk Uji $index",
        priceMin: String = "15000.00",
        priceMax: String = priceMin,
        inStock: Boolean = true,
    ) = ProductSummary(
        slug = "produk-uji-$index",
        name = name,
        imageUrl = null,
        categoryId = 1,
        categoryName = "Aksesori",
        sellerName = "Penjual Uji",
        priceMin = BigDecimal(priceMin),
        priceMax = BigDecimal(priceMax),
        inStock = inStock,
    )

    fun products(range: IntRange) = range.map { product(it) }

    fun page(items: List<ProductSummary>, page: Int = 1, lastPage: Int = 1) =
        Paged(items, PageInfo(currentPage = page, perPage = 20, total = lastPage * 20, lastPage = lastPage))

    fun variant(
        id: Long,
        name: String = "Varian $id",
        price: String = "15000.00",
        inStock: Boolean = true,
        maxQty: Int = 5,
    ) = ProductVariant(id = id, name = name, imageUrl = null, price = BigDecimal(price), inStock = inStock, maxQty = maxQty)

    fun detail(
        variants: List<ProductVariant> = listOf(variant(31, "Kecil", "15000.00", maxQty = 3), variant(32, "Besar", "25000.00", maxQty = 5)),
        description: String? = "Gantungan kunci akrilik contoh.",
    ) = ProductDetail(
        summary = product(1, name = "Gantungan Kunci Uji", priceMin = "15000.00", priceMax = "25000.00"),
        description = description,
        variants = variants,
    )

    val categories = listOf(Category(1, "Aksesori", null), Category(2, "Poster", null))
}

/**
 * Fake repositori katalog. `productsHandler` / `detailHandler` adalah fungsi suspend, sehingga tes
 * dapat meniru jawaban lambat dengan `delay` (waktu virtual) dan memeriksa pembatalannya.
 */
internal class FakeCatalogRepository : CatalogRepository {

    data class ProductsCall(val query: ProductQuery, val page: Int)

    val productCalls = mutableListOf<ProductsCall>()
    var productsHandler: suspend (ProductQuery, Int) -> ApiResult<Paged<ProductSummary>> =
        { _, page -> ApiResult.Success(CatalogTestData.page(emptyList(), page)) }

    var categoriesCalls = 0
    var categoriesResult: ApiResult<List<Category>> = ApiResult.Success(emptyList())

    val detailCalls = mutableListOf<String>()
    var detailHandler: suspend (String) -> ApiResult<ProductDetail> = { ApiResult.Failure(ApiError.Unknown) }

    var cachedProductList: List<ProductSummary> = emptyList()
    var cachedCategoryList: List<Category> = emptyList()
    var cachedDetail: ProductDetail? = null

    override suspend fun products(query: ProductQuery, page: Int): ApiResult<Paged<ProductSummary>> {
        productCalls += ProductsCall(query, page)
        return productsHandler(query, page)
    }

    override suspend fun categories(): ApiResult<List<Category>> {
        categoriesCalls++
        return categoriesResult
    }

    override suspend fun product(slug: String): ApiResult<ProductDetail> {
        detailCalls += slug
        return detailHandler(slug)
    }

    override suspend fun cachedProducts(): List<ProductSummary> = cachedProductList

    override suspend fun cachedCategories(): List<Category> = cachedCategoryList

    override suspend fun cachedProduct(slug: String): ProductDetail? = cachedDetail
}
