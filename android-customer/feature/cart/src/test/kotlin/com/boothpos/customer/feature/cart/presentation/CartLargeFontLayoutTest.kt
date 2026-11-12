package com.boothpos.customer.feature.cart.presentation

import androidx.compose.material3.SnackbarHostState
import androidx.compose.runtime.CompositionLocalProvider
import androidx.compose.ui.platform.LocalDensity
import androidx.compose.ui.test.assertIsDisplayed
import androidx.compose.ui.test.junit4.createComposeRule
import androidx.compose.ui.test.onNodeWithTag
import androidx.compose.ui.test.onNodeWithText
import androidx.compose.ui.unit.Density
import androidx.test.ext.junit.runners.AndroidJUnit4
import com.boothpos.customer.core.data.domain.model.CartLine
import com.boothpos.customer.core.data.domain.model.subtotal
import com.boothpos.customer.core.ui.theme.BoothTheme
import com.boothpos.customer.feature.cart.domain.issue
import java.math.BigDecimal
import org.junit.Rule
import org.junit.Test
import org.junit.runner.RunWith
import org.robolectric.annotation.Config

/** Ponsel 320 dp dengan huruf sistem 200 %: total dan "Lanjut ke checkout" tetap terlihat tanpa menggulir. */
@RunWith(AndroidJUnit4::class)
@Config(qualifiers = "w320dp-h640dp")
class CartLargeFontLayoutTest {

    @get:Rule
    val rule = createComposeRule()

    private fun line(variantId: Long) = CartLine(
        variantId = variantId,
        productSlug = "produk-uji-$variantId",
        productName = "Gantungan Kunci Uji Dengan Nama Yang Cukup Panjang $variantId",
        variantName = "Varian $variantId",
        imageUrl = null,
        unitPrice = BigDecimal("15000.00"),
        quantity = 1,
        maxQuantity = 3,
    )

    private fun show(darkTheme: Boolean) {
        val lines = (31L..35L).map(::line)
        val state = CartUiState.Content(
            lines = lines.map { CartLineUi(it, it.issue()) },
            subtotal = lines.subtotal(),
            itemCount = lines.sumOf { it.quantity },
        )
        rule.setContent {
            val density = LocalDensity.current
            CompositionLocalProvider(LocalDensity provides Density(density.density, fontScale = 2f)) {
                BoothTheme(darkTheme = darkTheme) {
                    CartScreen(
                        state = state,
                        snackbarHostState = SnackbarHostState(),
                        onStartShopping = {},
                        onIncrease = {},
                        onDecrease = {},
                        onAdjustToLimit = {},
                        onRemove = {},
                        onOpenProduct = {},
                        onCheckout = {},
                    )
                }
            }
        }
    }

    @Test
    fun `total dan aksi utama tetap terlihat dengan huruf besar`() {
        show(darkTheme = false)

        rule.onNodeWithTag(CartTestTags.TOTAL).assertIsDisplayed()
        rule.onNodeWithText("Lanjut ke checkout").assertIsDisplayed()
    }

    @Test
    fun `tema gelap dengan huruf besar menampilkan aksi utama yang sama`() {
        show(darkTheme = true)

        rule.onNodeWithText("Lanjut ke checkout").assertIsDisplayed()
    }
}
