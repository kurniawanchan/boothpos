package com.boothpos.customer.feature.auth.presentation

import androidx.compose.runtime.Composable
import androidx.compose.runtime.CompositionLocalProvider
import androidx.compose.ui.platform.LocalDensity
import androidx.compose.ui.test.assertIsDisplayed
import androidx.compose.ui.test.hasClickAction
import androidx.compose.ui.test.hasSetTextAction
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

/**
 * Ponsel paling sempit yang didukung (320 dp) dengan ukuran huruf sistem 200 %: aksi utama setiap
 * layar auth harus tetap dapat dicapai (digulir bila perlu) dan tidak terpotong, terang maupun gelap.
 * Skala huruf linear 2.0 lebih berat daripada skala non-linear Android 14, jadi ini batas atasnya.
 */
@RunWith(AndroidJUnit4::class)
@Config(qualifiers = "w320dp-h640dp")
class AuthLargeFontLayoutTest {

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
    fun `pilihan masuk daftar dan lihat toko tetap dapat dicapai`() {
        large(darkTheme = false) { AuthChoiceScreen(onSignIn = {}, onRegister = {}, onContinueAsGuest = {}) }

        rule.onNode(hasText("Masuk") and hasClickAction()).performScrollTo().assertIsDisplayed()
        rule.onNode(hasText("Daftar") and hasClickAction()).performScrollTo().assertIsDisplayed()
        rule.onNodeWithText("Lihat toko tanpa akun").performScrollTo().assertIsDisplayed()
    }

    @Test
    fun `formulir masuk menampilkan kolom dan tombol Masuk dalam tema gelap`() {
        large(darkTheme = true) {
            SignInScreen(state = SignInUiState(), onEmailChange = {}, onPasswordChange = {}, onSubmit = {}, onRegister = {})
        }

        rule.onNode(hasSetTextAction() and hasText("E-mail")).performScrollTo().assertIsDisplayed()
        rule.onNode(hasText("Masuk") and hasClickAction()).performScrollTo().assertIsDisplayed()
    }

    @Test
    fun `formulir daftar menampilkan tombol Daftar setelah digulir`() {
        large(darkTheme = false) {
            RegisterScreen(
                state = RegisterUiState(),
                onNameChange = {},
                onEmailChange = {},
                onPhoneChange = {},
                onPasswordChange = {},
                onSubmit = {},
                onSignIn = {},
            )
        }

        rule.onNode(hasSetTextAction() and hasText("Nama")).performScrollTo().assertIsDisplayed()
        rule.onNode(hasText("Daftar") and hasClickAction()).performScrollTo().assertIsDisplayed()
    }
}
