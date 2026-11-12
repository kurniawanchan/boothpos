package com.boothpos.customer.feature.catalog.data

import android.content.Context
import androidx.room.Room
import androidx.test.core.app.ApplicationProvider
import androidx.test.ext.junit.runners.AndroidJUnit4
import com.boothpos.customer.core.data.local.AppDatabase
import com.boothpos.customer.core.data.local.CatalogCacheDao
import com.boothpos.customer.core.data.local.CatalogCacheLimits
import com.boothpos.customer.core.network.HttpClientFactory
import com.boothpos.customer.core.network.NetworkConfig
import com.boothpos.customer.core.network.NetworkJson
import com.boothpos.customer.core.network.RetrofitFactory
import com.boothpos.customer.core.network.UnauthorizedEvents
import com.boothpos.customer.core.network.error.ApiError
import com.boothpos.customer.core.network.error.ApiErrorCodes
import com.boothpos.customer.core.network.error.ApiResult
import com.boothpos.customer.core.network.error.ProblemJsonParser
import com.boothpos.customer.core.network.error.SafeApiCall
import com.boothpos.customer.core.network.error.errorOrNull
import com.boothpos.customer.core.network.error.isStoreUnavailable
import com.boothpos.customer.feature.catalog.domain.Category
import com.boothpos.customer.feature.catalog.domain.ProductQuery
import com.boothpos.customer.feature.catalog.domain.ProductSort
import java.math.BigDecimal
import java.time.Clock
import java.time.Instant
import java.time.ZoneId
import java.time.ZoneOffset
import kotlinx.coroutines.test.runTest
import mockwebserver3.Dispatcher
import mockwebserver3.MockResponse
import mockwebserver3.MockWebServer
import mockwebserver3.RecordedRequest
import org.junit.After
import org.junit.Assert.assertEquals
import org.junit.Assert.assertFalse
import org.junit.Assert.assertNotNull
import org.junit.Assert.assertNull
import org.junit.Assert.assertTrue
import org.junit.Before
import org.junit.Test
import org.junit.runner.RunWith

/**
 * Kontrak §6 (`/storefront/categories`, `/storefront/products`, `/storefront/products/{slug}`)
 * terhadap MockWebServer, dengan cache Room in-memory sungguhan (Robolectric).
 */
@RunWith(AndroidJUnit4::class)
class CatalogRepositoryImplTest {

    /** Setiap pembacaan waktu maju 1 ms, supaya urutan "paling baru di-cache" pasti. */
    private class TickingClock : Clock() {
        private var now = 1_700_000_000_000L

        override fun millis(): Long = now++

        override fun instant(): Instant = Instant.ofEpochMilli(millis())

        override fun getZone(): ZoneId = ZoneOffset.UTC

        override fun withZone(zone: ZoneId?): Clock = this
    }

