package com.boothpos.customer.feature.checkout.presentation.address

import androidx.compose.runtime.getValue
import androidx.compose.runtime.mutableStateOf
import androidx.compose.runtime.setValue
import androidx.compose.ui.test.assertHasClickAction
import androidx.compose.ui.test.assertHeightIsAtLeast
import androidx.compose.ui.test.assertIsDisplayed
import androidx.compose.ui.test.assertIsNotEnabled
import androidx.compose.ui.test.assertIsOff
import androidx.compose.ui.test.assertIsOn
import androidx.compose.ui.test.hasText
import androidx.compose.ui.test.junit4.createComposeRule
import androidx.compose.ui.test.onNodeWithContentDescription
import androidx.compose.ui.test.onNodeWithText
import androidx.compose.ui.test.performClick
import androidx.compose.ui.test.performScrollTo
import androidx.compose.ui.test.performTextInput
import androidx.compose.ui.unit.dp
import androidx.test.ext.junit.runners.AndroidJUnit4
import com.boothpos.customer.core.data.domain.validation.FieldIssue
import com.boothpos.customer.core.network.error.ApiError
import com.boothpos.customer.core.network.error.ApiErrorCodes
import com.boothpos.customer.core.ui.theme.BoothTheme
import com.boothpos.customer.feature.checkout.domain.AddressDraft
import com.boothpos.customer.feature.checkout.domain.AddressField
import com.boothpos.customer.feature.checkout.presentation.CheckoutTestData.address
import org.junit.Assert.assertEquals
import org.junit.Rule
import org.junit.Test
import org.junit.runner.RunWith
import org.robolectric.annotation.Config

/** Daftar dan formulir alamat stateless di ponsel kecil (360 x 640 dp). */
@RunWith(AndroidJUnit4::class)
@Config(qualifiers = "w360dp-h640dp")
class AddressScreensTest {

    @get:Rule
    val rule = createComposeRule()

    private val calls = mutableListOf<String>()
    private var formState: AddressFormUiState by mutableStateOf(AddressFormUiState.Loading)
    private var listState: AddressListUiState by mutableStateOf(AddressListUiState.Loading)

    private fun showForm(initial: AddressFormUiState) {
        formState = initial
        rule.setContent {
            BoothTheme {
                AddressFormScreen(
                    state = formState,
                    onFieldChange = { field, value ->
                        calls += "${field.wireName}=$value"
                        (formState as? AddressFormUiState.Form)?.let { formState = it.copy(draft = it.draft.with(field, value)) }
                    },
                    onDefaultChange = { calls += "utama=$it" },
                    onSubmit = { calls += "simpan" },
                    onRetry = { calls += "coba-lagi" },
                    onClose = { calls += "tutup" },
                )
            }
        }
    }

    private fun showList(initial: AddressListUiState) {
        listState = initial
        rule.setContent {
            BoothTheme {
                AddressListScreen(
                    state = listState,
                    onRetry = { calls += "muat-ulang" },
                    onAddAddress = { calls += "tambah" },
                    onEditAddress = { calls += "ubah:$it" },
                    onSetDefault = { calls += "utama:$it" },
                    onRequestDelete = { calls += "hapus:$it" },
                    onConfirmDelete = { calls += "konfirmasi-hapus" },
                    onDismissDelete = { calls += "batal-hapus" },
                )
            }
        }
    }

    // --- formulir ---

    @Test
    fun `formulir alamat menandai kolom opsional dan meneruskan ketikan serta simpan`() {
        showForm(AddressFormUiState.Form(isNew = true, draft = AddressDraft(recipientName = "Pelanggan Uji")))

        rule.onNodeWithText("Label alamat (opsional)").assertIsDisplayed()
        rule.onNodeWithText("Nama penerima").assertIsDisplayed()
        rule.onNodeWithText("Kota/Kabupaten").performScrollTo().performTextInput("Kota Uji")
        rule.onNodeWithText("Kode pos (opsional)").performScrollTo().assertIsDisplayed()
        rule.onNode(hasText("Jadikan alamat utama") and hasText("Alamat utama dipilih otomatis saat checkout."))
            .performScrollTo()
            .assertIsOff()
            .assertHeightIsAtLeast(48.dp)
            .performClick()
        rule.onNodeWithText("Simpan alamat").performScrollTo().assertHasClickAction().assertHeightIsAtLeast(48.dp).performClick()

        assertEquals(listOf("city=Kota Uji", "utama=true", "simpan"), calls)
    }

