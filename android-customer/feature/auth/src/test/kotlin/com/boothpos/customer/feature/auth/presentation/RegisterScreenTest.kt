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
import com.boothpos.customer.core.ui.theme.BoothTheme
import org.junit.Assert.assertEquals
import org.junit.Rule
import org.junit.Test
import org.junit.runner.RunWith
import org.robolectric.annotation.Config

/** Layar daftar stateless di ponsel kecil (360 x 640 dp), tanpa Hilt dan tanpa jaringan. */
@RunWith(AndroidJUnit4::class)
@Config(qualifiers = "w360dp-h640dp")
class RegisterScreenTest {

    @get:Rule
    val rule = createComposeRule()

    private var state by mutableStateOf(RegisterUiState())
    private var submits = 0
    private var signIns = 0

    private val nameField = hasSetTextAction() and hasText("Nama")
    private val emailField = hasSetTextAction() and hasText("E-mail")
    private val phoneField = hasSetTextAction() and hasText("Nomor telepon (opsional)")
    private val passwordField = hasSetTextAction() and hasText("Kata sandi")
    // Teks persisnya hanya dimiliki tombol utama (tautan di bawahnya berteks lebih panjang).
    private val submitButton = hasText("Daftar")

    private fun show(initial: RegisterUiState = RegisterUiState()) {
        state = initial
        rule.setContent {
            BoothTheme {
                RegisterScreen(
                    state = state,
                    onNameChange = { state = state.copy(name = it) },
                    onEmailChange = { state = state.copy(email = it) },
                    onPhoneChange = { state = state.copy(phone = it) },
                    onPasswordChange = { state = state.copy(password = it) },
                    onSubmit = { submits++ },
                    onSignIn = { signIns++ },
                )
            }
        }
    }

    @Test
    fun `empat kolom tombol utama dan tautan masuk tampil dengan labelnya`() {
        show()

        rule.onNode(nameField).assertIsDisplayed()
        rule.onNode(emailField).assertIsDisplayed()
        rule.onNode(phoneField).performScrollTo().assertIsDisplayed()
        rule.onNode(passwordField).performScrollTo().assertIsDisplayed()
        rule.onNodeWithText("Minimal 8 karakter.", substring = true).assertIsDisplayed()
        rule.onNode(submitButton).performScrollTo().assertIsDisplayed().assertIsEnabled().assertHeightIsAtLeast(48.dp)
        rule.onNodeWithText("Sudah punya akun? Masuk").performScrollTo().assertHasClickAction().assertHeightIsAtLeast(48.dp)
    }

    @Test
    fun `mengetik dan menekan tombol meneruskan nilai dan satu kali kirim`() {
        show()

        rule.onNode(nameField).performTextInput("Pelanggan Uji")
        rule.onNode(emailField).performTextInput("pelanggan.uji@example.test")
        rule.onNode(phoneField).performScrollTo().performTextInput("0800-0000-0000")
        rule.onNode(passwordField).performScrollTo().performTextInput(FAKE_PASSWORD)
        rule.onNode(submitButton).performScrollTo().performClick()

        assertEquals("Pelanggan Uji", state.name)
        assertEquals("pelanggan.uji@example.test", state.email)
        assertEquals("0800-0000-0000", state.phone)
        assertEquals(FAKE_PASSWORD, state.password)
        assertEquals(1, submits)
    }

    @Test
    fun `tombol selesai di papan ketik pada kata sandi mengirim formulir`() {
        show(RegisterUiState(name = "Pelanggan Uji", email = "pelanggan.uji@example.test", password = FAKE_PASSWORD))

        rule.onNode(passwordField).performScrollTo().performImeAction()

        assertEquals(1, submits)
    }

