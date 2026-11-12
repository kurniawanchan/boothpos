package com.boothpos.customer.feature.cart.presentation

import androidx.compose.material3.SnackbarDuration
import androidx.compose.material3.SnackbarHostState
import androidx.compose.material3.SnackbarResult
import androidx.compose.runtime.LaunchedEffect
import androidx.compose.runtime.getValue
import androidx.compose.runtime.mutableStateOf
import androidx.compose.runtime.setValue
import androidx.compose.ui.test.assert
import androidx.compose.ui.test.assertContentDescriptionEquals
import androidx.compose.ui.test.assertHasClickAction
import androidx.compose.ui.test.assertHeightIsAtLeast
import androidx.compose.ui.test.assertIsDisplayed
import androidx.compose.ui.test.assertIsEnabled
import androidx.compose.ui.test.assertIsNotEnabled
import androidx.compose.ui.test.assertWidthIsAtLeast
import androidx.compose.ui.test.getUnclippedBoundsInRoot
import androidx.compose.ui.test.hasText
import androidx.compose.ui.test.junit4.createComposeRule
import androidx.compose.ui.test.onNodeWithContentDescription
import androidx.compose.ui.test.onNodeWithTag
import androidx.compose.ui.test.onNodeWithText
import androidx.compose.ui.test.performClick
import androidx.compose.ui.unit.dp
import androidx.test.ext.junit.runners.AndroidJUnit4
import com.boothpos.customer.core.data.domain.model.CartLine
import com.boothpos.customer.core.data.domain.model.subtotal
import com.boothpos.customer.core.ui.theme.BoothTheme
import com.boothpos.customer.feature.cart.domain.issue
import java.math.BigDecimal
import org.junit.Assert.assertEquals
import org.junit.Assert.assertTrue
import org.junit.Rule
import org.junit.Test
import org.junit.runner.RunWith
import org.robolectric.annotation.Config

/** Keranjang stateless di ponsel kecil (360 x 640 dp), tanpa Hilt, penyimpanan, atau gambar jarak jauh. */
@RunWith(AndroidJUnit4::class)
@Config(qualifiers = "w360dp-h640dp")
class CartScreenTest {

    @get:Rule
    val rule = createComposeRule()

    private var state: CartUiState by mutableStateOf(CartUiState.Loading)
    private val snackbarHostState = SnackbarHostState()
    private var snackbarMessage: String? by mutableStateOf(null)
    private var snackbarResult: SnackbarResult? = null
    private val actions = mutableListOf<String>()

    private fun line(variantId: Long, name: String, price: String, quantity: Int, maxQuantity: Int) = CartLine(
        variantId = variantId,
        productSlug = "produk-uji-$variantId",
        productName = name,
        variantName = "Varian $variantId",
        imageUrl = null,
        unitPrice = BigDecimal(price),
        quantity = quantity,
        maxQuantity = maxQuantity,
    )

    private val keychain = line(31, "Gantungan Kunci Uji", "15000.00", quantity = 2, maxQuantity = 3)
    private val sticker = line(32, "Stiker Uji", "25000.00", quantity = 1, maxQuantity = 1)

    private fun content(vararg lines: CartLine) = CartUiState.Content(
        lines = lines.map { CartLineUi(it, it.issue()) },
        subtotal = lines.toList().subtotal(),
        itemCount = lines.sumOf { it.quantity },
    )

    private fun show(initial: CartUiState) {
        state = initial
        rule.setContent {
            BoothTheme {
                CartScreen(
                    state = state,
                    snackbarHostState = snackbarHostState,
                    onStartShopping = { actions += "belanja" },
                    onIncrease = { actions += "tambah:$it" },
                    onDecrease = { actions += "kurang:$it" },
                    onAdjustToLimit = { actions += "sesuaikan:$it" },
                    onRemove = { actions += "hapus:$it" },
                    onOpenProduct = { actions += "produk:$it" },
                    onCheckout = { actions += "checkout" },
                )
                snackbarMessage?.let { message ->
                    LaunchedEffect(message) {
                        snackbarResult = snackbarHostState.showSnackbar(
                            message = message,
                            actionLabel = "Urungkan",
                            duration = SnackbarDuration.Long,
                        )
                    }
                }
            }
        }
    }

    private val total get() = rule.onNodeWithTag(CartTestTags.TOTAL)
    private val checkoutButton get() = rule.onNodeWithText("Lanjut ke checkout")

    @Test
    fun `keranjang yang belum terbaca tampil sebagai memuat`() {
        show(CartUiState.Loading)

        rule.onNodeWithText("Memuat…").assertIsDisplayed()
        checkoutButton.assertDoesNotExist()
    }

    @Test
    fun `keranjang kosong menampilkan jalan kembali ke toko sebagai satu aksi utama`() {
        show(CartUiState.Empty)

        rule.onNodeWithText("Keranjang Anda kosong").assertIsDisplayed()
        total.assertDoesNotExist()
        checkoutButton.assertDoesNotExist()
        rule.onNodeWithText("Mulai belanja").assertHasClickAction().assertHeightIsAtLeast(48.dp).performClick()

        assertEquals(listOf("belanja"), actions)
    }