    @Test
    fun `galat kolom tampil di bawah kolomnya dan galat batas alamat di atas formulir`() {
        showForm(
            AddressFormUiState.Form(
                isNew = true,
                draft = AddressDraft(),
                errors = mapOf(
                    AddressField.RecipientName to AddressFieldError.Local(FieldIssue.Required),
                    AddressField.Label to AddressFieldError.Local(FieldIssue.TooLong(50)),
                    AddressField.PostalCode to AddressFieldError.Server("Kode pos tidak valid."),
                ),
                formError = ApiError.Http(409, ApiErrorCodes.ADDRESS_LIMIT_REACHED),
            ),
        )

        rule.onNodeWithText("Jumlah alamat sudah mencapai batas. Hapus salah satu alamat lebih dulu.").assertIsDisplayed()
        rule.onNodeWithText("Nama penerima wajib diisi.", substring = true).performScrollTo().assertIsDisplayed()
        rule.onNodeWithText("Label alamat maksimal 50 karakter.", substring = true).performScrollTo().assertIsDisplayed()
        rule.onNodeWithText("Kode pos tidak valid.", substring = true).performScrollTo().assertIsDisplayed()
    }

    @Test
    fun `alamat utama tidak dapat dicabut dan alamat yang hilang punya jalan kembali`() {
        showForm(
            AddressFormUiState.Form(
                isNew = false,
                draft = AddressDraft.from(address(id = 5)),
                defaultLocked = true,
            ),
        )
        rule.onNode(hasText("Jadikan alamat utama") and hasText("Ini alamat utama Anda.", substring = true))
            .performScrollTo()
            .assertIsOn()
            .assertIsNotEnabled()

        formState = AddressFormUiState.NotFound
        rule.onNodeWithText("Alamat tidak ditemukan").assertIsDisplayed()
        rule.onNodeWithText("Kembali").performClick()

        formState = AddressFormUiState.LoadFailed(ApiError.Network)
        rule.onNodeWithText("Coba lagi").performClick()

        assertEquals(listOf("tutup", "coba-lagi"), calls)
    }

    // --- daftar ---

    @Test
    fun `daftar kosong mengajak menambah alamat`() {
        showList(AddressListUiState.Content(emptyList()))

        rule.onNodeWithText("Belum ada alamat").assertIsDisplayed()
        rule.onNodeWithText("Tambah alamat").assertHasClickAction().assertHeightIsAtLeast(48.dp).performClick()

        assertEquals(listOf("tambah"), calls)
    }

    @Test
    fun `daftar menandai alamat utama dengan teks dan menyediakan ubah hapus serta jadikan utama`() {
        showList(AddressListUiState.Content(listOf(address(id = 5), address(id = 6, label = "Kantor", isDefault = false))))

        rule.onNodeWithText("Rumah (alamat utama)").assertIsDisplayed()
        rule.onNodeWithText("Kantor").assertIsDisplayed()
        // Hanya alamat yang bukan utama yang menawarkan "Jadikan alamat utama".
        rule.onNodeWithText("Jadikan alamat utama").assertHasClickAction().assertHeightIsAtLeast(48.dp).performClick()
        rule.onNodeWithContentDescription("Ubah alamat Kantor").assertHasClickAction().performClick()
        rule.onNodeWithContentDescription("Hapus alamat Rumah").assertHasClickAction().assertHeightIsAtLeast(48.dp).performClick()
        rule.onNodeWithText("Tambah alamat").assertIsDisplayed().performClick()

        assertEquals(listOf("utama:6", "ubah:6", "hapus:5", "tambah"), calls)
    }

    @Test
    fun `hapus meminta konfirmasi dan aksi dinonaktifkan selama perubahan berjalan`() {
        val addresses = listOf(address(id = 5), address(id = 6, label = "Kantor", isDefault = false))
        showList(AddressListUiState.Content(addresses, pendingDelete = addresses[1]))

        rule.onNodeWithText("Hapus alamat?").assertIsDisplayed()
        rule.onNodeWithText("Alamat Kantor akan dihapus. Pesanan yang sudah dibuat tidak berubah.").assertIsDisplayed()
        rule.onNodeWithText("Batal").performClick()

        listState = AddressListUiState.Content(addresses, busy = true)
        rule.onNodeWithContentDescription("Memperbarui alamat…").assertIsDisplayed()
        rule.onNodeWithText("Tambah alamat").assertIsNotEnabled()
        rule.onNodeWithContentDescription("Hapus alamat Kantor").assertIsNotEnabled()

        listState = AddressListUiState.Content(addresses, actionError = ApiError.Network)
        rule.onNodeWithText("Periksa koneksi internet Anda, lalu coba lagi.").assertIsDisplayed()
        rule.onNodeWithText("Muat ulang").performClick()

        assertEquals(listOf("batal-hapus", "muat-ulang"), calls)
    }
}
