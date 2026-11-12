package com.boothpos.customer.feature.account.presentation

import androidx.compose.runtime.Composable
import androidx.compose.runtime.CompositionLocalProvider
import androidx.compose.ui.platform.LocalDensity
import androidx.compose.ui.test.assertIsDisplayed
import androidx.compose.ui.test.hasClickAction
import androidx.compose.ui.test.hasText
import androidx.compose.ui.test.junit4.createComposeRule
import androidx.compose.ui.test.onNodeWithText
import androidx.compose.ui.test.performScrollTo
import androidx.compose.ui.unit.Density
import androidx.test.ext.junit.runners.AndroidJUnit4
import com.boothpos.customer.core.ui.theme.BoothTheme
import org.junit.Rule
import org.junit.Test
import org.junit.runner.RunWith
import org.robolectric.annotation.Config

/** Ponsel 320 dp dengan huruf sistem 200 %: aksi Akun dan "Simpan" profil tetap dapat dicapai. */
@RunWith(AndroidJUnit4::class)
@Config(qualifiers = "w320dp-h640dp")
class AccountLargeFontLayoutTest {

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

    private fun account(state: AccountUiState) = @Composable {
        AccountScreen(
            state = state,
            onSignIn = {},
            onRegister = {},
            onEditProfile = {},
            onManageAddresses = {},
            onRetryRefresh = {},
            onSignOutClick = {},
            onConfirmSignOut = {},
            onDismissSignOut = {},
        )
    }

    @Test
    fun `ajakan masuk menampilkan Masuk dan Daftar`() {
        large(darkTheme = false, account(AccountUiState.SignedOut(sessionExpired = true)))

        rule.onNode(hasText("Masuk") and hasClickAction()).performScrollTo().assertIsDisplayed()
        rule.onNode(hasText("Daftar") and hasClickAction()).performScrollTo().assertIsDisplayed()
    }

    @Test
    fun `profil dapat digulir sampai Keluar dalam tema gelap`() {
        large(
            darkTheme = true,
            account(
                AccountUiState.Profile(
                    name = "Pelanggan Uji Dengan Nama Panjang",
                    email = "pelanggan.uji@example.test",
                    phone = "0800-0000-0000",
                ),
            ),
        )

        rule.onNodeWithText("Ubah profil").performScrollTo().assertIsDisplayed()
        rule.onNodeWithText("Alamat pengiriman").performScrollTo().assertIsDisplayed()
        rule.onNodeWithText("Keluar").performScrollTo().assertIsDisplayed()
    }

    @Test
    fun `ubah profil dapat digulir sampai Simpan`() {
        large(darkTheme = false) {
            EditProfileScreen(
                state = EditProfileUiState.Form(
                    name = "Pelanggan Uji",
                    phone = "0800-0000-0000",
                    email = "pelanggan.uji@example.test",
                ),
                onNameChange = {},
                onPhoneChange = {},
                onSubmit = {},
            )
        }

        rule.onNodeWithText("Simpan").performScrollTo().assertIsDisplayed()
    }
}
