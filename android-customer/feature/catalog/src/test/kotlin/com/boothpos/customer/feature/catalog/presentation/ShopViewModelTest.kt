package com.boothpos.customer.feature.catalog.presentation

import androidx.lifecycle.SavedStateHandle
import com.boothpos.customer.core.data.testing.FakeConnectivityObserver
import com.boothpos.customer.core.data.testing.MainDispatcherRule
import com.boothpos.customer.core.network.error.ApiError
import com.boothpos.customer.core.network.error.ApiErrorCodes
import com.boothpos.customer.core.network.error.ApiResult
import com.boothpos.customer.feature.catalog.domain.ProductQuery
import com.boothpos.customer.feature.catalog.domain.ProductSort
import com.boothpos.customer.feature.catalog.presentation.CatalogTestData.page
import com.boothpos.customer.feature.catalog.presentation.CatalogTestData.product
import com.boothpos.customer.feature.catalog.presentation.CatalogTestData.products
import com.boothpos.customer.feature.catalog.presentation.FakeCatalogRepository.ProductsCall
import kotlin.coroutines.cancellation.CancellationException
import kotlinx.coroutines.ExperimentalCoroutinesApi
import kotlinx.coroutines.NonCancellable
import kotlinx.coroutines.delay
import kotlinx.coroutines.test.advanceTimeBy
import kotlinx.coroutines.test.advanceUntilIdle
import kotlinx.coroutines.test.runCurrent
import kotlinx.coroutines.test.runTest
import kotlinx.coroutines.withContext
import org.junit.Assert.assertEquals
import org.junit.Assert.assertFalse
import org.junit.Assert.assertNull
import org.junit.Assert.assertTrue
import org.junit.Rule
import org.junit.Test

@OptIn(ExperimentalCoroutinesApi::class)
class ShopViewModelTest {

    @get:Rule
    val mainRule = MainDispatcherRule()

    private val repository = FakeCatalogRepository()
    private val connectivity = FakeConnectivityObserver()

    private fun viewModel(handle: SavedStateHandle = SavedStateHandle()) = ShopViewModel(handle, repository, connectivity)

    private fun ShopViewModel.content() = uiState.value.content
    private fun ShopViewModel.shown() = uiState.value.content as ShopContent.Products
    private fun ShopViewModel.names() = shown().items.map { it.name }

    /** Tiga halaman berisi 20 produk; halaman ke-n berisi produk (n-1)*20+1 .. n*20. */
    private fun servePages(lastPage: Int = 3) {
        repository.productsHandler = { _, page ->
            ApiResult.Success(page(products((page - 1) * 20 + 1..page * 20), page, lastPage))
        }
    }

    private val serverError = ApiError.Http(500, ApiErrorCodes.SERVER_ERROR)
    private val storeClosed = ApiError.Http(503, ApiErrorCodes.CUSTOMER_COMMERCE_DISABLED)

    // --- muat awal ---

    @Test
    fun `muat awal menampilkan memuat lalu halaman pertama dan kategori`() = runTest(mainRule.dispatcher) {
        servePages()
        repository.categoriesResult = ApiResult.Success(CatalogTestData.categories)

        val viewModel = viewModel()
        assertEquals(ShopContent.Loading, viewModel.content())
        advanceUntilIdle()

        assertEquals(products(1..20), viewModel.shown().items)
        assertTrue(viewModel.shown().canLoadMore)
        assertNull(viewModel.shown().staleReason)
        assertEquals(CatalogTestData.categories, viewModel.uiState.value.categories)
        // Hanya halaman pertama: katalog tidak dimuat seluruhnya di muka.
        assertEquals(listOf(ProductsCall(ProductQuery(), 1)), repository.productCalls)
        assertEquals(1, repository.categoriesCalls)
    }

    @Test
    fun `toko tanpa produk tampil kosong dan pencarian tanpa hasil tampil sebagai tidak ditemukan`() =
        runTest(mainRule.dispatcher) {
            val viewModel = viewModel()
            advanceUntilIdle()
            assertEquals(ShopContent.Empty(filtered = false), viewModel.content())

            viewModel.onSearchChange("tidak ada")
            advanceUntilIdle()
            assertEquals(ShopContent.Empty(filtered = true), viewModel.content())

            // "Hapus pencarian dan filter" mengembalikan daftar bawaan.
            servePages(lastPage = 1)
            viewModel.clearFilters()
            advanceUntilIdle()

            assertEquals("", viewModel.uiState.value.search)
            assertEquals(20, viewModel.shown().items.size)
            assertEquals(ProductsCall(ProductQuery(), 1), repository.productCalls.last())
        }