    @Test
    fun `galat tiap kolom tampil di bawah kolomnya dan diumumkan sebagai galat`() {
        show(
            RegisterUiState(
                nameError = FieldErrorUi.Local(FieldIssue.Required),
                emailError = FieldErrorUi.Local(FieldIssue.InvalidFormat),
                phoneError = FieldErrorUi.Local(FieldIssue.TooLong(30)),
                passwordError = FieldErrorUi.Local(FieldIssue.TooShort(8)),
            ),
        )

        rule.onNodeWithText("Nama wajib diisi.", substring = true).performScrollTo().assertIsDisplayed()
        rule.onNodeWithText("Format e-mail tidak valid.", substring = true).performScrollTo().assertIsDisplayed()
        rule.onNodeWithText("Nomor telepon maksimal 30 karakter.", substring = true).performScrollTo().assertIsDisplayed()
        rule.onNodeWithText("Kata sandi minimal 8 karakter.", substring = true).performScrollTo().assertIsDisplayed()
        listOf(nameField, emailField, phoneField, passwordField).forEach { field ->
            rule.onNode(field).assert(SemanticsMatcher.keyIsDefined(SemanticsProperties.Error))
        }
    }

    @Test
    fun `galat kolom dari server ditampilkan apa adanya`() {
        show(RegisterUiState(passwordError = FieldErrorUi.Server("Kata sandi terlalu umum.")))

        rule.onNodeWithText("Kata sandi terlalu umum.", substring = true).performScrollTo().assertIsDisplayed()
    }

    @Test
    fun `pendaftaran ditolak tampil netral tanpa menandai kolom e-mail`() {
        show(RegisterUiState(email = "pelanggan.uji@example.test", formMessage = AuthFormMessage.RegistrationFailed))

        rule.onNodeWithText("Pendaftaran tidak dapat diproses. Periksa data Anda, atau masuk jika sudah punya akun.")
            .assertIsDisplayed()
            .assert(SemanticsMatcher.expectValue(SemanticsProperties.LiveRegion, LiveRegionMode.Polite))
        rule.onNode(emailField).assert(SemanticsMatcher.keyNotDefined(SemanticsProperties.Error))
        rule.onNodeWithText("terdaftar", substring = true, ignoreCase = true).assertDoesNotExist()
    }

    @Test
    fun `saat permintaan berjalan tombol utama dan kolom tidak dapat dipakai`() {
        show(
            RegisterUiState(
                name = "Pelanggan Uji",
                email = "pelanggan.uji@example.test",
                password = FAKE_PASSWORD,
                submitting = true,
            ),
        )

        rule.onNode(submitButton)
            .performScrollTo()
            .assertIsNotEnabled()
            .assert(SemanticsMatcher.expectValue(SemanticsProperties.StateDescription, "Sedang diproses"))
            .performClick()
        // Kolom yang dinonaktifkan tidak lagi punya aksi isi teks, jadi dicari lewat labelnya.
        listOf("Nama", "E-mail", "Nomor telepon (opsional)", "Kata sandi").forEach { label ->
            rule.onNodeWithText(label).assertIsNotEnabled().assert(hasSetTextAction().not())
        }
        rule.onNodeWithText("Sudah punya akun? Masuk").assertIsNotEnabled()

        assertEquals(0, submits)
    }

    @Test
    fun `tombol mata menampilkan dan menyembunyikan kata sandi`() {
        show(RegisterUiState(password = FAKE_PASSWORD))
        val showsSecret = SemanticsMatcher.expectValue(SemanticsProperties.EditableText, AnnotatedString(FAKE_PASSWORD))

        rule.onNode(passwordField).performScrollTo().assert(showsSecret.not())
        rule.onNodeWithContentDescription("Tampilkan kata sandi").assertHasClickAction().performClick()
        rule.onNode(passwordField).assert(showsSecret)
        rule.onNodeWithContentDescription("Sembunyikan kata sandi").performClick()
        rule.onNode(passwordField).assert(showsSecret.not())
    }

    @Test
    fun `setiap kolom membawa petunjuk isi-otomatis`() {
        show()

        listOf(nameField, emailField, phoneField, passwordField).forEach { field ->
            rule.onNode(field).assert(SemanticsMatcher.keyIsDefined(SemanticsProperties.ContentType))
        }
    }

    @Test
    fun `tautan masuk memanggil callback`() {
        show()

        rule.onNodeWithText("Sudah punya akun? Masuk").performScrollTo().performClick()

        assertEquals(1, signIns)
    }

    private companion object {
        // Nilai uji yang jelas-jelas palsu; bukan kredensial sungguhan.
        const val FAKE_PASSWORD = "sandi-palsu-uji"
    }
}
