package com.boothpos.customer.feature.account.presentation

import androidx.compose.runtime.getValue
import androidx.compose.runtime.mutableStateOf
import androidx.compose.runtime.setValue
import androidx.compose.ui.semantics.SemanticsProperties
import androidx.compose.ui.test.SemanticsMatcher
import androidx.compose.ui.test.assert
import androidx.compose.ui.test.assertHasClickAction
import androidx.compose.ui.test.assertHeightIsAtLeast
import androidx.compose.ui.test.assertIsDisplayed
import androidx.compose.ui.test.assertIsNotEnabled
import androidx.compose.ui.test.hasAnyAncestor
import androidx.compose.ui.test.hasText
import androidx.compose.ui.test.isDialog
import androidx.compose.ui.test.junit4.createComposeRule
import androidx.compose.ui.test.onNodeWithContentDescription
import androidx.compose.ui.test.onNodeWithText
import androidx.compose.ui.test.performClick
import androidx.compose.ui.unit.dp
import androidx.test.ext.junit.runners.AndroidJUnit4
import com.boothpos.customer.core.network.error.ApiError
import com.boothpos.customer.core.ui.theme.BoothTheme
import org.junit.Assert.assertEquals
import org.junit.Rule
import org.junit.Test
import org.junit.runner.RunWith
import org.robolectric.annotation.Config

/** Layar Akun stateless di ponsel kecil (360 x 640 dp), tanpa Hilt dan tanpa jaringan. */
@RunWith(AndroidJUnit4::class)
@Config(qualifiers = "w360dp-h640dp")
class AccountScreenTest {

    @get:Rule
    val rule = createComposeRule()

    private var state by mutableStateOf<AccountUiState>(AccountUiState.Loading)
    private val actions = mutableListOf<String>()

    private val profile = AccountUiState.Profile(
        name = "Pelanggan Uji",
        email = "pelanggan.uji@example.test",
        phone = "0800-0000-0000",
    )

    private fun show(initial: AccountUiState) {
        state = initial
        rule.setContent {
            BoothTheme {
                AccountScreen(
                    state = state,
                    onSignIn = { actions += "masuk" },
                    onRegister = { actions += "daftar" },
                    onEditProfile = { actions += "ubah" },
                    onManageAddresses = { actions += "alamat" },
                    onRetryRefresh = { actions += "coba-lagi" },
                    onSignOutClick = { actions += "keluar" },
                    onConfirmSignOut = { actions += "konfirmasi-keluar" },
                    onDismissSignOut = { actions += "batal" },
                )
            }
        }
    }

    @Test
    fun `sesi yang belum terbaca tampil sebagai memuat`() {
        show(AccountUiState.Loading)

        rule.onNodeWithText("Memuat…").assertIsDisplayed()
    }

    @Test
    fun `belum masuk menampilkan ajakan dengan Masuk sebagai aksi utama dan Daftar pendamping`() {
        show(AccountUiState.SignedOut(sessionExpired = false))

        rule.onNodeWithText("Anda belum masuk")
            .assertIsDisplayed()
            .assert(SemanticsMatcher.keyIsDefined(SemanticsProperties.Heading))
        rule.onNodeWithText("Sesi berakhir, silakan masuk lagi.").assertDoesNotExist()
        rule.onNodeWithText("Masuk").assertHasClickAction().assertHeightIsAtLeast(48.dp).performClick()
        rule.onNodeWithText("Daftar").assertHasClickAction().assertHeightIsAtLeast(48.dp).performClick()

        assertEquals(listOf("masuk", "daftar"), actions)
    }

    @Test
    fun `sesi berakhir dijelaskan dengan teks di atas ajakan masuk`() {
        show(AccountUiState.SignedOut(sessionExpired = true))

        rule.onNodeWithText("Sesi berakhir, silakan masuk lagi.").assertIsDisplayed()
        rule.onNodeWithText("Masuk").assertIsDisplayed()
    }

    @Test
    fun `profil menampilkan nama e-mail dan telepon dengan labelnya`() {
        show(profile)

        // Label dan nilai digabung menjadi satu simpul supaya pembaca layar membacanya sekaligus.
        rule.onNode(hasText("Nama") and hasText("Pelanggan Uji")).assertIsDisplayed()
        rule.onNode(hasText("E-mail") and hasText("pelanggan.uji@example.test")).assertIsDisplayed()
        rule.onNode(hasText("Nomor telepon") and hasText("0800-0000-0000")).assertIsDisplayed()
        rule.onNodeWithText("Ubah profil").assertHasClickAction().assertHeightIsAtLeast(48.dp).performClick()

        assertEquals(listOf("ubah"), actions)
    }

    @Test
    fun `profil menyediakan jalan ke kelola alamat pengiriman`() {
        show(profile)

        rule.onNodeWithText("Alamat pengiriman").assertHasClickAction().assertHeightIsAtLeast(48.dp).performClick()

        assertEquals(listOf("alamat"), actions)
    }

    @Test
    fun `telepon yang kosong ditulis sebagai belum diisi`() {
        show(profile.copy(phone = null))

        rule.onNode(hasText("Nomor telepon") and hasText("Belum diisi")).assertIsDisplayed()
    }

    @Test
    fun `gagal menyegarkan tetap menampilkan profil dengan pesan dan coba lagi`() {
        show(profile.copy(refreshError = ApiError.Network))

        rule.onNodeWithText("Profil belum dapat diperbarui. Periksa koneksi internet Anda, lalu coba lagi.")
            .assertIsDisplayed()
        rule.onNode(hasText("Nama") and hasText("Pelanggan Uji")).assertIsDisplayed()
        rule.onNodeWithText("Coba lagi").assertHasClickAction().assertHeightIsAtLeast(48.dp).performClick()

        assertEquals(listOf("coba-lagi"), actions)
    }

    @Test
    fun `sedang menyegarkan diumumkan lewat indikator berdeskripsi`() {
        show(profile.copy(refreshing = true))

        rule.onNodeWithContentDescription("Memperbarui profil…").assertIsDisplayed()
    }

    @Test
    fun `keluar meminta konfirmasi dan menyebut keranjang akan dikosongkan`() {
        show(profile)

        rule.onNodeWithText("Keluar").assertHasClickAction().assertHeightIsAtLeast(48.dp).performClick()
        assertEquals(listOf("keluar"), actions)
        rule.onNodeWithText("Keluar dari akun?").assertDoesNotExist()

        state = profile.copy(confirmingSignOut = true)
        rule.onNodeWithText("Keluar dari akun?").assertIsDisplayed()
        rule.onNodeWithText("Keranjang belanja di perangkat ini akan dikosongkan.").assertIsDisplayed()
        rule.onNode(hasText("Batal") and hasAnyAncestor(isDialog())).performClick()
        rule.onNode(hasText("Keluar") and hasAnyAncestor(isDialog())).performClick()

        assertEquals(listOf("keluar", "batal", "konfirmasi-keluar"), actions)
    }

    @Test
    fun `saat sedang keluar tombol tidak dapat ditekan lagi`() {
        show(profile.copy(signingOut = true))

        rule.onNodeWithText("Keluar").assertIsNotEnabled().performClick()
        rule.onNodeWithText("Ubah profil").assertIsNotEnabled()

        assertEquals(emptyList<String>(), actions)
    }
}
