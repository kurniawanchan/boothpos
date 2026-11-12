package com.boothpos.customer.feature.catalog.presentation

import androidx.compose.runtime.getValue
import androidx.compose.runtime.mutableStateOf
import androidx.compose.runtime.setValue
import androidx.compose.ui.semantics.Role
import androidx.compose.ui.semantics.SemanticsProperties
import androidx.compose.ui.test.SemanticsMatcher
import androidx.compose.ui.test.assert
import androidx.compose.ui.test.assertHasClickAction
import androidx.compose.ui.test.assertHeightIsAtLeast
import androidx.compose.ui.test.assertIsDisplayed
import androidx.compose.ui.test.assertIsEnabled
import androidx.compose.ui.test.assertIsNotEnabled
import androidx.compose.ui.test.assertIsNotSelected
import androidx.compose.ui.test.assertIsSelected
import androidx.compose.ui.test.assertWidthIsAtLeast
import androidx.compose.ui.test.hasClickAction
import androidx.compose.ui.test.hasSetTextAction
import androidx.compose.ui.test.hasText
import androidx.compose.ui.test.junit4.createComposeRule
import androidx.compose.ui.test.onNodeWithContentDescription
import androidx.compose.ui.test.onNodeWithTag
import androidx.compose.ui.test.onNodeWithText
import androidx.compose.ui.test.performClick
import androidx.compose.ui.test.performImeAction
import androidx.compose.ui.test.performScrollToIndex
import androidx.compose.ui.test.performTextInput
import androidx.compose.ui.unit.dp
import androidx.test.ext.junit.runners.AndroidJUnit4
import com.boothpos.customer.core.network.error.ApiError
import com.boothpos.customer.core.network.error.ApiErrorCodes
import com.boothpos.customer.core.ui.theme.BoothTheme
import com.boothpos.customer.feature.catalog.domain.ProductSort
import com.boothpos.customer.feature.catalog.presentation.CatalogTestData.product
import com.boothpos.customer.feature.catalog.presentation.CatalogTestData.products
import org.junit.Assert.assertEquals
import org.junit.Assert.assertTrue
import org.junit.Rule
import org.junit.Test
import org.junit.runner.RunWith
import org.robolectric.annotation.Config

/** Daftar produk stateless di ponsel kecil (360 x 640 dp), tanpa Hilt, jaringan, atau gambar jarak jauh. */
@RunWith(AndroidJUnit4::class)
@Config(qualifiers = "w360dp-h640dp")
class ShopScreenTest {

    @get:Rule
    val rule = createComposeRule()

    private var state by mutableStateOf(ShopUiState())
    private val searches = mutableListOf<String>()
    private var submits = 0
    private val categoryChoices = mutableListOf<Long?>()
    private val sortChoices = mutableListOf<ProductSort>()
    private var refreshes = 0
    private var loadMores = 0
    private var loadMoreRetries = 0
    private var filterClears = 0
    private val opened = mutableListOf<String>()

    private val searchField = hasSetTextAction() and hasText("Cari produk")
    private val sortButton = "Urutkan produk. Urutan sekarang: Terbaru"

    private fun show(content: ShopContent, base: ShopUiState = ShopUiState()) {
        state = base.copy(content = content)
        rule.setContent {
            BoothTheme {
                ShopScreen(
                    state = state,
                    onSearchChange = {
                        searches += it
                        state = state.copy(search = it)
                    },
                    onSearchSubmit = { submits++ },
                    onCategorySelected = { categoryChoices += it },
                    onSortSelected = { sortChoices += it },
                    onRefresh = { refreshes++ },
                    onLoadMore = { loadMores++ },
                    onRetryLoadMore = { loadMoreRetries++ },
                    onClearFilters = { filterClears++ },
                    onProductClick = { opened += it },
                )
            }
        }
    }

    private val twoProducts = listOf(
        product(1, priceMin = "15000.00", priceMax = "25000.00"),
        product(2, priceMin = "20000.00"),
    )

    // --- keadaan ---

    @Test
    fun `memuat menampilkan indikator dan kolom cari tetap ada`() {
        show(ShopContent.Loading)

        rule.onNodeWithText("Memuat…").assertIsDisplayed()
        rule.onNode(searchField).assertIsDisplayed()
    }

    @Test
    fun `toko tanpa produk menampilkan keadaan kosong dengan muat ulang`() {
        show(ShopContent.Empty(filtered = false))

        rule.onNodeWithText("Belum ada produk").assertIsDisplayed()
        rule.onNodeWithText("Muat ulang").assertHasClickAction().assertHeightIsAtLeast(48.dp).performClick()

        assertEquals(1, refreshes)
        assertEquals(0, filterClears)
    }

