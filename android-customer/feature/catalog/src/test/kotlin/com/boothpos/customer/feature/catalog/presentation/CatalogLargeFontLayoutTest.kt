package com.boothpos.customer.feature.catalog.presentation

import androidx.compose.material3.SnackbarHostState
import androidx.compose.runtime.Composable
import androidx.compose.runtime.CompositionLocalProvider
import androidx.compose.ui.platform.LocalDensity
import androidx.compose.ui.test.assertIsDisplayed
import androidx.compose.ui.test.hasClickAction
import androidx.compose.ui.test.hasSetTextAction
import androidx.compose.ui.test.hasText
import androidx.compose.ui.test.junit4.createComposeRule
import androidx.compose.ui.test.onNodeWithContentDescription
import androidx.compose.ui.test.onNodeWithText
import androidx.compose.ui.test.performScrollTo
import androidx.compose.ui.unit.Density
import androidx.test.ext.junit.runners.AndroidJUnit4
import com.boothpos.customer.core.ui.theme.BoothTheme
import com.boothpos.customer.feature.catalog.presentation.CatalogTestData.detail
import com.boothpos.customer.feature.catalog.presentation.CatalogTestData.products
import org.junit.Rule
import org.junit.Test
import org.junit.runner.RunWith
import org.robolectric.annotation.Config

/**
 * Ponsel 320 dp dengan huruf sistem 200 %: kolom cari, kartu produk, dan "Tambah ke keranjang"
 * (yang disematkan di bawah, tanpa perlu menggulir) tetap tampil, terang maupun gelap.
 */
@RunWith(AndroidJUnit4::class)
@Config(qualifiers = "w320dp-h640dp")
class CatalogLargeFontLayoutTest {

    @get:Rule
    val rule = createComposeRule()

    private fun large(darkTheme: Boolean, content: @Composable () -> Unit) {
        rule.setContent {
            val density = LocalDensity.current
            CompositionLocalProvider(LocalDensity provides Density(density.density, fontScale = 2f)) {
                BoothTheme(darkTheme = darkTheme) { content() }
            }
        }
    }

    @Test
    fun `daftar produk menampilkan kolom cari dan kartu pertama yang dapat diketuk`() {
        large(darkTheme = true) {
            ShopScreen(
                state = ShopUiState(content = ShopContent.Products(items = products(1..5))),
                onSearchChange = {},
                onSearchSubmit = {},
                onCategorySelected = {},
                onSortSelected = {},
                onRefresh = {},
                onLoadMore = {},
                onRetryLoadMore = {},
                onClearFilters = {},
                onProductClick = {},
            )
        }

        rule.onNode(hasSetTextAction() and hasText("Cari produk")).assertIsDisplayed()
        rule.onNode(hasClickAction() and hasText("Produk Uji 1", substring = true)).assertIsDisplayed()
    }

    @Test
    fun `detail produk menjaga Tambah ke keranjang tetap terlihat tanpa menggulir`() {
        large(darkTheme = false) {
            ProductDetailScreen(
                state = ProductDetailUiState.Content(product = detail(), selectedVariantId = 31, quantity = 1),
                snackbarHostState = SnackbarHostState(),
                onRetry = {},
                onVariantSelected = {},
                onDecreaseQuantity = {},
                onIncreaseQuantity = {},
                onAddToCart = {},
                onBackToShop = {},
            )
        }

        rule.onNodeWithText("Tambah ke keranjang").assertIsDisplayed()
        // Pemilih jumlah ada di konten yang digulir, di atas tombol yang disematkan.
        rule.onNodeWithContentDescription("Tambah jumlah").performScrollTo().assertIsDisplayed()
        rule.onNodeWithText("Tambah ke keranjang").assertIsDisplayed()
    }
}