    @Test
    fun `total sementara selalu terlihat bersama keterangan bahwa harga dikonfirmasi saat checkout`() {
        show(content(keychain, sticker))

        // 2 x Rp15.000 + 1 x Rp25.000
        total.assertIsDisplayed()
            .assert(hasText("Total sementara"))
            .assert(hasText("3 barang"))
            .assert(hasText("Rp55.000"))
        rule.onNodeWithText("Harga dan stok akan dikonfirmasi saat checkout.").assertIsDisplayed()
        checkoutButton.assertIsDisplayed().assertIsEnabled().assertHeightIsAtLeast(48.dp).performClick()

        assertEquals(listOf("checkout"), actions)
        // Total berada di atas tombol utama, tidak tertutup olehnya.
        assertTrue(total.getUnclippedBoundsInRoot().bottom <= checkoutButton.getUnclippedBoundsInRoot().top)
    }

    @Test
    fun `baris menampilkan nama varian harga satuan dan jumlah harga`() {
        // Dua baris, supaya jumlah harga baris (Rp30.000) berbeda dari total keranjang (Rp55.000).
        show(content(keychain, sticker))

        rule.onNodeWithText("Gantungan Kunci Uji").assertIsDisplayed()
        rule.onNodeWithText("Varian 31").assertIsDisplayed()
        rule.onNodeWithText("Rp15.000 per barang").assertIsDisplayed()
        rule.onNodeWithText("Rp30.000").assertIsDisplayed()
        rule.onNodeWithTag(CartTestTags.quantity(31)).assertContentDescriptionEquals("Jumlah: 2")
        rule.onNodeWithContentDescription("Lihat produk Gantungan Kunci Uji").assertHasClickAction().performClick()

        assertEquals(listOf("produk:produk-uji-31"), actions)
    }

    @Test
    fun `tombol jumlah berlabel berukuran 48dp dan nonaktif di batasnya`() {
        show(content(keychain, sticker))

        val increase = rule.onNodeWithContentDescription("Tambah jumlah Gantungan Kunci Uji")
        val decrease = rule.onNodeWithContentDescription("Kurangi jumlah Gantungan Kunci Uji")
        increase.assertIsEnabled().assertHeightIsAtLeast(48.dp).assertWidthIsAtLeast(48.dp).performClick()
        decrease.assertIsEnabled().assertHeightIsAtLeast(48.dp).assertWidthIsAtLeast(48.dp).performClick()
        // Stiker: jumlah 1 dan batas server 1, jadi kedua tombol nonaktif (bukan disembunyikan).
        rule.onNodeWithContentDescription("Tambah jumlah Stiker Uji").assertIsNotEnabled()
        rule.onNodeWithContentDescription("Kurangi jumlah Stiker Uji").assertIsNotEnabled()

        assertEquals(listOf("tambah:31", "kurang:31"), actions)
    }

    @Test
    fun `tombol hapus berlabel nama barang dan memanggil penghapusan baris itu`() {
        show(content(keychain, sticker))

        rule.onNodeWithContentDescription("Hapus Stiker Uji dari keranjang")
            .assertHasClickAction()
            .assertHeightIsAtLeast(48.dp)
            .assertWidthIsAtLeast(48.dp)
            .performClick()

        assertEquals(listOf("hapus:32"), actions)
    }

    @Test
    fun `urungkan tetap dapat ditekan setelah baris terakhir dihapus dan tidak menutupi total`() {
        show(content(keychain))

        snackbarMessage = "Stiker Uji dihapus dari keranjang."
        rule.waitForIdle()
        val message = rule.onNodeWithText("Stiker Uji dihapus dari keranjang.").assertIsDisplayed()
        assertTrue(message.getUnclippedBoundsInRoot().bottom <= total.getUnclippedBoundsInRoot().top)

        // Baris terakhir terhapus: layar menjadi kosong, tetapi snackbar-nya masih ada.
        state = CartUiState.Empty
        rule.waitForIdle()
        rule.onNodeWithText("Keranjang Anda kosong").assertIsDisplayed()
        rule.onNodeWithText("Urungkan").assertHasClickAction().performClick()
        rule.waitForIdle()

        assertEquals(SnackbarResult.ActionPerformed, snackbarResult)
    }

    @Test
    fun `baris bermasalah ditandai dengan teks dan checkout dinonaktifkan sampai diperbaiki`() {
        val over = line(31, "Gantungan Kunci Uji", "15000.00", quantity = 3, maxQuantity = 1)
        val gone = line(40, "Pin Uji", "9000.00", quantity = 1, maxQuantity = 0)
        show(content(over, gone))

        rule.onNodeWithText("Jumlah melebihi yang tersedia (maksimal 1).").assertIsDisplayed()
        rule.onNodeWithText("Tidak lagi tersedia. Hapus barang ini untuk melanjutkan.").assertIsDisplayed()
        rule.onNodeWithText("Perbaiki barang yang ditandai untuk melanjutkan.").assertIsDisplayed()
        checkoutButton.assertIsNotEnabled()
        rule.onNodeWithContentDescription("Tambah jumlah Gantungan Kunci Uji").assertIsNotEnabled()
        rule.onNodeWithText("Ubah jumlah menjadi 1").assertHasClickAction().assertHeightIsAtLeast(48.dp).performClick()

        assertEquals(listOf("sesuaikan:31"), actions)
    }

    @Test
    fun `teks snackbar menyebut nama barang yang dihapus`() {
        val resources = androidx.test.core.app.ApplicationProvider
            .getApplicationContext<android.content.Context>().resources

        assertEquals(
            "Stiker Uji dihapus dari keranjang.",
            CartEvent.LineRemoved("Stiker Uji", canUndo = true).message(resources),
        )
        assertEquals("Barang tidak dapat dikembalikan ke keranjang.", CartEvent.UndoFailed.message(resources))
        assertEquals("Keranjang belum dapat diubah. Silakan coba lagi.", CartEvent.UpdateFailed.message(resources))
    }
}
