package com.boothpos.customer.feature.account.presentation

import androidx.compose.runtime.getValue
import androidx.compose.runtime.mutableStateOf
import androidx.compose.runtime.setValue
import androidx.compose.ui.semantics.LiveRegionMode
import androidx.compose.ui.semantics.SemanticsProperties
import androidx.compose.ui.test.SemanticsMatcher
import androidx.compose.ui.test.assert
import androidx.compose.ui.test.assertHeightIsAtLeast
import androidx.compose.ui.test.assertIsDisplayed
import androidx.compose.ui.test.assertIsEnabled
import androidx.compose.ui.test.assertIsNotEnabled
import androidx.compose.ui.test.hasSetTextAction
import androidx.compose.ui.test.hasText
import androidx.compose.ui.test.junit4.createComposeRule
import androidx.compose.ui.test.onNodeWithText
import androidx.compose.ui.test.performClick
import androidx.compose.ui.test.performImeAction
import androidx.compose.ui.test.performTextClearance
import androidx.compose.ui.test.performTextInput
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

/** Layar ubah profil stateless di ponsel kecil (360 x 640 dp), tanpa Hilt dan tanpa jaringan. */
@RunWith(AndroidJUnit4::class)
@Config(qualifiers = "w360dp-h640dp")
class EditProfileScreenTest {

    @get:Rule
    val rule = createComposeRule()

    private val form = EditProfileUiState.Form(
        name = "Pelanggan Uji",
        phone = "0800-0000-0000",
        email = "pelanggan.uji@example.test",
    )
    private var state by mutableStateOf<EditProfileUiState>(form)
    private var submits = 0

    private val nameField = hasSetTextAction() and hasText("Nama")
    private val phoneField = hasSetTextAction() and hasText("Nomor telepon (opsional)")

    private fun show(initial: EditProfileUiState = form) {
        state = initial
        rule.setContent {
            BoothTheme {
                EditProfileScreen(
                    state = state,
                    onNameChange = { value -> state = (state as EditProfileUiState.Form).copy(name = value) },
                    onPhoneChange = { value -> state = (state as EditProfileUiState.Form).copy(phone = value) },
                    onSubmit = { submits++ },
                )
            }
        }
    }

    @Test
    fun `formulir menampilkan nama dan telepon yang dapat diubah serta e-mail yang hanya dibaca`() {
        show()

        rule.onNode(nameField).assertIsDisplayed().assertIsEnabled()
        rule.onNode(phoneField).assertIsDisplayed().assertIsEnabled()
        // E-mail adalah teks, bukan kolom isian.
        rule.onNode(hasText("E-mail") and hasText("pelanggan.uji@example.test") and hasText("E-mail tidak dapat diubah."))
            .assertIsDisplayed()
            .assert(hasSetTextAction().not())
        rule.onNodeWithText("Simpan").assertIsDisplayed().assertIsEnabled().assertHeightIsAtLeast(48.dp)
    }

    @Test
    fun `mengubah nilai dan menyimpan meneruskan nilai dan satu kali kirim`() {
        show()

        rule.onNode(nameField).performTextClearance()
        rule.onNode(nameField).performTextInput("Nama Baru")
        rule.onNode(phoneField).performTextClearance()
        rule.onNodeWithText("Simpan").performClick()

        val current = state as EditProfileUiState.Form
        assertEquals("Nama Baru", current.name)
        assertEquals("", current.phone)
        assertEquals(1, submits)
    }

    @Test
    fun `tombol selesai di papan ketik pada telepon menyimpan formulir`() {
        show()

        rule.onNode(phoneField).performImeAction()

        assertEquals(1, submits)
    }

    @Test
    fun `galat kolom tampil di bawah kolomnya dan diumumkan sebagai galat`() {
        show(
            form.copy(
                name = "",
                nameError = ProfileFieldError.Local(FieldIssue.Required),
                phoneError = ProfileFieldError.Server("Nomor telepon maksimal 30 karakter."),
            ),
        )

        rule.onNodeWithText("Nama wajib diisi.", substring = true).assertIsDisplayed()
        rule.onNodeWithText("Nomor telepon maksimal 30 karakter.", substring = true).assertIsDisplayed()
        rule.onNode(nameField).assert(SemanticsMatcher.keyIsDefined(SemanticsProperties.Error))
        rule.onNode(phoneField).assert(SemanticsMatcher.keyIsDefined(SemanticsProperties.Error))
    }

    @Test
    fun `batas panjang dari pemeriksaan di perangkat menyebut kolom dan batasnya`() {
        show(form.copy(phoneError = ProfileFieldError.Local(FieldIssue.TooLong(30))))

        rule.onNodeWithText("Nomor telepon maksimal 30 karakter.", substring = true).assertIsDisplayed()
    }

    @Test
    fun `galat formulir tampil sebagai pesan yang diumumkan`() {
        show(form.copy(formError = ApiError.Network))

        rule.onNodeWithText("Periksa koneksi internet Anda, lalu coba lagi.")
            .assertIsDisplayed()
            .assert(SemanticsMatcher.expectValue(SemanticsProperties.LiveRegion, LiveRegionMode.Polite))
    }

    @Test
    fun `saat menyimpan tombol utama dan kolom tidak dapat dipakai`() {
        show(form.copy(submitting = true))

        rule.onNodeWithText("Simpan")
            .assertIsNotEnabled()
            .assert(SemanticsMatcher.expectValue(SemanticsProperties.StateDescription, "Sedang diproses"))
            .performClick()
        // Kolom yang dinonaktifkan tidak lagi punya aksi isi teks, jadi dicari lewat labelnya.
        listOf("Nama", "Nomor telepon (opsional)").forEach { label ->
            rule.onNodeWithText(label).assertIsNotEnabled().assert(hasSetTextAction().not())
        }

        assertEquals(0, submits)
    }

    @Test
    fun `sesi yang belum terbaca tampil sebagai memuat`() {
        show(EditProfileUiState.Loading)

        rule.onNodeWithText("Memuat…").assertIsDisplayed()
    }
}