    // --- halaman berikutnya ---

    @Test
    fun `mendekati akhir daftar menambahkan halaman berikutnya tanpa duplikat sampai halaman terakhir`() =
        runTest(mainRule.dispatcher) {
            repository.productsHandler = { _, page ->
                when (page) {
                    1 -> ApiResult.Success(page(products(1..20), 1, lastPage = 2))
                    // Produk 20 muncul lagi: daftar di server bergeser di antara dua permintaan.
                    else -> ApiResult.Success(page(products(20..30), page, lastPage = 2))
                }
            }
            val viewModel = viewModel()
            advanceUntilIdle()

            viewModel.loadMore()
            viewModel.loadMore()
            assertTrue(viewModel.shown().loadingMore)
            advanceUntilIdle()

            assertEquals(products(1..30), viewModel.shown().items)
            assertFalse(viewModel.shown().loadingMore)
            assertFalse("Halaman terakhir sudah dimuat", viewModel.shown().canLoadMore)
            assertEquals(listOf(1, 2), repository.productCalls.map { it.page })

            viewModel.loadMore()
            advanceUntilIdle()
            assertEquals(2, repository.productCalls.size)
        }

    @Test
    fun `halaman berikutnya yang gagal mempertahankan daftar dan hanya diulang atas permintaan`() =
        runTest(mainRule.dispatcher) {
            var failNextPage = true
            repository.productsHandler = { _, page ->
                if (page > 1 && failNextPage) {
                    ApiResult.Failure(ApiError.Timeout)
                } else {
                    ApiResult.Success(page(products((page - 1) * 20 + 1..page * 20), page, lastPage = 3))
                }
            }
            val viewModel = viewModel()
            advanceUntilIdle()

            viewModel.loadMore()
            advanceUntilIdle()
            assertEquals(ApiError.Timeout, viewModel.shown().loadMoreError)
            assertEquals(20, viewModel.shown().items.size)

            // Guliran yang masih di dekat akhir tidak mengulang permintaan yang baru saja gagal.
            viewModel.loadMore()
            advanceUntilIdle()
            assertEquals(2, repository.productCalls.size)

            failNextPage = false
            viewModel.retryLoadMore()
            advanceUntilIdle()

            assertNull(viewModel.shown().loadMoreError)
            assertEquals(40, viewModel.shown().items.size)
            assertEquals(ProductsCall(ProductQuery(), 2), repository.productCalls.last())
        }

    @Test
    fun `halaman kosong menghentikan pemuatan walau meta menyebut masih ada`() = runTest(mainRule.dispatcher) {
        repository.productsHandler = { _, page ->
            ApiResult.Success(page(if (page == 1) products(1..20) else emptyList(), page, lastPage = 9))
        }
        val viewModel = viewModel()
        advanceUntilIdle()

        viewModel.loadMore()
        advanceUntilIdle()

        assertFalse(viewModel.shown().canLoadMore)
        assertEquals(20, viewModel.shown().items.size)
    }

    // --- pencarian ---

    @Test
    fun `pencarian ditunda sampai ketikan berhenti lalu dikirim satu kali dari halaman pertama`() =
        runTest(mainRule.dispatcher) {
            servePages()
            val viewModel = viewModel()
            advanceUntilIdle()
            repository.productCalls.clear()

            viewModel.onSearchChange("g")
            advanceTimeBy(200)
            viewModel.onSearchChange("ga")
            advanceTimeBy(200)
            viewModel.onSearchChange("  gantungan ")

            // Kolom langsung mengikuti ketikan; permintaannya belum dikirim.
            assertEquals("  gantungan ", viewModel.uiState.value.search)
            advanceTimeBy(ShopViewModel.SEARCH_DEBOUNCE_MS - 1)
            runCurrent()
            assertTrue(repository.productCalls.isEmpty())

            advanceTimeBy(1)
            runCurrent()

            assertEquals(listOf(ProductsCall(ProductQuery(search = "gantungan"), 1)), repository.productCalls)
        }

    @Test
    fun `tombol cari di papan ketik dan mengosongkan kolom berlaku tanpa menunggu`() = runTest(mainRule.dispatcher) {
        servePages()
        val viewModel = viewModel()
        advanceUntilIdle()

        viewModel.onSearchChange("poster")
        viewModel.onSearchSubmit()
        runCurrent()
        assertEquals(ProductsCall(ProductQuery(search = "poster"), 1), repository.productCalls.last())

        viewModel.onSearchChange("")
        runCurrent()
        assertEquals(ProductsCall(ProductQuery(), 1), repository.productCalls.last())

        // Penundaan yang tersisa tidak mengirim permintaan ketiga.
        advanceUntilIdle()
        assertEquals(3, repository.productCalls.size)
    }

