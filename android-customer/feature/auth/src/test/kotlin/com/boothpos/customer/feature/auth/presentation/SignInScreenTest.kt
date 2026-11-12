package com.boothpos.customer.feature.auth.presentation

import androidx.compose.runtime.getValue
import androidx.compose.runtime.mutableStateOf
import androidx.compose.runtime.setValue
import androidx.compose.ui.semantics.LiveRegionMode
import androidx.compose.ui.semantics.SemanticsProperties
import androidx.compose.ui.test.SemanticsMatcher
import androidx.compose.ui.test.assert
import androidx.compose.ui.test.assertHasClickAction
import androidx.compose.ui.test.assertHeightIsAtLeast
import androidx.compose.ui.test.assertIsDisplayed
import androidx.compose.ui.test.assertIsEnabled
import androidx.compose.ui.test.assertIsNotEnabled
import androidx.compose.ui.test.hasSetTextAction
import androidx.compose.ui.test.hasText
import androidx.compose.ui.test.junit4.createComposeRule
import androidx.compose.ui.test.onNodeWithContentDescription
import androidx.compose.ui.test.onNodeWithText
import androidx.compose.ui.test.performClick
import androidx.compose.ui.test.performImeAction
import androidx.compose.ui.test.performScrollTo
import androidx.compose.ui.test.performTextInput
import androidx.compose.ui.text.AnnotatedString
import androidx.compose.ui.unit.dp
import androidx.test.ext.junit.runners.AndroidJUnit4
import com.boothpos.customer.core.data.domain.validation.FieldIssue
import com.boothpos.customer.core.network.error.ApiError
import com.boothpos.customer.core.ui.theme.BoothTheme
import org.junit.Assert.assertEquals
import org.junit.Rule
import org.junit.Test
import org.junit.runner.RunWith
import org.robolectric.annotation.Config

/** Layar masuk stateless di ponsel kecil (360 x 640 dp), tanpa Hilt dan tanpa jaringan. */
@RunWith(AndroidJUnit4::class)
@Config(qualifiers = "w360dp-h640dp")
class SignInScreenTest {

    @get:Rule
    val rule = createComposeRule()

    private var state by mutableStateOf(SignInUiState())
    private var submits = 0
    private var registers = 0

    private val emailField = hasSetTextAction() and hasText("E-mail")
    private val passwordField = hasSetTextAction() and hasText("Kata sandi")
    // Teks persisnya hanya dimiliki tombol utama (tautan di bawahnya berteks lebih panjang).
    private val submitButton = hasText("Masuk")

    private fun show(initial: SignInUiState = SignInUiState()) {
        state = initial
        rule.setContent {
            BoothTheme {
                SignInScreen(
                    state = state,
                    onEmailChange = { state = state.copy(email = it) },
                    onPasswordChange = { state = state.copy(password = it) },
                    onSubmit = { submits++ },
                    onRegister = { registers++ },
                )
            }
        }
    }

    @Test
    fun `kolom tombol utama dan tautan daftar tampil dengan labelnya`() {
        show()

        rule.onNode(emailField).assertIsDisplayed()
        rule.onNode(passwordField).assertIsDisplayed()
        rule.onNode(submitButton).assertIsDisplayed().assertIsEnabled().assertHeightIsAtLeast(48.dp)
        rule.onNodeWithText("Belum punya akun? Daftar").assertHasClickAction().assertHeightIsAtLeast(48.dp)
        rule.onNodeWithText("Sesi berakhir, silakan masuk lagi.").assertDoesNotExist()
    }

    @Test
    fun `mengetik dan menekan tombol meneruskan nilai dan satu kali kirim`() {
        show()

        rule.onNode(emailField).performTextInput("pelanggan.uji@example.test")
        rule.onNode(passwordField).performTextInput(FAKE_PASSWORD)
        rule.onNode(submitButton).performClick()

        assertEquals("pelanggan.uji@example.test", state.email)
        assertEquals(FAKE_PASSWORD, state.password)
        assertEquals(1, submits)
    }

    @Test
    fun `tombol selesai di papan ketik pada kata sandi mengirim formulir`() {
        show(SignInUiState(email = "pelanggan.uji@example.test", password = FAKE_PASSWORD))

        rule.onNode(passwordField).performImeAction()

        assertEquals(1, submits)
    }

    @Test
    fun `galat kolom tampil di bawah kolomnya dan diumumkan sebagai galat`() {
        show(
            SignInUiState(
                emailError = FieldErrorUi.Local(FieldIssue.InvalidFormat),
                passwordError = FieldErrorUi.Local(FieldIssue.Required),
            ),
        )

        rule.onNodeWithText("Format e-mail tidak valid. Contoh: nama@email.com", substring = true).assertIsDisplayed()
        rule.onNodeWithText("Kata sandi wajib diisi.", substring = true).assertIsDisplayed()
        rule.onNode(emailField).assert(SemanticsMatcher.keyIsDefined(SemanticsProperties.Error))
        rule.onNode(passwordField).assert(SemanticsMatcher.keyIsDefined(SemanticsProperties.Error))
    }

