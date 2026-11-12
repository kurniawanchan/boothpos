package com.boothpos.customer.feature.catalog.contract

import android.content.Context
import androidx.room.Room
import androidx.test.core.app.ApplicationProvider
import androidx.test.ext.junit.runners.AndroidJUnit4
import com.boothpos.customer.core.data.local.AppDatabase
import com.boothpos.customer.core.data.testing.RealPayloads
import com.boothpos.customer.core.network.HttpClientFactory
import com.boothpos.customer.core.network.NetworkConfig
import com.boothpos.customer.core.network.NetworkJson
import com.boothpos.customer.core.network.RetrofitFactory
import com.boothpos.customer.core.network.UnauthorizedEvents
import com.boothpos.customer.core.network.dto.DataEnvelope
import com.boothpos.customer.core.network.dto.PagedEnvelope
import com.boothpos.customer.core.network.dto.ProblemDto
import com.boothpos.customer.core.network.error.ApiError
import com.boothpos.customer.core.network.error.ApiErrorCodes
import com.boothpos.customer.core.network.error.ApiResult
import com.boothpos.customer.core.network.error.ProblemJsonParser
import com.boothpos.customer.core.network.error.SafeApiCall
import com.boothpos.customer.core.network.error.errorOrNull
import com.boothpos.customer.core.network.error.isStoreUnavailable
import com.boothpos.customer.feature.catalog.data.CatalogApi
import com.boothpos.customer.feature.catalog.data.CatalogRepositoryImpl
import com.boothpos.customer.feature.catalog.data.CategoryDto
import com.boothpos.customer.feature.catalog.data.ProductCardDto
import com.boothpos.customer.feature.catalog.data.ProductDetailDto
import com.boothpos.customer.feature.catalog.domain.Category
import com.boothpos.customer.feature.catalog.domain.ProductQuery
import java.math.BigDecimal
import java.time.Clock
import java.time.Instant
import java.time.ZoneOffset
import kotlinx.coroutines.test.runTest
import kotlinx.serialization.json.Json
import mockwebserver3.MockWebServer
import org.junit.After
import org.junit.Assert.assertEquals
import org.junit.Assert.assertFalse
import org.junit.Assert.assertNull
import org.junit.Assert.assertTrue
import org.junit.Before
import org.junit.Test
import org.junit.runner.RunWith

/**
 * Kontrak §6 (katalog publik) dan §3 terhadap jawaban NYATA backend, lewat Retrofit + converter +
 * `Json` milik :core:network dan `CatalogRepositoryImpl` (dengan cache Room in-memory sungguhan).
 * Berkas ada di `src/test/resources/real` (lihat README di sana).
 *
 * Satu bentuk tidak pernah terekam: 503 `customer_commerce_disabled` (saklar fitur mati). Tesnya
 * memakai `derived/customer_commerce_disabled_503`, yang disusun dari kode PHP
 * (`EnsureCustomerCommerceEnabled` + `CustomerCommerceProblem` + `lang/id/customer_commerce.php`),
 * bukan rekaman; lihat `derived/README.md`.
 */
@RunWith(AndroidJUnit4::class)
class RealCatalogContractTest {

    private val server = MockWebServer()
    private val json = NetworkJson.create()

    // Diagnostik, bukan jalur aplikasi: `Json` aplikasi yang sama, hanya `ignoreUnknownKeys` dimatikan,
    // supaya field rekaman yang namanya tidak cocok dengan DTO (lalu diam-diam jatuh ke nilai bawaan)
    // ketahuan. Dipakai hanya oleh tes "tidak ada field rekaman yang diabaikan".
    private val strict = Json(from = json) { ignoreUnknownKeys = false }

    private inline fun <reified T> strictDecode(name: String, derived: Boolean = false): T {
        val body = (if (derived) RealPayloads.derived(name) else RealPayloads.recorded(name)).body
        return strict.decodeFromString(body)
    }
    private lateinit var database: AppDatabase
    private lateinit var repository: CatalogRepositoryImpl