    @Test
    fun `pencarian tanpa hasil menawarkan menghapus pencarian dan filter`() {
        show(ShopContent.Empty(filtered = true))

        rule.onNodeWithText("Produk tidak ditemukan").assertIsDisplayed()
        rule.onNodeWithText("Coba kata kunci lain atau pilih kategori yang berbeda.").assertIsDisplayed()
        rule.onNodeWithText("Hapus pencarian dan filter").performClick()

        assertEquals(1, filterClears)
        assertEquals(0, refreshes)
    }

    @Test
    fun `galat menampilkan pesan dan coba lagi yang nonaktif selama mengulang`() {
        show(ShopContent.Error(ApiError.Http(500, ApiErrorCodes.SERVER_ERROR)))

        rule.onNodeWithText("Terjadi kesalahan").assertIsDisplayed()
        rule.onNodeWithText("Coba lagi").assertIsEnabled().performClick()
        assertEquals(1, refreshes)

        state = state.copy(content = ShopContent.Error(ApiError.Http(500, ApiErrorCodes.SERVER_ERROR), retrying = true))
        rule.onNodeWithText("Coba lagi").assertIsNotEnabled()
    }

    @Test
    fun `tanpa koneksi dan toko tidak tersedia punya keadaannya sendiri`() {
        show(ShopContent.Error(ApiError.Network))
        rule.onNodeWithText("Tidak ada koneksi").assertIsDisplayed()
        rule.onNodeWithText("Coba lagi").assertHasClickAction()

        state = state.copy(content = ShopContent.Error(ApiError.Http(503, ApiErrorCodes.CUSTOMER_COMMERCE_DISABLED)))
        rule.onNodeWithText("Toko sedang tidak tersedia").assertIsDisplayed()
        rule.onNodeWithText("Coba lagi").performClick()
        assertEquals(1, refreshes)

        state = state.copy(content = ShopContent.Error(ApiError.Http(423, ApiErrorCodes.STORE_LOCKED)))
        rule.onNodeWithText("Toko sedang tidak tersedia").assertIsDisplayed()
    }

    @Test
    fun `offline menampilkan produk dari cache dengan pita offline dan coba lagi`() {
        show(ShopContent.Products(items = twoProducts, staleReason = ApiError.Network))

        rule.onNodeWithText("Anda sedang offline. Menampilkan produk yang terakhir dimuat.").assertIsDisplayed()
        rule.onNodeWithText("Produk Uji 1").assertIsDisplayed()
        rule.onNodeWithText("Coba lagi").assertHeightIsAtLeast(48.dp).performClick()

        assertEquals(1, refreshes)
    }

    @Test
    fun `penyegaran yang gagal memberi tahu bahwa data mungkin belum terbaru`() {
        show(ShopContent.Products(items = twoProducts, staleReason = ApiError.Http(500, ApiErrorCodes.SERVER_ERROR)))

        rule.onNodeWithText("Data terbaru belum dapat dimuat. Yang tampil mungkin belum terbaru.").assertIsDisplayed()
        rule.onNodeWithText("Produk Uji 2").assertIsDisplayed()
    }

    // --- konten ---

    @Test
    fun `kartu produk menampilkan nama penjual harga dan ketersediaan sebagai satu tombol`() {
        show(ShopContent.Products(items = twoProducts + product(3, inStock = false)))

        // Harga varian berbeda -> "Mulai dari"; harga tunggal tampil apa adanya.
        val firstCard = hasText("Produk Uji 1") and hasClickAction()
        rule.onNode(firstCard)
            .assertIsDisplayed()
            .assert(hasText("Penjual Uji"))
            .assert(hasText("Mulai dari Rp15.000"))
            // Ketersediaan terbaca sebagai teks di dalam kartu, bukan warna saja.
            .assert(hasText("Tersedia"))
            .assert(SemanticsMatcher.expectValue(SemanticsProperties.Role, Role.Button))
            .assertHeightIsAtLeast(48.dp)
        rule.onNode(hasText("Produk Uji 2") and hasClickAction()).assert(hasText("Rp20.000"))
        rule.onNode(hasText("Produk Uji 3") and hasClickAction()).assert(hasText("Stok habis"))

        rule.onNode(firstCard).performClick()

        assertEquals(listOf("produk-uji-1"), opened)
    }

    @Test
    fun `daftar pendek yang masih punya halaman berikutnya langsung memintanya`() {
        show(ShopContent.Products(items = twoProducts, canLoadMore = true))
        rule.waitForIdle()

        assertTrue(loadMores >= 1)
    }

    @Test
    fun `halaman berikutnya baru diminta saat guliran mendekati akhir daftar`() {
        show(ShopContent.Products(items = products(1..30), canLoadMore = true))
        rule.waitForIdle()
        assertEquals("Belum diminta selama akhir daftar masih jauh", 0, loadMores)

        rule.onNodeWithTag(CatalogTestTags.PRODUCT_LIST).performScrollToIndex(29)
        rule.waitForIdle()

        assertTrue(loadMores >= 1)
    }

    @Test
    fun `halaman terakhir tidak meminta apa pun`() {
        show(ShopContent.Products(items = twoProducts, canLoadMore = false))
        rule.waitForIdle()

        assertEquals(0, loadMores)
    }

