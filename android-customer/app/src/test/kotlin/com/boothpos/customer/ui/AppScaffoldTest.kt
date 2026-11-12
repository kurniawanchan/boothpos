package com.boothpos.customer.ui

import androidx.compose.material3.SnackbarHostState
import androidx.compose.material3.Text
import androidx.compose.runtime.CompositionLocalProvider
import androidx.compose.ui.platform.LocalDensity
import androidx.compose.ui.semantics.SemanticsProperties
import androidx.compose.ui.test.SemanticsMatcher
import androidx.compose.ui.test.assert
import androidx.compose.ui.test.assertHeightIsAtLeast
import androidx.compose.ui.test.assertIsDisplayed
import androidx.compose.ui.test.assertIsSelected
import androidx.compose.ui.test.assertTouchHeightIsEqualTo
import androidx.compose.ui.test.assertTouchWidthIsEqualTo
import androidx.compose.ui.test.junit4.createComposeRule
import androidx.compose.ui.test.onNodeWithContentDescription
import androidx.compose.ui.test.onNodeWithText
import androidx.compose.ui.test.performClick
import androidx.compose.ui.unit.Density
import androidx.compose.ui.unit.dp
import androidx.test.ext.junit.runners.AndroidJUnit4
import com.boothpos.customer.core.ui.theme.BoothTheme
import com.boothpos.customer.navigation.AccountChrome
import com.boothpos.customer.navigation.OrdersChrome
import com.boothpos.customer.navigation.ScreenChrome
import com.boothpos.customer.navigation.ShopChrome
import com.boothpos.customer.navigation.TopLevelDestination
import org.junit.Assert.assertEquals
import org.junit.Rule
import org.junit.Test
import org.junit.runner.RunWith
import org.robolectric.annotation.Config

@RunWith(AndroidJUnit4::class)
@Config(qualifiers = "w360dp-h640dp")
class AppScaffoldTest {

    @get:Rule
    val rule = createComposeRule()

    private val selectedTabs = mutableListOf<TopLevelDestination>()
    private var accountClicks = 0
    private var backClicks = 0
    private var retries = 0

    // Catatan: IconButton Material 3 digambar 40dp; yang dijamin minimal 48dp adalah AREA SENTUHnya,
    // jadi tombol ikon diuji dengan assertTouch*, bukan dengan tinggi node.
    private fun show(
        state: AppUiState,
        chrome: ScreenChrome,
        canNavigateBack: Boolean = false,
        darkTheme: Boolean = false,
        fontScale: Float? = null,
    ) {
        rule.setContent {
            val density = LocalDensity.current
            CompositionLocalProvider(
                LocalDensity provides Density(density.density, fontScale ?: density.fontScale),
            ) {
                BoothTheme(darkTheme = darkTheme) {
                    AppScaffold(
                        state = state,
                        chrome = chrome,
                        canNavigateBack = canNavigateBack,
                        snackbarHostState = SnackbarHostState(),
                        onTopLevelSelected = { selectedTabs += it },
                        onAccountClick = { accountClicks++ },
                        onBackClick = { backClicks++ },
                        onRetryStore = { retries++ },
                    ) {
                        Text("konten-layar")
                    }
                }
            }
        }
    }

    @Test
    fun `navigasi bawah berisi Belanja Keranjang Pesanan dan Akun ada di bilah atas`() {
        show(AppUiState(storeStatus = StoreStatus.Open, storeName = "Toko Uji"), ShopChrome)

        // Judul bilah atas adalah judul halaman bagi TalkBack.
        rule.onNodeWithText("Toko Uji")
            .assertIsDisplayed()
            .assert(SemanticsMatcher.keyIsDefined(SemanticsProperties.Heading))
        rule.onNodeWithText("Belanja").assertIsSelected()
        rule.onNodeWithText("konten-layar").assertIsDisplayed()
        rule.onNodeWithText("Keranjang").assertHeightIsAtLeast(48.dp).performClick()
        rule.onNodeWithText("Pesanan").performClick()
        rule.onNodeWithContentDescription("Akun").assertTouchHeightIsEqualTo(48.dp).assertTouchWidthIsEqualTo(48.dp).performClick()

        assertEquals(listOf(TopLevelDestination.Cart, TopLevelDestination.Orders), selectedTabs)
        assertEquals(1, accountClicks)
    }

    @Test
    fun `lencana keranjang menyebut jumlah barang`() {
        show(AppUiState(storeStatus = StoreStatus.Open, cartItemCount = 3), ShopChrome)

        rule.onNodeWithContentDescription("3 barang di keranjang", useUnmergedTree = true).assertIsDisplayed()
    }

    @Test
    fun `toko tutup menutupi layar belanja dan coba lagi memanggil callback`() {
        show(AppUiState(storeStatus = StoreStatus.Closed), ShopChrome)

        rule.onNodeWithText("Toko sedang tidak tersedia").assertIsDisplayed()
        rule.onNodeWithText("konten-layar").assertDoesNotExist()
        rule.onNodeWithText("Coba lagi").performClick()
        // Navigasi tetap bisa dipakai untuk membuka Pesanan.
        rule.onNodeWithText("Pesanan").performClick()

        assertEquals(1, retries)
        assertEquals(listOf(TopLevelDestination.Orders), selectedTabs)
    }

    @Test
    fun `toko tutup tidak menutupi Pesanan`() {
        show(AppUiState(storeStatus = StoreStatus.Closed), OrdersChrome)

        rule.onNodeWithText("konten-layar").assertIsDisplayed()
        rule.onNodeWithText("Toko sedang tidak tersedia").assertDoesNotExist()
    }

    @Test
    fun `layar non-tab menampilkan tombol kembali tanpa navigasi bawah`() {
        show(AppUiState(storeStatus = StoreStatus.Open), AccountChrome, canNavigateBack = true)

        rule.onNodeWithText("Akun").assertIsDisplayed()
        rule.onNodeWithText("Keranjang").assertDoesNotExist()
        rule.onNodeWithContentDescription("Kembali").assertTouchHeightIsEqualTo(48.dp).assertTouchWidthIsEqualTo(48.dp).performClick()

        assertEquals(1, backClicks)
    }

    @Test
    @Config(qualifiers = "w320dp-h640dp")
    fun `teks dua kali lebih besar di layar 320dp bertema gelap tetap menampilkan navigasi dan aksi akun`() {
        show(
            AppUiState(storeStatus = StoreStatus.Open, storeName = "Toko Uji", cartItemCount = 3),
            ShopChrome,
            darkTheme = true,
            fontScale = 2f,
        )

        rule.onNodeWithText("konten-layar").assertIsDisplayed()
        rule.onNodeWithText("Belanja").assertIsDisplayed()
        rule.onNodeWithText("Keranjang").assertIsDisplayed()
        rule.onNodeWithText("Pesanan").assertIsDisplayed()
        rule.onNodeWithContentDescription("Akun").assertIsDisplayed().assertTouchHeightIsEqualTo(48.dp)
    }
}