    @Test
    fun `pencarian baru membatalkan permintaan lama sehingga hasil lama tidak pernah tampil`() =
        runTest(mainRule.dispatcher) {
            var cancelled = 0
            repository.productsHandler = { query, page ->
                when (query.search) {
                    "lama" -> {
                        try {
                            delay(5_000)
                        } catch (e: CancellationException) {
                            cancelled++
                            throw e
                        }
                        ApiResult.Success(page(listOf(product(1, name = "Hasil Lama")), page))
                    }
                    "baru" -> ApiResult.Success(page(listOf(product(2, name = "Hasil Baru")), page))
                    else -> ApiResult.Success(page(emptyList(), page))
                }
            }
            val viewModel = viewModel()
            advanceUntilIdle()

            viewModel.onSearchChange("lama")
            advanceTimeBy(ShopViewModel.SEARCH_DEBOUNCE_MS)
            runCurrent()
            assertEquals(ShopContent.Loading, viewModel.content())

            viewModel.onSearchChange("baru")
            advanceTimeBy(ShopViewModel.SEARCH_DEBOUNCE_MS)
            runCurrent()

            assertEquals(listOf("Hasil Baru"), viewModel.names())
            assertEquals("Permintaan lama dibatalkan", 1, cancelled)

            advanceTimeBy(10_000)
            runCurrent()
            assertEquals(listOf("Hasil Baru"), viewModel.names())
        }

    @Test
    fun `jawaban lama yang tetap tiba setelah kueri berganti dibuang`() = runTest(mainRule.dispatcher) {
        repository.productsHandler = { query, page ->
            if (query.search == "lama") {
                // Meniru panggilan yang tidak dapat dibatalkan dan tetap menjawab.
                withContext(NonCancellable) { delay(5_000) }
                ApiResult.Success(page(listOf(product(1, name = "Hasil Lama")), page))
            } else {
                ApiResult.Success(page(listOf(product(2, name = "Hasil Baru")), page))
            }
        }
        val viewModel = viewModel()
        advanceUntilIdle()

        viewModel.onSearchChange("lama")
        viewModel.onSearchSubmit()
        runCurrent()
        viewModel.onSearchChange("baru")
        viewModel.onSearchSubmit()
        advanceUntilIdle()

        assertEquals(listOf("Hasil Baru"), viewModel.names())
    }

    @Test
    fun `teks pencarian dibatasi ke panjang maksimum server`() = runTest(mainRule.dispatcher) {
        val viewModel = viewModel()

        viewModel.onSearchChange("a".repeat(150))
        advanceUntilIdle()

        assertEquals(ProductQuery.MAX_SEARCH_LENGTH, viewModel.uiState.value.search.length)
        assertEquals(ProductQuery.MAX_SEARCH_LENGTH, repository.productCalls.last().query.search.length)
    }

    // --- filter dan urutan ---

    @Test
    fun `mengganti kategori atau urutan memulai lagi dari halaman pertama dengan kueri baru`() =
        runTest(mainRule.dispatcher) {
            repository.categoriesResult = ApiResult.Success(CatalogTestData.categories)
            repository.productsHandler = { query, page ->
                val items = if (query.categoryId == 2L) products(100 * page..100 * page + 4) else products((page - 1) * 20 + 1..page * 20)
                ApiResult.Success(page(items, page, lastPage = 3))
            }
            val viewModel = viewModel()
            advanceUntilIdle()
            viewModel.loadMore()
            advanceUntilIdle()
            assertEquals(40, viewModel.shown().items.size)

            viewModel.onCategorySelected(2)
            // Daftar lama langsung diganti keadaan memuat: tidak ada campuran hasil lama dan baru.
            assertEquals(ShopContent.Loading, viewModel.content())
            advanceUntilIdle()

            assertEquals(2L, viewModel.uiState.value.selectedCategoryId)
            assertEquals(products(100..104), viewModel.shown().items)
            assertEquals(ProductsCall(ProductQuery(categoryId = 2), 1), repository.productCalls.last())

            // Halaman berikutnya memakai kueri baru dan nomor halaman yang sudah diulang.
            viewModel.loadMore()
            advanceUntilIdle()
            assertEquals(ProductsCall(ProductQuery(categoryId = 2), 2), repository.productCalls.last())
            assertEquals(10, viewModel.shown().items.size)

            viewModel.onSortSelected(ProductSort.PriceAsc)
            advanceUntilIdle()
            assertEquals(ProductSort.PriceAsc, viewModel.uiState.value.sort)
            assertEquals(
                ProductsCall(ProductQuery(categoryId = 2, sort = ProductSort.PriceAsc), 1),
                repository.productCalls.last(),
            )
            assertEquals(5, viewModel.shown().items.size)

            // Memilih nilai yang sama tidak mengirim permintaan.
            val calls = repository.productCalls.size
            viewModel.onCategorySelected(2)
            viewModel.onSortSelected(ProductSort.PriceAsc)
            advanceUntilIdle()
            assertEquals(calls, repository.productCalls.size)
        }