    @Before
    fun setUp() {
        server.start()
        val config = NetworkConfig(server.url("/api/v1/").toString())
        // Token ada, tetapi endpoint storefront tidak boleh membawanya.
        val client = HttpClientFactory.create(config, { FAKE_TOKEN }, UnauthorizedEvents())
        val api = RetrofitFactory.create(config, json, client).create(CatalogApi::class.java)
        database = Room.inMemoryDatabaseBuilder(ApplicationProvider.getApplicationContext<Context>(), AppDatabase::class.java)
            .allowMainThreadQueries()
            .build()
        repository = CatalogRepositoryImpl(
            api,
            SafeApiCall(ProblemJsonParser(json)),
            database.catalogCacheDao(),
            Clock.fixed(Instant.parse("2026-11-12T05:00:00Z"), ZoneOffset.UTC),
        )
    }

    @After
    fun tearDown() {
        database.close()
        server.close()
    }

    // --- 200 kategori ---

    @Test
    fun `200 kategori etalase nyata terbaca dan permintaan tidak membawa token`() = runTest {
        val recorded = RealPayloads.recorded("categories_200")
        assertEquals(200, recorded.status)
        server.enqueueRecorded(recorded)

        val categories = (repository.categories() as ApiResult.Success).value

        assertEquals(listOf(Category(id = 1, name = "Bruno Test Category", imageUrl = null)), categories)
        assertEquals(categories, repository.cachedCategories())
        val request = server.takeRequest()
        assertEquals("/api/v1/storefront/categories", request.url.encodedPath)
        assertNull(request.headers["Authorization"])
    }

    // --- 200 daftar produk ---

    @Test
    fun `200 daftar produk nyata - kartu, harga BigDecimal persis, dan meta halaman`() = runTest {
        val recorded = RealPayloads.recorded("products_list_200")
        assertEquals(200, recorded.status)
        server.enqueueRecorded(recorded)

        val paged = (repository.products(ProductQuery(search = "1794456911541"), page = 1) as ApiResult.Success).value

        val card = paged.items.single()
        assertEquals("etalase-bruno-1794456911541", card.slug)
        assertEquals("Etalase Bruno 1794456911541", card.name)
        assertNull(card.imageUrl)
        assertEquals(1L, card.categoryId)
        assertEquals("Bruno Test Category", card.categoryName)
        assertEquals("Bruno Test Seller", card.sellerName)
        // equals() BigDecimal memeriksa skala juga: "75000.00" harus tetap berskala 2.
        assertEquals(BigDecimal("75000.00"), card.priceMin)
        assertEquals(BigDecimal("75000.00"), card.priceMax)
        assertFalse(card.hasPriceRange)
        assertTrue(card.inStock)
        // Aplikasi meminta per_page=20; server menjawab 50 (batas miliknya) dan aplikasi memakai meta server.
        assertEquals(1, paged.page.currentPage)
        assertEquals(50, paged.page.perPage)
        assertEquals(1, paged.page.total)
        assertEquals(1, paged.page.lastPage)
        assertFalse(paged.page.hasMore)

        val request = server.takeRequest()
        assertEquals("/api/v1/storefront/products", request.url.encodedPath)
        assertEquals("1794456911541", request.url.queryParameter("q"))
        assertNull(request.headers["Authorization"])
    }

    @Test
    fun `200 daftar produk bawaan nyata menjadi daftar offline`() = runTest {
        server.enqueueRecorded(RealPayloads.recorded("products_list_200"))

        repository.products(ProductQuery(), page = 1)

        val cached = repository.cachedProducts().single()
        assertEquals("etalase-bruno-1794456911541", cached.slug)
        assertEquals(BigDecimal("75000.00"), cached.priceMin)
        assertTrue(cached.inStock)
    }

    // --- 200 detail produk ---

    @Test
    fun `200 detail produk nyata - varian, harga, in_stock, max_qty, dan cache detail`() = runTest {
        val recorded = RealPayloads.recorded("product_detail_200")
        assertEquals(200, recorded.status)
        server.enqueueRecorded(recorded)

        val detail = (repository.product("etalase-bruno-1794456911541") as ApiResult.Success).value

        assertEquals("etalase-bruno-1794456911541", detail.summary.slug)
        assertEquals("Etalase Bruno 1794456911541", detail.summary.name)
        assertEquals("Bruno Test Seller", detail.summary.sellerName)
        assertEquals(BigDecimal("75000.00"), detail.summary.priceMin)
        assertEquals("Produk uji etalase dari koleksi Bruno.", detail.description)
        val variant = detail.variants.single()
        assertEquals(1L, variant.id)
        assertEquals("Ukuran S", variant.name)
        assertNull(variant.imageUrl)
        assertEquals(BigDecimal("75000.00"), variant.price)
        assertTrue(variant.inStock)
        assertEquals(10, variant.maxQty)
        assertTrue(variant.isPurchasable)
        // Detail yang baru dibuka dapat dibuka lagi saat offline, tanpa selisih apa pun.
        assertEquals(detail, repository.cachedProduct("etalase-bruno-1794456911541"))

        val request = server.takeRequest()
        assertEquals("/api/v1/storefront/products/etalase-bruno-1794456911541", request.url.encodedPath)
        assertNull(request.headers["Authorization"])
    }

