package com.boothpos.customer.feature.checkout.presentation

import androidx.compose.runtime.Composable
import androidx.compose.runtime.CompositionLocalProvider
import androidx.compose.ui.platform.LocalDensity
import androidx.compose.ui.test.assertIsDisplayed
import androidx.compose.ui.test.hasSetTextAction
import androidx.compose.ui.test.hasText
import androidx.compose.ui.test.junit4.createComposeRule
import androidx.compose.ui.test.onNodeWithTag
import androidx.compose.ui.test.onNodeWithText
import androidx.compose.ui.test.performScrollTo
import androidx.compose.ui.unit.Density
import androidx.test.ext.junit.runners.AndroidJUnit4
import com.boothpos.customer.core.data.domain.model.FulfilmentMethod
import com.boothpos.customer.core.ui.theme.BoothTheme
import com.boothpos.customer.feature.checkout.domain.AddressDraft
import com.boothpos.customer.feature.checkout.presentation.CheckoutTestData.address
import com.boothpos.customer.feature.checkout.presentation.address.AddressFormScreen
import com.boothpos.customer.feature.checkout.presentation.address.AddressFormUiState
import org.junit.Rule
import org.junit.Test
import org.junit.runner.RunWith
import org.robolectric.annotation.Config

/**
 * Ponsel 320 dp dengan huruf sistem 200 %: setiap langkah checkout tetap menampilkan aksi
 * utamanya (disematkan di bawah) dan total di ringkasan, terang maupun gelap.
 */
@RunWith(AndroidJUnit4::class)
@Config(qualifiers = "w320dp-h640dp")
class CheckoutLargeFontLayoutTest {

    @get:Rule
    val rule = createComposeRule()

    private val noActions = CheckoutActions(
        onRetryLoad = {},
        onSignIn = {},
        onBackToCart = {},
        onViewOrder = {},
        onSelectMethod = {},
        onContinueFromMethod = {},
        onContactNameChange = {},
        onContactPhoneChange = {},
        onNotesChange = {},
        onSelectAddress = {},
        onAddAddress = {},
        onEditAddress = {},
        onRetryAddresses = {},
        onContinueToReview = {},
        onRetryQuote = {},
        onPlaceOrder = {},
        onDismissPriceChange = {},
        onFixCart = {},
        onRetryUnknownStatus = {},
    )

    private fun form(step: CheckoutStep, method: FulfilmentMethod, review: ReviewState = ReviewState.Idle) =
        CheckoutUiState.Form(
            step = step,
            methods = listOf(FulfilmentMethod.Pickup, FulfilmentMethod.Courier),
            method = method,
            pickupInstructions = "Ambil di meja depan, tunjukkan nomor pesanan.",
            courierInstructions = "Dikirim setiap hari kerja.",
            contactName = "Pelanggan Uji",
            contactPhone = "0800-0000-0000",
            notes = "",
            addresses = AddressesState.Loaded(listOf(address(id = 5), address(id = 6, label = "Kantor", isDefault = false))),
            selectedAddressId = 5,
            review = review,
        )

    private fun large(darkTheme: Boolean, content: @Composable () -> Unit) {
        rule.setContent {
            val density = LocalDensity.current
            CompositionLocalProvider(LocalDensity provides Density(density.density, fontScale = 2f)) {
                BoothTheme(darkTheme = darkTheme) { content() }
            }
        }
    }

    @Test
    fun `langkah cara pengambilan menampilkan Lanjut tanpa menggulir`() {
        large(darkTheme = false) { CheckoutScreen(state = form(CheckoutStep.Method, FulfilmentMethod.Pickup), actions = noActions) }

        rule.onNodeWithText("Lanjut").assertIsDisplayed()
    }

    @Test
    fun `langkah alamat dan kontak kurir menampilkan Lanjut ke ringkasan dalam tema gelap`() {
        large(darkTheme = true) { CheckoutScreen(state = form(CheckoutStep.Details, FulfilmentMethod.Courier), actions = noActions) }

        rule.onNodeWithText("Lanjut ke ringkasan").assertIsDisplayed()
        // Kolom kontak ada di konten yang digulir, di atas tombol yang disematkan.
        rule.onNode(hasSetTextAction() and hasText("Nomor telepon", substring = true)).performScrollTo().assertIsDisplayed()
        rule.onNodeWithText("Lanjut ke ringkasan").assertIsDisplayed()
    }

    @Test
    fun `ringkasan menampilkan total dan Buat pesanan tanpa menggulir`() {
        large(darkTheme = true) {
            CheckoutScreen(
                state = form(
                    CheckoutStep.Review,
                    FulfilmentMethod.Courier,
                    review = ReviewState.Ready(CheckoutTestData.quote(FulfilmentMethod.Courier)),
                ),
                actions = noActions,
            )
        }

        rule.onNodeWithTag(CheckoutTestTags.TOTAL).assertIsDisplayed()
        rule.onNodeWithText("Buat pesanan").assertIsDisplayed()
    }

    @Test
    fun `formulir alamat dapat digulir sampai Simpan alamat`() {
        large(darkTheme = false) {
            AddressFormScreen(
                state = AddressFormUiState.Form(isNew = true, draft = AddressDraft(recipientName = "Pelanggan Uji")),
                onFieldChange = { _, _ -> },
                onDefaultChange = {},
                onSubmit = {},
                onRetry = {},
                onClose = {},
            )
        }

        rule.onNodeWithText("Simpan alamat").performScrollTo().assertIsDisplayed()
    }
}