    @Test
    fun `memilih kategori saat pencarian masih ditunda mengirim satu permintaan gabungan`() =
        runTest(mainRule.dispatcher) {
            repository.categoriesResult = ApiResult.Success(CatalogTestData.categories)
            servePages()
            val viewModel = viewModel()
            advanceUntilIdle()
            repository.productCalls.clear()

            viewModel.onSearchChange("kunci")
            viewModel.onCategorySelected(1)
            advanceUntilIdle()

            assertEquals(listOf(ProductsCall(ProductQuery(search = "kunci", categoryId = 1), 1)), repository.productCalls)
        }

    @Test
    fun `kategori terpilih yang tidak lagi ada di server dilepas`() = runTest(mainRule.dispatcher) {
        repository.categoriesResult = ApiResult.Success(CatalogTestData.categories)
        servePages()
        val handle = SavedStateHandle()
        viewModel(handle).apply {
            advanceUntilIdle()
            onCategorySelected(2)
            advanceUntilIdle()
        }

        // Proses dipulihkan setelah kategori 2 dihapus toko.
        repository.categoriesResult = ApiResult.Success(CatalogTestData.categories.take(1))
        val restored = viewModel(handle)
        assertEquals(2L, restored.uiState.value.selectedCategoryId)
        advanceUntilIdle()

        assertNull(restored.uiState.value.selectedCategoryId)
        assertEquals(ProductsCall(ProductQuery(), 1), repository.productCalls.last())
    }

    @Test
    fun `pencarian kategori dan urutan dipulihkan dari saved state`() = runTest(mainRule.dispatcher) {
        repository.categoriesResult = ApiResult.Success(CatalogTestData.categories)
        servePages()
        val handle = SavedStateHandle()
        viewModel(handle).apply {
            advanceUntilIdle()
            onSearchChange("kunci")
            onCategorySelected(2)
            onSortSelected(ProductSort.Name)
            advanceUntilIdle()
        }
        repository.productCalls.clear()

        val restored = viewModel(handle)
        advanceUntilIdle()

        assertEquals("kunci", restored.uiState.value.search)
        assertEquals(2L, restored.uiState.value.selectedCategoryId)
        assertEquals(ProductSort.Name, restored.uiState.value.sort)
        assertEquals(
            listOf(ProductsCall(ProductQuery(search = "kunci", categoryId = 2, sort = ProductSort.Name), 1)),
            repository.productCalls,
        )
    }

    // --- galat, coba lagi, penyegaran ---

    @Test
    fun `galat halaman pertama dapat dicoba lagi dan ketukan ganda tidak mengirim dua kali`() =
        runTest(mainRule.dispatcher) {
            var fail = true
            repository.productsHandler = { _, page ->
                if (fail) ApiResult.Failure(serverError) else ApiResult.Success(page(products(1..3), page))
            }
            val viewModel = viewModel()
            advanceUntilIdle()
            assertEquals(ShopContent.Error(serverError), viewModel.content())

            fail = false
            viewModel.refresh()
            viewModel.refresh()
            assertEquals(ShopContent.Error(serverError, retrying = true), viewModel.content())
            advanceUntilIdle()

            assertEquals(products(1..3), viewModel.shown().items)
            assertEquals(2, repository.productCalls.size)
            assertEquals("Kategori ikut dimuat ulang", 2, repository.categoriesCalls)
        }

    @Test
    fun `penyegaran mempertahankan daftar dan mengganti isinya dengan halaman pertama terbaru`() =
        runTest(mainRule.dispatcher) {
            servePages()
            val viewModel = viewModel()
            advanceUntilIdle()
            viewModel.loadMore()
            advanceUntilIdle()

            viewModel.refresh()
            assertTrue(viewModel.shown().refreshing)
            assertEquals("Daftar tetap tampil selama menyegarkan", 40, viewModel.shown().items.size)
            advanceUntilIdle()

            assertFalse(viewModel.shown().refreshing)
            assertEquals(products(1..20), viewModel.shown().items)
            // Penomoran halaman diulang: halaman berikutnya adalah halaman 2.
            viewModel.loadMore()
            advanceUntilIdle()
            assertEquals(ProductsCall(ProductQuery(), 2), repository.productCalls.last())
        }