    @Test
    fun `kaki daftar menampilkan sedang memuat lalu galat dengan coba lagi`() {
        show(ShopContent.Products(items = twoProducts, canLoadMore = true, loadingMore = true))
        rule.onNodeWithTag(CatalogTestTags.PRODUCT_LIST).performScrollToIndex(2)
        rule.onNodeWithText("Memuat produk berikutnya…").assertIsDisplayed()

        state = state.copy(
            content = ShopContent.Products(items = twoProducts, canLoadMore = true, loadMoreError = ApiError.Timeout),
        )
        rule.onNodeWithTag(CatalogTestTags.PRODUCT_LIST).performScrollToIndex(2)
        rule.onNodeWithText("Produk berikutnya belum dapat dimuat.").assertIsDisplayed()
        rule.onNodeWithText("Coba lagi").assertHeightIsAtLeast(48.dp).performClick()

        assertEquals(1, loadMoreRetries)
    }

    @Test
    fun `slug produk yang sama dengan kunci item tetap tidak merusak daftar`() {
        // Slug datang dari server; kunci item ganda akan membuat LazyColumn crash.
        val odd = listOf("footer", "header:categories", "header:stale").mapIndexed { index, slug ->
            product(index + 1).copy(slug = slug)
        }
        show(
            content = ShopContent.Products(items = odd, canLoadMore = true, loadingMore = true, staleReason = ApiError.Network),
            base = ShopUiState(categories = CatalogTestData.categories),
        )

        rule.onNodeWithText("Produk Uji 1").assertIsDisplayed()
        rule.onNode(hasText("Produk Uji 1") and hasClickAction()).performClick()
        assertEquals(listOf("footer"), opened)
    }

    // --- cari, kategori, urutan ---

    @Test
    fun `mengetik meneruskan teks dan tombol hapus serta tombol cari bekerja`() {
        show(ShopContent.Products(items = twoProducts))
        rule.onNodeWithContentDescription("Hapus pencarian").assertDoesNotExist()

        rule.onNode(searchField).performTextInput("kunci")
        assertEquals("kunci", searches.last())

        rule.onNode(searchField).performImeAction()
        assertEquals(1, submits)

        rule.onNodeWithContentDescription("Hapus pencarian").assertHeightIsAtLeast(48.dp).performClick()
        assertEquals("", searches.last())
        rule.onNodeWithContentDescription("Hapus pencarian").assertDoesNotExist()
    }

    @Test
    fun `chip kategori menandai pilihan dan meneruskan kategori yang dipilih`() {
        show(
            content = ShopContent.Products(items = twoProducts),
            base = ShopUiState(categories = CatalogTestData.categories),
        )

        rule.onNodeWithText("Semua").assertIsSelected()
        rule.onNodeWithText("Poster").assertIsNotSelected().performClick()
        assertEquals(listOf<Long?>(2L), categoryChoices)

        state = state.copy(selectedCategoryId = 2)
        rule.onNodeWithText("Poster").assertIsSelected()
        rule.onNodeWithText("Semua").assertIsNotSelected().performClick()
        assertEquals(listOf(2L, null), categoryChoices)
    }

    @Test
    fun `chip kategori tetap tersedia saat daftar kosong atau galat`() {
        show(
            content = ShopContent.Empty(filtered = true),
            base = ShopUiState(categories = CatalogTestData.categories, selectedCategoryId = 2),
        )

        rule.onNodeWithText("Poster").assertIsSelected()
        rule.onNodeWithText("Semua").performClick()

        assertEquals(listOf<Long?>(null), categoryChoices)
    }

    @Test
    fun `menu urutan menyebut urutan sekarang dan meneruskan pilihan`() {
        show(ShopContent.Products(items = twoProducts))

        rule.onNodeWithContentDescription(sortButton)
            .assertHasClickAction()
            .assertHeightIsAtLeast(48.dp)
            .assertWidthIsAtLeast(48.dp)
            .performClick()
        rule.onNode(hasText("Terbaru") and hasClickAction()).assertIsSelected()
        rule.onNode(hasText("Nama A–Z") and hasClickAction()).assertIsNotSelected()
        rule.onNodeWithText("Harga tertinggi").assertIsDisplayed()
        rule.onNodeWithText("Harga terendah").performClick()

        assertEquals(listOf(ProductSort.PriceAsc), sortChoices)
        // Menu tertutup setelah memilih.
        rule.onNodeWithText("Harga tertinggi").assertDoesNotExist()
    }

    @Test
    fun `tombol muat ulang adalah padanan tarik untuk segarkan yang dapat ditekan`() {
        show(ShopContent.Products(items = twoProducts))

        rule.onNodeWithContentDescription("Muat ulang")
            .assertHeightIsAtLeast(48.dp)
            .assertWidthIsAtLeast(48.dp)
            .performClick()

        assertEquals(1, refreshes)
    }
}
