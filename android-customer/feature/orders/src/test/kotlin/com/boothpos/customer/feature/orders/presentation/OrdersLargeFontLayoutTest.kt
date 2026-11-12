package com.boothpos.customer.feature.orders.presentation

import androidx.compose.material3.SnackbarHostState
import androidx.compose.runtime.Composable
import androidx.compose.runtime.CompositionLocalProvider
import androidx.compose.ui.platform.LocalDensity
import androidx.compose.ui.test.assertIsDisplayed
import androidx.compose.ui.test.hasClickAction
import androidx.compose.ui.test.hasText
import androidx.compose.ui.test.junit4.createComposeRule
import androidx.compose.ui.test.onAllNodesWithText
import androidx.compose.ui.test.onFirst
import androidx.compose.ui.test.onNodeWithText
import androidx.compose.ui.test.performScrollTo
import androidx.compose.ui.unit.Density
import androidx.test.ext.junit.runners.AndroidJUnit4
import com.boothpos.customer.core.ui.theme.BoothTheme
import com.boothpos.customer.feature.orders.presentation.OrdersTestData.SAFE_TRACKING_URL
import com.boothpos.customer.feature.orders.presentation.OrdersTestData.number
import com.boothpos.customer.feature.orders.presentation.OrdersTestData.shipment
import com.boothpos.customer.feature.orders.presentation.OrdersTestData.shippedDetail
import com.boothpos.customer.feature.orders.presentation.OrdersTestData.summaries
import org.junit.Rule
import org.junit.Test
import org.junit.runner.RunWith
import org.robolectric.annotation.Config

/**
 * Ponsel 320 dp dengan huruf sistem 200 %: baris pesanan tetap dapat diketuk, dan detail tetap
 * mendahulukan status lalu dapat digulir sampai "Lacak paket" dan "Perbarui status".
 */
@RunWith(AndroidJUnit4::class)
@Config(qualifiers = "w320dp-h640dp")
class OrdersLargeFontLayoutTest {

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
    fun `daftar pesanan menampilkan baris pertama yang dapat diketuk`() {
        large(darkTheme = true) {
            OrdersScreen(
                state = OrdersUiState.Orders(items = summaries(1..5)),
                onSignIn = {},
                onRefresh = {},
                onLoadMore = {},
                onRetryLoadMore = {},
                onOrderClick = {},
                dateFormatter = OrdersTestData.dates,
            )
        }

        rule.onNode(hasClickAction() and hasText("Pesanan ${number(1)}", substring = true)).assertIsDisplayed()
    }

    @Test
    fun `ajakan masuk di Pesanan tetap menampilkan tombol Masuk`() {
        large(darkTheme = false) {
            OrdersScreen(
                state = OrdersUiState.SignedOut(sessionExpired = true),
                onSignIn = {},
                onRefresh = {},
                onLoadMore = {},
                onRetryLoadMore = {},
                onOrderClick = {},
                dateFormatter = OrdersTestData.dates,
            )
        }

        rule.onNodeWithText("Masuk").performScrollTo().assertIsDisplayed()
    }

    @Test
    fun `detail pesanan menampilkan status lebih dulu dan aksinya dapat dicapai`() {
        val order = shippedDetail(shipment = shipment(trackingUrl = SAFE_TRACKING_URL))
        large(darkTheme = true) {
            OrderDetailScreen(
                state = OrderDetailUiState.Content(order = order, updatedByStore = true),
                snackbarHostState = SnackbarHostState(),
                onRefresh = {},
                onSignIn = {},
                onBackToOrders = {},
                onCopyTrackingNumber = {},
                onTrackParcel = {},
                onConfirmHandoff = {},
                onDismissHandoff = {},
                dateFormatter = OrdersTestData.dates,
            )
        }

        rule.onAllNodesWithText("Dikirim").onFirst().assertIsDisplayed()
        rule.onNodeWithText("Lacak paket").performScrollTo().assertIsDisplayed()
        rule.onNodeWithText("Perbarui status").performScrollTo().assertIsDisplayed()
    }
}