    @Test
    fun `penyegaran yang gagal tetap menampilkan daftar dengan penanda belum terbaru`() =
        runTest(mainRule.dispatcher) {
            var failure: ApiError? = null
            repository.productsHandler = { _, page ->
                failure?.let { ApiResult.Failure(it) } ?: ApiResult.Success(page(products(1..20), page, lastPage = 2))
            }
            val viewModel = viewModel()
            advanceUntilIdle()

            failure = ApiError.Timeout
            viewModel.refresh()
            advanceUntilIdle()

            assertEquals(20, viewModel.shown().items.size)
            assertFalse(viewModel.shown().refreshing)
            assertEquals(ApiError.Timeout, viewModel.shown().staleReason)

            // Toko ditutup: daftar lama tidak boleh tetap tampil.
            failure = storeClosed
            viewModel.refresh()
            advanceUntilIdle()
            assertEquals(ShopContent.Error(storeClosed), viewModel.content())
        }

    @Test
    fun `toko yang ditutup saat memuat halaman berikutnya mengganti daftar dengan keadaan toko tutup`() =
        runTest(mainRule.dispatcher) {
            repository.productsHandler = { _, page ->
                if (page == 1) ApiResult.Success(page(products(1..20), 1, lastPage = 2)) else ApiResult.Failure(storeClosed)
            }
            val viewModel = viewModel()
            advanceUntilIdle()

            viewModel.loadMore()
            advanceUntilIdle()

            assertEquals(ShopContent.Error(storeClosed), viewModel.content())
        }

    // --- offline ---

    @Test
    fun `offline menampilkan daftar dan kategori dari cache dengan penanda offline`() = runTest(mainRule.dispatcher) {
        repository.productsHandler = { _, _ -> ApiResult.Failure(ApiError.Network) }
        repository.categoriesResult = ApiResult.Failure(ApiError.Network)
        repository.cachedProductList = products(1..3)
        repository.cachedCategoryList = CatalogTestData.categories

        val viewModel = viewModel()
        advanceUntilIdle()

        assertEquals(products(1..3), viewModel.shown().items)
        assertEquals(ApiError.Network, viewModel.shown().staleReason)
        assertFalse("Cache bukan daftar berhalaman", viewModel.shown().canLoadMore)
        assertEquals(CatalogTestData.categories, viewModel.uiState.value.categories)

        viewModel.loadMore()
        advanceUntilIdle()
        assertEquals(1, repository.productCalls.size)
    }

    @Test
    fun `offline tanpa cache atau dengan filter aktif tampil sebagai galat koneksi`() = runTest(mainRule.dispatcher) {
        repository.productsHandler = { _, _ -> ApiResult.Failure(ApiError.Network) }
        val viewModel = viewModel()
        advanceUntilIdle()
        assertEquals(ShopContent.Error(ApiError.Network), viewModel.content())

        // Cache hanya berisi daftar bawaan: hasil filter tidak boleh dikarang dari cache.
        repository.cachedProductList = products(1..3)
        viewModel.onSearchChange("kunci")
        advanceUntilIdle()
        assertEquals(ShopContent.Error(ApiError.Network), viewModel.content())

        // Galat server (bukan offline) juga tidak jatuh ke cache.
        repository.productsHandler = { _, _ -> ApiResult.Failure(serverError) }
        viewModel.clearFilters()
        advanceUntilIdle()
        assertEquals(ShopContent.Error(serverError), viewModel.content())
    }

    @Test
    fun `koneksi yang kembali memuat ulang data offline tetapi tidak mengganggu data terbaru`() =
        runTest(mainRule.dispatcher) {
            connectivity.set(false)
            var online = false
            repository.productsHandler = { _, page ->
                if (online) ApiResult.Success(page(products(1..20), page)) else ApiResult.Failure(ApiError.Network)
            }
            repository.cachedProductList = products(1..3)
            val viewModel = viewModel()
            advanceUntilIdle()
            assertEquals(ApiError.Network, viewModel.shown().staleReason)

            online = true
            connectivity.set(true)
            advanceUntilIdle()

            assertNull(viewModel.shown().staleReason)
            assertEquals(20, viewModel.shown().items.size)
            assertEquals(2, repository.productCalls.size)

            // Data sudah terbaru: naik-turun koneksi berikutnya tidak memicu permintaan.
            connectivity.set(false)
            advanceUntilIdle()
            connectivity.set(true)
            advanceUntilIdle()
            assertEquals(2, repository.productCalls.size)
        }
}