    private val server = MockWebServer()
    private val json = NetworkJson.create()
    private lateinit var database: AppDatabase
    private lateinit var dao: CatalogCacheDao
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
        dao = database.catalogCacheDao()
        repository = CatalogRepositoryImpl(api, SafeApiCall(ProblemJsonParser(json)), dao, TickingClock())
    }

    @After
    fun tearDown() {
        database.close()
        server.close()
    }

    // Data contoh yang jelas-jelas palsu. Field tambahan meniru server yang lebih baru.
    private fun cardJson(index: Int, priceMin: String = "15000.00", priceMax: String = "25000.00") =
        """{"slug":"produk-uji-$index","name":"Produk Uji $index","image_url":"https://cdn.example.test/p$index.png",
            "category":{"id":1,"name":"Aksesori","icon":"x"},"seller_name":"Penjual Uji",
            "price_min":"$priceMin","price_max":"$priceMax","in_stock":true,
            "rating":4.5,"badges":["baru"]}"""

    private fun listJson(indexes: Iterable<Int>, page: Int = 1, lastPage: Int = 1) =
        """{"data":[${indexes.joinToString(",") { cardJson(it) }}],
            "meta":{"current_page":$page,"per_page":20,"total":${lastPage * 20},"last_page":$lastPage,"from":1},
            "links":{"next":null}}"""

    private fun detailJson(slug: String = SLUG) =
        """{"data":{"slug":"$slug","name":"Gantungan Kunci Uji","image_url":null,
            "category":{"id":1,"name":"Aksesori"},"seller_name":"Penjual Uji",
            "price_min":"15000.00","price_max":"25000.00","in_stock":true,
            "description":"Akrilik 5 cm.","published_note":"abaikan",
            "variants":[
              {"id":31,"name":"Kecil","image_url":null,"price":"15000.00","in_stock":true,"max_qty":3,"weight":10},
              {"id":32,"name":"Besar","image_url":"https://cdn.example.test/v32.png","price":"25000.00","in_stock":false,"max_qty":0}
            ]}}"""

    private fun enqueue(body: String, code: Int = 200) =
        server.enqueue(MockResponse.Builder().code(code).body(body).build())

    private suspend fun cachedSlugs() = repository.cachedProducts().map { it.slug }

    // --- daftar produk ---

    @Test
    fun `daftar produk mengirim parameter kueri dan memetakan kartu serta halaman`() = runTest {
        enqueue(listJson(listOf(1, 2), page = 2, lastPage = 3))

        val result = repository.products(
            ProductQuery(search = "  gantungan kunci ", categoryId = 7, sort = ProductSort.PriceDesc),
            page = 2,
        )

        val paged = (result as ApiResult.Success).value
        assertEquals(2, paged.items.size)
        val card = paged.items.first()
        assertEquals("produk-uji-1", card.slug)
        assertEquals("Produk Uji 1", card.name)
        assertEquals("https://cdn.example.test/p1.png", card.imageUrl)
        assertEquals(1L, card.categoryId)
        assertEquals("Aksesori", card.categoryName)
        assertEquals("Penjual Uji", card.sellerName)
        assertEquals(BigDecimal("15000.00"), card.priceMin)
        assertEquals(BigDecimal("25000.00"), card.priceMax)
        assertTrue(card.hasPriceRange)
        assertTrue(card.inStock)
        assertEquals(2, paged.page.currentPage)
        assertEquals(3, paged.page.lastPage)
        assertTrue(paged.page.hasMore)

        val request = server.takeRequest()
        assertEquals("GET", request.method)
        assertEquals("/api/v1/storefront/products", request.url.encodedPath)
        assertEquals("gantungan kunci", request.url.queryParameter("q"))
        assertEquals("7", request.url.queryParameter("category_id"))
        assertEquals("price_desc", request.url.queryParameter("sort"))
        assertEquals("2", request.url.queryParameter("page"))
        assertEquals("20", request.url.queryParameter("per_page"))
        assertEquals(setOf("q", "category_id", "sort", "page", "per_page"), request.url.queryParameterNames)
        // Endpoint publik: token pelanggan tidak pernah ikut terkirim.
        assertNull(request.headers["Authorization"])
        assertEquals("application/json", request.headers["Accept"])
    }

    @Test
    fun `kueri bawaan tidak mengirim q dan category_id dan setiap urutan memakai nilai yang diizinkan`() = runTest {
        ProductSort.entries.forEach { _ -> enqueue(listJson(emptyList())) }

        ProductSort.entries.forEach { sort -> repository.products(ProductQuery(search = "   ", sort = sort), page = 1) }

        val sent = ProductSort.entries.map { server.takeRequest() }
        assertEquals(listOf("newest", "name", "price_asc", "price_desc"), sent.map { it.url.queryParameter("sort") })
        sent.forEach { request ->
            assertEquals(setOf("sort", "page", "per_page"), request.url.queryParameterNames)
        }
    }

    @Test
    fun `pencarian dipotong ke panjang maksimum server`() = runTest {
        enqueue(listJson(emptyList()))

        repository.products(ProductQuery(search = "a".repeat(150)), page = 1)

        assertEquals(ProductQuery.MAX_SEARCH_LENGTH, server.takeRequest().url.queryParameter("q")?.length)
    }

    @Test
    fun `harga yang tidak terbaca menggagalkan panggilan dan tidak ada yang disimpan`() = runTest {
        enqueue("""{"data":[${cardJson(1, priceMin = "Rp15.000")}],"meta":{"current_page":1,"per_page":20,"total":1,"last_page":1}}""")

        val result = repository.products(ProductQuery(), page = 1)

        assertEquals(ApiError.Unknown, result.errorOrNull())
        assertTrue(repository.cachedProducts().isEmpty())
    }

    // --- cache daftar offline ---

    @Test
    fun `halaman pertama daftar bawaan menjadi daftar offline dan halaman berikutnya menyambungnya`() = runTest {
        enqueue(listJson(listOf(1, 2, 3), page = 1, lastPage = 2))
        enqueue(listJson(listOf(4, 5), page = 2, lastPage = 2))

        repository.products(ProductQuery(), page = 1)
        repository.products(ProductQuery(), page = 2)

        assertEquals((1..5).map { "produk-uji-$it" }, cachedSlugs())
        val cached = repository.cachedProducts().first()
        assertEquals(BigDecimal("15000.00"), cached.priceMin)
        assertEquals("Penjual Uji", cached.sellerName)

        // Halaman pertama yang lebih baru menggantikan daftar offline lama, bukan mencampurnya.
        enqueue(listJson(listOf(9, 2), page = 1, lastPage = 1))
        repository.products(ProductQuery(), page = 1)

        assertEquals(listOf("produk-uji-9", "produk-uji-2"), cachedSlugs())
    }

    @Test
    fun `hasil pencarian filter urutan lain dan halaman di luar batas tidak masuk daftar offline`() = runTest {
        enqueue(listJson(listOf(1, 2)))
        repository.products(ProductQuery(), page = 1)

        enqueue(listJson(listOf(7)))
        repository.products(ProductQuery(search = "kunci"), page = 1)
        enqueue(listJson(listOf(8)))
        repository.products(ProductQuery(categoryId = 2), page = 1)
        enqueue(listJson(listOf(9)))
        repository.products(ProductQuery(sort = ProductSort.Name), page = 1)
        enqueue(listJson(listOf(10), page = 4, lastPage = 9))
        repository.products(ProductQuery(), page = CatalogRepositoryImpl.OFFLINE_LIST_PAGES + 1)

        assertEquals(listOf("produk-uji-1", "produk-uji-2"), cachedSlugs())
        assertEquals("Tidak ada yang disimpan di luar daftar bawaan", 2, dao.countProducts())
    }

    @Test
    fun `gagal jaringan terpetakan dan daftar offline yang sudah ada tetap dapat dibaca`() = runTest {
        enqueue(listJson(listOf(1, 2)))
        repository.products(ProductQuery(), page = 1)

        server.close()
        val result = repository.products(ProductQuery(), page = 1)

        assertEquals(ApiError.Network, result.errorOrNull())
        assertEquals(listOf("produk-uji-1", "produk-uji-2"), cachedSlugs())
    }

    @Test
    fun `toko dimatikan 503 dan instalasi terkunci 423 terpetakan sebagai toko tidak tersedia`() = runTest {
        enqueue(
            """{"type":"https://boothpos.local/problems/customer_commerce_disabled","title":"Tidak tersedia",
                "status":503,"detail":"Toko belum menerima pesanan.","code":"customer_commerce_disabled","request_id":"req-uji-1"}""",
            code = 503,
        )
        enqueue("""{"message":"Belum diaktivasi.","activated":false}""", code = 423)

        val disabled = repository.products(ProductQuery(), page = 1).errorOrNull() as ApiError.Http
        val locked = repository.categories().errorOrNull() as ApiError.Http

        assertEquals(ApiErrorCodes.CUSTOMER_COMMERCE_DISABLED, disabled.code)
        assertEquals("req-uji-1", disabled.requestId)
        assertTrue(disabled.isStoreUnavailable)
        assertTrue(locked.isStoreUnavailable)
    }

    // --- kategori ---

    @Test
    fun `kategori dipetakan dan disimpan untuk offline`() = runTest {
        enqueue(
            """{"data":[{"id":1,"name":"Aksesori","image_url":"https://cdn.example.test/c1.png","products_count":4},
                {"id":2,"name":"Poster","image_url":null}]}""",
        )

        val categories = (repository.categories() as ApiResult.Success).value

        val expected = listOf(
            Category(1, "Aksesori", "https://cdn.example.test/c1.png"),
            Category(2, "Poster", null),
        )
        assertEquals(expected, categories)
        assertEquals(expected, repository.cachedCategories())
        val request = server.takeRequest()
        assertEquals("/api/v1/storefront/categories", request.url.encodedPath)
        assertNull(request.headers["Authorization"])
    }

    // --- detail produk ---

    @Test
    fun `detail produk dipetakan disimpan dan dapat dibuka lagi dari cache`() = runTest {
        enqueue(detailJson())

        val detail = (repository.product(SLUG) as ApiResult.Success).value

        assertEquals(SLUG, detail.summary.slug)
        assertEquals("Gantungan Kunci Uji", detail.summary.name)
        assertNull(detail.summary.imageUrl)
        assertEquals("Akrilik 5 cm.", detail.description)
        assertEquals(listOf(31L, 32L), detail.variants.map { it.id })
        val small = detail.variants[0]
        assertEquals("Kecil", small.name)
        assertEquals(BigDecimal("15000.00"), small.price)
        assertEquals(3, small.maxQty)
        assertTrue(small.isPurchasable)
        val large = detail.variants[1]
        assertEquals("https://cdn.example.test/v32.png", large.imageUrl)
        assertFalse(large.inStock)
        assertFalse(large.isPurchasable)

        val request = server.takeRequest()
        assertEquals("/api/v1/storefront/products/$SLUG", request.url.encodedPath)
        assertNull(request.headers["Authorization"])

        assertEquals(detail, repository.cachedProduct(SLUG))
        // Produk yang hanya dibuka lewat detail tidak menyusup ke daftar offline.
        assertTrue(repository.cachedProducts().isEmpty())
    }

    @Test
    fun `produk yang hanya tersimpan sebagai kartu tidak dianggap punya detail offline`() = runTest {
        enqueue(listJson(listOf(1)))
        repository.products(ProductQuery(), page = 1)

        assertNull(repository.cachedProduct("produk-uji-1"))
        assertNull(repository.cachedProduct("tidak-pernah-dimuat"))
    }

    @Test
    fun `404 not_found dilaporkan dan cache produk itu dibuang`() = runTest {
        enqueue(detailJson())
        repository.product(SLUG)
        assertNotNull(repository.cachedProduct(SLUG))

        enqueue(
            """{"type":"https://boothpos.local/problems/not_found","title":"Tidak ditemukan","status":404,
                "detail":"Data tidak ditemukan.","code":"not_found","request_id":"req-uji-2"}""",
            code = 404,
        )
        val error = repository.product(SLUG).errorOrNull() as ApiError.Http

        assertEquals(ApiErrorCodes.NOT_FOUND, error.code)
        assertEquals(404, error.status)
        assertNull("Produk yang tidak lagi dijual tidak boleh tampil lagi saat offline", repository.cachedProduct(SLUG))
    }

    @Test
    fun `cache dipangkas ke batas tetap dan yang paling lama dibuang lebih dulu`() = runTest {
        server.dispatcher = object : Dispatcher() {
            override fun dispatch(request: RecordedRequest): MockResponse =
                MockResponse.Builder().body(detailJson(slug = request.url.pathSegments.last())).build()
        }
        val overflow = 5

        repeat(CatalogCacheLimits.MAX_PRODUCTS + overflow) { index ->
            // Varian ber-id sama di setiap jawaban: baris varian lama ikut terganti, tidak menumpuk.
            assertTrue(repository.product("produk-uji-${index + 1}") is ApiResult.Success)
        }

        assertEquals(CatalogCacheLimits.MAX_PRODUCTS, dao.countProducts())
        (1..overflow).forEach { assertNull(repository.cachedProduct("produk-uji-$it")) }
        assertNotNull(dao.findProductWithDetail("produk-uji-${overflow + 1}"))
        assertNotNull(repository.cachedProduct("produk-uji-${CatalogCacheLimits.MAX_PRODUCTS + overflow}"))
    }

    private companion object {
        const val SLUG = "gantungan-kunci-uji"
        const val FAKE_TOKEN = "tes-token-palsu-123"
    }
}