    @Test
    fun `galat kolom dari server ditampilkan apa adanya`() {
        show(SignInUiState(emailError = FieldErrorUi.Server("E-mail tidak boleh kosong.")))

        rule.onNodeWithText("E-mail tidak boleh kosong.", substring = true).assertIsDisplayed()
    }

    @Test
    fun `kredensial salah tampil sebagai pesan formulir generik yang diumumkan`() {
        show(SignInUiState(formMessage = AuthFormMessage.InvalidCredentials))

        rule.onNodeWithText("E-mail atau kata sandi salah. Periksa lagi, lalu coba masuk kembali.")
            .assertIsDisplayed()
            .assert(SemanticsMatcher.expectValue(SemanticsProperties.LiveRegion, LiveRegionMode.Polite))
    }

    @Test
    fun `batas percobaan menampilkan petunjuk tunggu dalam detik atau menit`() {
        show(SignInUiState(formMessage = AuthFormMessage.RateLimited(42)))
        rule.onNodeWithText("Terlalu banyak percobaan. Coba lagi dalam 42 detik.").assertIsDisplayed()

        state = state.copy(formMessage = AuthFormMessage.RateLimited(61))
        rule.onNodeWithText("Terlalu banyak percobaan. Coba lagi dalam sekitar 2 menit.").assertIsDisplayed()

        state = state.copy(formMessage = AuthFormMessage.RateLimited(null))
        rule.onNodeWithText("Terlalu banyak percobaan. Tunggu sebentar, lalu coba lagi.").assertIsDisplayed()
    }

    @Test
    fun `galat jaringan dan toko tidak tersedia punya pesan sendiri`() {
        show(SignInUiState(formMessage = AuthFormMessage.Other(ApiError.Network)))
        rule.onNodeWithText("Periksa koneksi internet Anda, lalu coba lagi.").assertIsDisplayed()

        state = state.copy(formMessage = AuthFormMessage.StoreUnavailable)
        rule.onNodeWithText("Toko sedang tidak tersedia saat ini. Silakan coba lagi nanti.").assertIsDisplayed()
    }

    @Test
    fun `saat permintaan berjalan tombol utama dan kolom tidak dapat dipakai`() {
        show(SignInUiState(email = "pelanggan.uji@example.test", password = FAKE_PASSWORD, submitting = true))

        rule.onNode(submitButton)
            .assertIsNotEnabled()
            .assert(SemanticsMatcher.expectValue(SemanticsProperties.StateDescription, "Sedang diproses"))
            .performClick()
        // Kolom yang dinonaktifkan tidak lagi punya aksi isi teks, jadi dicari lewat labelnya.
        listOf("E-mail", "Kata sandi").forEach { label ->
            rule.onNodeWithText(label).assertIsNotEnabled().assert(hasSetTextAction().not())
        }
        rule.onNodeWithText("Belum punya akun? Daftar").assertIsNotEnabled()

        assertEquals(0, submits)
    }

    @Test
    fun `tombol mata menampilkan dan menyembunyikan kata sandi`() {
        show(SignInUiState(password = FAKE_PASSWORD))
        val showsSecret = SemanticsMatcher.expectValue(SemanticsProperties.EditableText, AnnotatedString(FAKE_PASSWORD))

        rule.onNode(passwordField).assert(SemanticsMatcher.keyIsDefined(SemanticsProperties.Password))
        rule.onNode(passwordField).assert(showsSecret.not())
        rule.onNodeWithContentDescription("Tampilkan kata sandi").assertHasClickAction().performClick()
        rule.onNode(passwordField).assert(showsSecret)
        rule.onNodeWithContentDescription("Sembunyikan kata sandi").performClick()
        rule.onNode(passwordField).assert(showsSecret.not())
    }

    @Test
    fun `kolom membawa petunjuk isi-otomatis`() {
        show()

        rule.onNode(emailField).assert(SemanticsMatcher.keyIsDefined(SemanticsProperties.ContentType))
        rule.onNode(passwordField).assert(SemanticsMatcher.keyIsDefined(SemanticsProperties.ContentType))
    }

    @Test
    fun `sesi berakhir ditampilkan sebagai pemberitahuan di atas formulir`() {
        show(SignInUiState(sessionExpired = true))

        rule.onNodeWithText("Sesi berakhir, silakan masuk lagi.")
            .assertIsDisplayed()
            .assert(SemanticsMatcher.expectValue(SemanticsProperties.LiveRegion, LiveRegionMode.Polite))
    }

    @Test
    fun `tautan daftar dan bantuan lupa kata sandi tersedia`() {
        show()

        rule.onNodeWithText("Belum punya akun? Daftar").performScrollTo().performClick()
        rule.onNodeWithText("Lupa kata sandi?", substring = true).performScrollTo().assertIsDisplayed()

        assertEquals(1, registers)
    }

    private companion object {
        // Nilai uji yang jelas-jelas palsu; bukan kredensial sungguhan.
        const val FAKE_PASSWORD = "sandi-palsu-uji"
    }
}
