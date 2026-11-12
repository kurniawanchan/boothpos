package com.boothpos.customer.feature.catalog.presentation

import android.content.Context
import androidx.compose.material3.SnackbarDuration
import androidx.compose.material3.SnackbarHostState
import androidx.compose.material3.SnackbarResult
import androidx.compose.runtime.LaunchedEffect
import androidx.compose.runtime.getValue
import androidx.compose.runtime.mutableStateOf
import androidx.compose.runtime.setValue
import androidx.compose.ui.semantics.SemanticsProperties
import androidx.compose.ui.test.SemanticsMatcher
import androidx.compose.ui.test.assert
import androidx.compose.ui.test.assertContentDescriptionEquals
import androidx.compose.ui.test.assertCountEquals
import androidx.compose.ui.test.assertHasClickAction
import androidx.compose.ui.test.assertHeightIsAtLeast
import androidx.compose.ui.test.assertIsDisplayed
import androidx.compose.ui.test.assertIsEnabled
import androidx.compose.ui.test.assertIsNotEnabled
import androidx.compose.ui.test.assertIsNotSelected
import androidx.compose.ui.test.assertIsSelected
import androidx.compose.ui.test.assertTextEquals
import androidx.compose.ui.test.assertWidthIsAtLeast
import androidx.compose.ui.test.getUnclippedBoundsInRoot
import androidx.compose.ui.test.hasText
import androidx.compose.ui.test.junit4.createComposeRule
import androidx.compose.ui.test.onAllNodesWithText
import androidx.compose.ui.test.onNodeWithContentDescription
import androidx.compose.ui.test.onNodeWithTag
import androidx.compose.ui.test.onNodeWithText
import androidx.compose.ui.test.performClick
import androidx.compose.ui.test.performScrollTo
import androidx.compose.ui.unit.dp
import androidx.test.core.app.ApplicationProvider
import androidx.test.ext.junit.runners.AndroidJUnit4
import com.boothpos.customer.core.network.error.ApiError
import com.boothpos.customer.core.network.error.ApiErrorCodes
import com.boothpos.customer.core.ui.theme.BoothTheme
import com.boothpos.customer.feature.catalog.presentation.CatalogTestData.detail
import com.boothpos.customer.feature.catalog.presentation.CatalogTestData.variant
import org.junit.Assert.assertEquals
import org.junit.Assert.assertFalse
import org.junit.Assert.assertTrue
import org.junit.Rule
import org.junit.Test
import org.junit.runner.RunWith
import org.robolectric.annotation.Config

/** Detail produk stateless di ponsel kecil (360 x 640 dp), tanpa Hilt, jaringan, atau gambar jarak jauh. */
@RunWith(AndroidJUnit4::class)
@Config(qualifiers = "w360dp-h640dp")
class ProductDetailScreenTest {

    @get:Rule
    val rule = createComposeRule()

    private var state: ProductDetailUiState by mutableStateOf(ProductDetailUiState.Loading)
    private val snackbarHostState = SnackbarHostState()
    private var snackbarMessage: String? by mutableStateOf(null)
    private var snackbarResult: SnackbarResult? = null
    private var retries = 0
    private val variantChoices = mutableListOf<Long>()
    private var adds = 0
    private var backs = 0

    /** Varian Kecil (Rp15.000, maks. 3) terpilih, jumlah 1. */
    private val content = ProductDetailUiState.Content(product = detail(), selectedVariantId = 31, quantity = 1)

    private fun updateContent(transform: (ProductDetailUiState.Content) -> ProductDetailUiState.Content) {
        (state as? ProductDetailUiState.Content)?.let { state = transform(it) }
    }

    /** Callback meniru ViewModel secukupnya, supaya perubahan state terlihat di layar. */
    private fun show(initial: ProductDetailUiState) {
        state = initial
        rule.setContent {
            BoothTheme {
                ProductDetailScreen(
                    state = state,
                    snackbarHostState = snackbarHostState,
                    onRetry = { retries++ },
                    onVariantSelected = { id ->
                        variantChoices += id
                        updateContent { it.copy(selectedVariantId = id) }
                    },
                    onDecreaseQuantity = { updateContent { it.copy(quantity = it.quantity - 1) } },
                    onIncreaseQuantity = { updateContent { it.copy(quantity = it.quantity + 1) } },
                    onAddToCart = { adds++ },
                    onBackToShop = { backs++ },
                )
                snackbarMessage?.let { message ->
                    LaunchedEffect(message) {
                        snackbarResult = snackbarHostState.showSnackbar(
                            message = message,
                            actionLabel = "Lihat keranjang",
                            duration = SnackbarDuration.Long,
                        )
                    }
                }
            }
        }
    }