    // --- 404 produk belum terbit ---

    @Test
    fun `404 produk belum terbit nyata - not_found tanpa membedakan produk yang tak ada`() = runTest {
        val slug = "tersembunyi-bruno-1794456911541"
        val recorded = RealPayloads.recorded("product_not_published_404")
        assertEquals(404, recorded.status)
        server.enqueueRecorded(recorded)

        val error = repository.product(slug).errorOrNull()

        assertEquals(
            ApiError.Http(
                status = 404,
                code = ApiErrorCodes.NOT_FOUND,
                detail = "Data yang Anda cari tidak ditemukan.",
                requestId = "1033d943-cbb4-4c79-938e-c1746343899a",
            ),
            error,
        )
        assertNull(repository.cachedProduct(slug))
    }

    // --- 422 sort tak dikenal ---

    @Test
    fun `422 validation_failed nyata - galat per kolom sort terbaca`() = runTest {
        val recorded = RealPayloads.recorded("products_unknown_sort_422_validation_failed")
        assertEquals(422, recorded.status)
        server.enqueueRecorded(recorded)

        // Aplikasi hanya mengirim empat nilai sort yang sah; tes ini membuktikan PENGURAIAN bentuk 422
        // sungguhan (rekaman #14 mengirim `sort=acak`), bukan bahwa aplikasi pernah memicunya.
        val error = repository.products(ProductQuery(), page = 1).errorOrNull() as ApiError.Http

        assertEquals(422, error.status)
        assertEquals(ApiErrorCodes.VALIDATION_FAILED, error.code)
        assertEquals("Data yang dikirim tidak valid. Periksa kembali isian Anda.", error.detail)
        // Server mengirim KUNCI terjemahan mentah, bukan kalimat ("validation.in"; temuan sisi backend).
        // ProblemJsonParser membuangnya supaya tidak pernah tampil, tetapi kolomnya tetap tercatat
        // sebagai ditolak (daftar kosong): layar jatuh ke teks lokal / pesan umum.
        assertEquals(mapOf("sort" to emptyList<String>()), error.fieldErrors)
        assertEquals("7810f6ad-b0ac-4da0-a860-4e210244cd0b", error.requestId)
    }

    // --- 503 (diturunkan dari PHP, bukan rekaman) ---

    @Test
    fun `503 customer_commerce_disabled (diturunkan dari PHP) - toko tidak tersedia dengan Retry-After 3600`() = runTest {
        val derived = RealPayloads.derived("customer_commerce_disabled_503")
        assertEquals(503, derived.status)
        server.enqueueRecorded(derived)

        val error = repository.products(ProductQuery(), page = 1).errorOrNull() as ApiError.Http

        assertEquals(ApiErrorCodes.CUSTOMER_COMMERCE_DISABLED, error.code)
        assertEquals(
            "Toko sedang tidak menerima pesanan baru lewat aplikasi. Silakan coba lagi nanti.",
            error.detail,
        )
        assertEquals(3600L, error.retryAfterSeconds)
        assertTrue(error.isStoreUnavailable)
        assertTrue(repository.cachedProducts().isEmpty())
    }

    // --- tidak ada field rekaman yang diabaikan diam-diam ---

    @Test
    fun `setiap field rekaman katalog dan galatnya dimodelkan DTO aplikasi`() {
        strictDecode<DataEnvelope<List<CategoryDto>>>("categories_200")
        strictDecode<PagedEnvelope<ProductCardDto>>("products_list_200")
        strictDecode<DataEnvelope<ProductDetailDto>>("product_detail_200")
        strictDecode<ProblemDto>("product_not_published_404")
        strictDecode<ProblemDto>("products_unknown_sort_422_validation_failed")
        strictDecode<ProblemDto>("customer_commerce_disabled_503", derived = true)
    }

    private companion object {
        const val FAKE_TOKEN = "tes-token-palsu-123"
    }
}