    private val price get() = rule.onNodeWithTag(CatalogTestTags.DETAIL_PRICE)
    private val quantity get() = rule.onNodeWithTag(CatalogTestTags.DETAIL_QUANTITY)
    private val decrease get() = rule.onNodeWithContentDescription("Kurangi jumlah")
    private val increase get() = rule.onNodeWithContentDescription("Tambah jumlah")
    private val addButton get() = rule.onNodeWithText("Tambah ke keranjang")

    // --- keadaan ---

    @Test
    fun `memuat lalu galat dengan coba lagi`() {
        show(ProductDetailUiState.Loading)
        rule.onNodeWithText("Memuat…").assertIsDisplayed()

        state = ProductDetailUiState.Error(ApiError.Network)
        rule.onNodeWithText("Tidak ada koneksi").assertIsDisplayed()
        rule.onNodeWithText("Coba lagi").performClick()
        assertEquals(1, retries)

        state = ProductDetailUiState.Error(ApiError.Network, retrying = true)
        rule.onNodeWithText("Coba lagi").assertIsNotEnabled()
    }

    @Test
    fun `produk yang tidak lagi dijual menampilkan keadaan tidak tersedia dengan jalan kembali`() {
        show(ProductDetailUiState.NotFound)

        rule.onNodeWithText("Produk tidak lagi tersedia").assertIsDisplayed()
        rule.onNodeWithText("Tambah ke keranjang").assertDoesNotExist()
        rule.onNodeWithText("Kembali ke daftar produk").assertHeightIsAtLeast(48.dp).performClick()

        assertEquals(1, backs)
    }

    @Test
    fun `toko tidak tersedia pada 503 dan 423 tampil tenang dengan coba lagi`() {
        show(ProductDetailUiState.Error(ApiError.Http(503, ApiErrorCodes.CUSTOMER_COMMERCE_DISABLED)))
        rule.onNodeWithText("Toko sedang tidak tersedia").assertIsDisplayed()
        rule.onNodeWithText("Coba lagi").performClick()
        assertEquals(1, retries)

        state = ProductDetailUiState.Error(ApiError.Http(423, ApiErrorCodes.STORE_LOCKED))
        rule.onNodeWithText("Toko sedang tidak tersedia").assertIsDisplayed()
    }

    @Test
    fun `data offline dari cache ditandai dan dapat dicoba lagi`() {
        show(content.copy(offline = true))

        rule.onNode(hasText("Anda sedang offline.", substring = true)).assertIsDisplayed()
        rule.onNodeWithText("Coba lagi").assertHeightIsAtLeast(48.dp).performClick()

        assertEquals(1, retries)
        addButton.assertIsEnabled()
    }

    // --- konten ---

    @Test
    fun `detail menampilkan nama penjual kategori harga ketersediaan dan deskripsi`() {
        show(content)

        rule.onNodeWithText("Gantungan Kunci Uji")
            .performScrollTo()
            .assertIsDisplayed()
            .assert(SemanticsMatcher.keyIsDefined(SemanticsProperties.Heading))
        rule.onNodeWithText("Dijual oleh Penjual Uji").performScrollTo().assertIsDisplayed()
        rule.onNodeWithText("Kategori: Aksesori").performScrollTo().assertIsDisplayed()
        price.assertTextEquals("Rp15.000")
        rule.onNodeWithText("Pilih varian").performScrollTo().assertIsDisplayed()
        rule.onNodeWithText("Gantungan kunci akrilik contoh.").performScrollTo().assertIsDisplayed()
    }

    @Test
    fun `memilih varian lain memperbarui harga dan tanda pilihan`() {
        show(content)
        price.assertTextEquals("Rp15.000")
        rule.onNode(hasText("Kecil")).assertIsSelected()

        // Setiap baris membawa harga dan ketersediaannya sendiri.
        rule.onNode(hasText("Besar"))
            .performScrollTo()
            .assert(hasText("Rp25.000"))
            .assert(hasText("Tersedia"))
            .assertIsNotSelected()
            .assertHeightIsAtLeast(48.dp)
            .performClick()

        assertEquals(listOf(32L), variantChoices)
        price.assertTextEquals("Rp25.000")
        rule.onNode(hasText("Besar")).assertIsSelected()
        rule.onNode(hasText("Kecil")).assertIsNotSelected()
    }

    @Test
    fun `varian yang habis ditandai dengan teks dan tidak dapat dipilih`() {
        show(
            content.copy(
                product = detail(
                    variants = listOf(variant(31, "Kecil", maxQty = 3), variant(33, "Jumbo", "40000.00", inStock = false, maxQty = 0)),
                ),
            ),
        )

        rule.onNode(hasText("Jumbo"))
            .performScrollTo()
            .assert(hasText("Stok habis"))
            .assertIsNotEnabled()
            .assertIsNotSelected()
    }

    @Test
    fun `pengatur jumlah berhenti di satu dan di max_qty`() {
        show(content)

        decrease.performScrollTo().assertIsNotEnabled().assertHeightIsAtLeast(48.dp).assertWidthIsAtLeast(48.dp)
        increase.assertIsEnabled().assertHeightIsAtLeast(48.dp).assertWidthIsAtLeast(48.dp)
        quantity.assertTextEquals("1").assertContentDescriptionEquals("Jumlah: 1")
        rule.onNodeWithText("Maksimal 3 per pesanan").assertIsDisplayed()

        increase.performClick()
        quantity.assertTextEquals("2").assertContentDescriptionEquals("Jumlah: 2")
        decrease.assertIsEnabled()

        increase.performClick()
        quantity.assertTextEquals("3")
        // Batas max_qty varian Kecil: tombol tambah nonaktif, bukan disembunyikan.
        increase.assertIsNotEnabled()

        decrease.performClick()
        quantity.assertTextEquals("2")
        increase.assertIsEnabled()
    }

    @Test
    fun `tombol tambah ke keranjang selalu terlihat dapat ditekan dan nonaktif selama diproses`() {
        show(content)

        addButton.assertIsDisplayed().assertIsEnabled().assertHasClickAction().assertHeightIsAtLeast(48.dp)
        addButton.performClick()
        assertEquals(1, adds)

        updateContent { it.copy(adding = true) }
        addButton
            .assertIsNotEnabled()
            .assert(SemanticsMatcher.expectValue(SemanticsProperties.StateDescription, "Sedang diproses"))
        addButton.performClick()
        assertEquals("Ketukan saat diproses tidak menambah lagi", 1, adds)
    }

    @Test
    fun `produk tanpa varian yang dapat dibeli menjelaskan alasannya dan menonaktifkan tombol`() {
        show(
            ProductDetailUiState.Content(
                product = detail(variants = listOf(variant(33, "Jumbo", "40000.00", inStock = false, maxQty = 0))),
                selectedVariantId = null,
                quantity = 1,
            ),
        )

        // Lencana ketersediaan produk + keterangan di baris varian.
        rule.onAllNodesWithText("Stok habis").assertCountEquals(2)
        rule.onNodeWithText("Produk ini sedang tidak dapat dibeli karena stoknya habis.").performScrollTo().assertIsDisplayed()
        // Tanpa varian terpilih, harga yang tampil adalah rentang harga produknya.
        price.assertTextEquals("Mulai dari Rp15.000")
        addButton.assertIsDisplayed().assertIsNotEnabled()
        increase.assertDoesNotExist()
    }

    // --- konfirmasi keranjang ---

    @Test
    fun `konfirmasi keranjang tampil di atas tombol utama dengan aksi lihat keranjang`() {
        show(content)

        snackbarMessage = "2 barang ditambahkan ke keranjang."
        rule.waitForIdle()

        val message = rule.onNodeWithText("2 barang ditambahkan ke keranjang.").assertIsDisplayed()
        // Konfirmasi tidak menutupi aksi utama.
        assertTrue(message.getUnclippedBoundsInRoot().bottom <= addButton.getUnclippedBoundsInRoot().top)
        rule.onNodeWithText("Lihat keranjang").assertHasClickAction().performClick()
        rule.waitForIdle()

        assertEquals(SnackbarResult.ActionPerformed, snackbarResult)
    }

    @Test
    fun `teks konfirmasi mengikuti hasil penambahan dan hanya menawarkan keranjang bila relevan`() {
        val resources = ApplicationProvider.getApplicationContext<Context>().resources

        val added = ProductDetailEvent.AddedToCart(quantity = 2)
        assertEquals("2 barang ditambahkan ke keranjang.", added.message(resources))
        assertTrue(added.offersCart)

        val capped = ProductDetailEvent.QuantityCapped(maxQuantity = 3)
        assertEquals("Jumlah di keranjang disesuaikan ke batas 3 barang.", capped.message(resources))
        assertTrue(capped.offersCart)

        val full = ProductDetailEvent.CartFull(maxLines = 50)
        assertEquals("Keranjang sudah berisi 50 jenis barang, batas untuk satu pesanan.", full.message(resources))
        assertTrue(full.offersCart)

        assertEquals("Varian ini sedang tidak tersedia.", ProductDetailEvent.VariantUnavailable.message(resources))
        assertFalse(ProductDetailEvent.VariantUnavailable.offersCart)
        assertEquals(
            "Barang belum dapat ditambahkan ke keranjang. Silakan coba lagi.",
            ProductDetailEvent.AddFailed.message(resources),
        )
        assertFalse(ProductDetailEvent.AddFailed.offersCart)
    }
}
