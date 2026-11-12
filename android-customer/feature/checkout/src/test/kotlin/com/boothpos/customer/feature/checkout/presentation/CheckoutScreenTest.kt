package com.boothpos.customer.feature.checkout.presentation

import androidx.compose.runtime.getValue
import androidx.compose.runtime.mutableStateOf
import androidx.compose.runtime.setValue
import androidx.compose.ui.test.assert
import androidx.compose.ui.test.assertCountEquals
import androidx.compose.ui.test.assertHasClickAction
import androidx.compose.ui.test.assertHeightIsAtLeast
import androidx.compose.ui.test.assertIsDisplayed
import androidx.compose.ui.test.assertIsEnabled
import androidx.compose.ui.test.assertIsNotEnabled
import androidx.compose.ui.test.assertIsNotSelected
import androidx.compose.ui.test.assertIsSelected
import androidx.compose.ui.test.getUnclippedBoundsInRoot
import androidx.compose.ui.test.hasText
import androidx.compose.ui.test.junit4.createComposeRule
import androidx.compose.ui.test.onAllNodesWithText
import androidx.compose.ui.test.onNodeWithTag
import androidx.compose.ui.test.onNodeWithText
import androidx.compose.ui.test.performClick
import androidx.compose.ui.test.performScrollTo
import androidx.compose.ui.unit.dp
import androidx.test.ext.junit.runners.AndroidJUnit4
import com.boothpos.customer.core.data.domain.model.FulfilmentMethod
import com.boothpos.customer.core.data.domain.validation.FieldIssue
import com.boothpos.customer.core.network.error.ApiError
import com.boothpos.customer.core.network.error.ApiErrorCodes
import com.boothpos.customer.core.ui.theme.BoothTheme
import com.boothpos.customer.feature.checkout.domain.CartIssueReason
import com.boothpos.customer.feature.checkout.presentation.CheckoutTestData.address
import java.math.BigDecimal
import org.junit.Assert.assertEquals
import org.junit.Assert.assertTrue
import org.junit.Rule
import org.junit.Test
import org.junit.runner.RunWith
import org.robolectric.annotation.Config

/** Checkout stateless di ponsel kecil (360 x 640 dp), tanpa Hilt maupun jaringan. */
@RunWith(AndroidJUnit4::class)
@Config(qualifiers = "w360dp-h640dp")
class CheckoutScreenTest {

    @get:Rule
    val rule = createComposeRule()

    private var state: CheckoutUiState by mutableStateOf(CheckoutUiState.Loading)
    private val calls = mutableListOf<String>()

    private fun updateForm(transform: (CheckoutUiState.Form) -> CheckoutUiState.Form) {
        (state as? CheckoutUiState.Form)?.let { state = transform(it) }
    }

    /** Callback meniru ViewModel secukupnya, supaya perubahan state terlihat di layar. */
    private val actions = CheckoutActions(
        onRetryLoad = { calls += "muat-ulang" },
        onSignIn = { calls += "masuk" },
        onBackToCart = { calls += "keranjang" },
        onViewOrder = { calls += "lihat-pesanan" },
        onSelectMethod = { method ->
            calls += "metode:${method.wireValue}"
            updateForm { it.copy(method = method, methodMissing = false) }
        },
        onContinueFromMethod = { calls += "lanjut" },
        onContactNameChange = { value -> updateForm { it.copy(contactName = value) } },
        onContactPhoneChange = { value -> updateForm { it.copy(contactPhone = value) } },
        onNotesChange = { value -> updateForm { it.copy(notes = value) } },
        onSelectAddress = { id ->
            calls += "alamat:$id"
            updateForm { it.copy(selectedAddressId = id) }
        },
        onAddAddress = { calls += "tambah-alamat" },
        onEditAddress = { calls += "ubah-alamat:$it" },
        onRetryAddresses = { calls += "muat-alamat" },
        onContinueToReview = { calls += "ke-ringkasan" },
        onRetryQuote = { calls += "quote-ulang" },
        onPlaceOrder = {
            calls += "buat-pesanan"
            // Seperti ViewModel: begitu ditekan, tombol masuk keadaan "sedang dikirim".
            updateForm { form -> form.copy(review = (form.review as ReviewState.Ready).copy(placing = true)) }
        },
        onDismissPriceChange = {
            calls += "tutup-harga"
            updateForm { form -> form.copy(review = (form.review as ReviewState.Ready).copy(priceChange = null)) }
        },
        onFixCart = { calls += "perbaiki-keranjang" },
        onRetryUnknownStatus = { calls += "periksa-status" },
    )

    private fun form(
        step: CheckoutStep,
        method: FulfilmentMethod? = FulfilmentMethod.Pickup,
        review: ReviewState = ReviewState.Idle,
    ) = CheckoutUiState.Form(
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

    private fun show(initial: CheckoutUiState) {
        state = initial
        rule.setContent { BoothTheme { CheckoutScreen(state = state, actions = actions) } }
    }

    private val placeOrderButton get() = rule.onNodeWithText("Buat pesanan")
    private val total get() = rule.onNodeWithTag(CheckoutTestTags.TOTAL)

    // --- keadaan layar ---

    @Test
    fun `memuat galat konfigurasi dan ajakan masuk masing-masing punya satu aksi yang jelas`() {
        show(CheckoutUiState.Loading)
        rule.onNodeWithText("Memuat…").assertIsDisplayed()

        state = CheckoutUiState.LoadFailed(ApiError.Network)
        rule.onNodeWithText("Tidak ada koneksi").assertIsDisplayed()
        rule.onNodeWithText("Coba lagi").performClick()

        state = CheckoutUiState.SignInRequired
        rule.onNodeWithText("Masuk untuk melanjutkan").assertIsDisplayed()
        rule.onNodeWithText("Masuk atau daftar").assertHasClickAction().assertHeightIsAtLeast(48.dp).performClick()

        state = CheckoutUiState.EmptyCart
        rule.onNodeWithText("Kembali ke keranjang").performClick()

        assertEquals(listOf("muat-ulang", "masuk", "keranjang"), calls)
    }

    @Test
    fun `toko tidak tersedia tampil tenang dengan coba lagi`() {
        show(CheckoutUiState.LoadFailed(ApiError.Http(503, ApiErrorCodes.CUSTOMER_COMMERCE_DISABLED)))

        rule.onNodeWithText("Toko sedang tidak tersedia").assertIsDisplayed()
        rule.onNodeWithText("Coba lagi").assertHasClickAction()
    }

    @Test
    fun `pesanan dibuat menampilkan nomor pesanan dan jalan ke pesanan`() {
        show(CheckoutUiState.Placed("CO-UJI0000001"))

        rule.onNodeWithText("Pesanan dibuat").assertIsDisplayed()
        rule.onNodeWithText("Nomor pesanan CO-UJI0000001. Toko akan memeriksa dan mengonfirmasi pesanan Anda.")
            .assertIsDisplayed()
        rule.onNodeWithText("Lihat pesanan").performClick()

        assertEquals(listOf("lihat-pesanan"), calls)
    }

    // --- langkah 1 ---

    @Test
    fun `langkah satu menawarkan ambil di toko atau kurir dengan petunjuk dari server`() {
        show(form(CheckoutStep.Method, method = null))

        rule.onNodeWithText("Langkah 1 dari 3 · Cara menerima pesanan").assertIsDisplayed()
        val pickup = rule.onNode(hasText("Ambil di toko") and hasText("Anda mengambil sendiri pesanan di toko."))
        val courier = rule.onNode(hasText("Dikirim kurir"))
        pickup.assertIsNotSelected().assertHeightIsAtLeast(48.dp)
        courier.assertIsNotSelected()
        rule.onNodeWithText("Petunjuk dari toko").assertDoesNotExist()

        pickup.performClick()
        pickup.assertIsSelected()
        rule.onNodeWithText("Ambil di meja depan, tunjukkan nomor pesanan.").assertIsDisplayed()

        courier.performClick()
        courier.assertIsSelected()
        pickup.assertIsNotSelected()
        rule.onNodeWithText("Dikirim setiap hari kerja.").assertIsDisplayed()
        rule.onNodeWithText("Lanjut").assertHasClickAction().assertHeightIsAtLeast(48.dp).performClick()

        assertEquals(listOf("metode:pickup", "metode:courier", "lanjut"), calls)
    }

    @Test
    fun `lanjut tanpa memilih cara menampilkan galat di tempatnya`() {
        show(form(CheckoutStep.Method, method = null).copy(methodMissing = true))

        rule.onNodeWithText("Pilih cara menerima pesanan.").assertIsDisplayed()
    }

    // --- langkah 2 ---

    @Test
    fun `langkah dua kurir menampilkan pilihan alamat dengan ubah dan tambah serta kontak dari profil`() {
        show(form(CheckoutStep.Details, method = FulfilmentMethod.Courier))

        rule.onNodeWithText("Langkah 2 dari 3 · Alamat dan kontak").assertIsDisplayed()
        val home = rule.onNode(hasText("Rumah (alamat utama)") and hasText("Jl. Contoh No. 5"))
        val office = rule.onNode(hasText("Kantor") and hasText("Jl. Contoh No. 6"))
        home.assertIsSelected()
        office.assertIsNotSelected().performClick()
        office.assertIsSelected()
        rule.onAllNodesWithText("Ubah").assertCountEquals(2)
        rule.onAllNodesWithText("Ubah")[1].assertHasClickAction().assertHeightIsAtLeast(48.dp).performClick()
        rule.onNodeWithText("Tambah alamat").performScrollTo().assertHasClickAction().performClick()
        rule.onNodeWithText("Pelanggan Uji").performScrollTo().assertIsDisplayed()
        rule.onNodeWithText("0800-0000-0000").performScrollTo().assertIsDisplayed()
        rule.onNodeWithText("Lanjut ke ringkasan").assertIsDisplayed().performClick()

        assertEquals(listOf("alamat:6", "ubah-alamat:6", "tambah-alamat", "ke-ringkasan"), calls)
    }

    @Test
    fun `ambil di toko tidak meminta alamat dan galat kolom tampil di bawah kolomnya`() {
        show(
            form(CheckoutStep.Details).copy(
                contactPhone = "",
                contactPhoneError = CheckoutFieldError.Local(FieldIssue.Required),
                notesError = CheckoutFieldError.Server("Catatan terlalu panjang."),
            ),
        )

        rule.onNodeWithText("Alamat pengiriman").assertDoesNotExist()
        rule.onNodeWithText("Nomor telepon wajib diisi.", substring = true).performScrollTo().assertIsDisplayed()
        rule.onNodeWithText("Catatan terlalu panjang.", substring = true).performScrollTo().assertIsDisplayed()
    }

    @Test
    fun `alamat yang belum ada gagal dimuat atau belum dipilih dijelaskan dengan teks`() {
        show(
            form(CheckoutStep.Details, method = FulfilmentMethod.Courier).copy(
                addresses = AddressesState.Loaded(emptyList()),
                selectedAddressId = null,
                addressMissing = true,
            ),
        )
        rule.onNodeWithText("Pilih atau tambahkan alamat pengiriman.").assertIsDisplayed()
        rule.onNodeWithText("Belum ada alamat tersimpan. Tambahkan alamat untuk pengiriman.").assertIsDisplayed()
        rule.onNodeWithText("Tambah alamat").assertHasClickAction()

        updateForm { it.copy(addresses = AddressesState.Failed(ApiError.Network), addressMissing = false) }
        rule.onNodeWithText("Alamat belum dapat dimuat. Periksa koneksi internet Anda, lalu coba lagi.")
            .assertIsDisplayed()
        rule.onNodeWithText("Coba lagi").performClick()

        updateForm { it.copy(addresses = AddressesState.Loading) }
        rule.onNodeWithText("Memuat alamat…").assertIsDisplayed()

        assertEquals(listOf("muat-alamat"), calls)
    }

    // --- langkah 3 ---

    @Test
    fun `ringkasan menampilkan baris subtotal ongkos kirim dan total dari quote dengan satu aksi utama`() {
        show(form(CheckoutStep.Review, review = ReviewState.Ready(CheckoutTestData.quote())))

        rule.onNodeWithText("Langkah 3 dari 3 · Ringkasan pesanan").assertIsDisplayed()
        rule.onNode(hasText("Gantungan Kunci Uji — Kecil") and hasText("2 × Rp15.000") and hasText("Rp30.000"))
            .assertIsDisplayed()
        rule.onNode(hasText("Stiker Uji — A5") and hasText("1 × Rp25.000")).assertIsDisplayed()
        rule.onNodeWithText("Ambil di meja depan, tunjukkan nomor pesanan.").performScrollTo().assertIsDisplayed()
        rule.onNodeWithText("Pelanggan Uji · 0800-0000-0000").performScrollTo().assertIsDisplayed()
        rule.onNode(hasText("Subtotal") and hasText("Rp55.000")).performScrollTo().assertIsDisplayed()
        rule.onNodeWithTag(CheckoutTestTags.SHIPPING).performScrollTo().assert(hasText("Rp0"))
        total.assertIsDisplayed().assert(hasText("Total")).assert(hasText("Rp55.000"))
        placeOrderButton.assertIsDisplayed().assertIsEnabled().assertHeightIsAtLeast(48.dp)
        // Total selalu terlihat tepat di atas tombol utama.
        assertTrue(total.getUnclippedBoundsInRoot().bottom <= placeOrderButton.getUnclippedBoundsInRoot().top)
        rule.onNodeWithText("Belum termasuk ongkos kirim, yang dikonfirmasi toko setelah pesanan dibuat.")
            .assertDoesNotExist()
    }

    @Test
    fun `ringkasan kurir menampilkan alamat dan ongkos kirim yang dikonfirmasi toko`() {
        show(
            form(
                CheckoutStep.Review,
                method = FulfilmentMethod.Courier,
                review = ReviewState.Ready(CheckoutTestData.quote(FulfilmentMethod.Courier)),
            ),
        )

        rule.onNodeWithText("Dikirim kurir").performScrollTo().assertIsDisplayed()
        rule.onNodeWithText("Jl. Contoh No. 5").performScrollTo().assertIsDisplayed()
        rule.onNodeWithTag(CheckoutTestTags.SHIPPING).performScrollTo()
            .assert(hasText("Ongkos kirim"))
            .assert(hasText("Ongkos kirim dikonfirmasi toko"))
        rule.onNodeWithText("Belum termasuk ongkos kirim, yang dikonfirmasi toko setelah pesanan dibuat.")
            .assertIsDisplayed()
    }

    @Test
    fun `tombol Buat pesanan nonaktif selama dikirim sehingga ketukan kedua tidak mengirim lagi`() {
        show(form(CheckoutStep.Review, review = ReviewState.Ready(CheckoutTestData.quote())))

        placeOrderButton.performClick()
        placeOrderButton.assertIsNotEnabled()
        placeOrderButton.performClick()
        placeOrderButton.performClick()

        assertEquals(listOf("buat-pesanan"), calls)
    }

    @Test
    fun `price_changed menampilkan dialog total lama dan baru lalu menunggu konfirmasi ulang`() {
        val newQuote = CheckoutTestData.quote(keychainPrice = "17500.00")
        show(
            form(
                CheckoutStep.Review,
                review = ReviewState.Ready(
                    quote = newQuote,
                    priceChange = PriceChange(BigDecimal("55000.00"), BigDecimal("60000.00")),
                ),
            ),
        )

        rule.onNodeWithText("Harga berubah").assertIsDisplayed()
        rule.onNodeWithText(
            "Total sebelumnya Rp55.000, sekarang Rp60.000. Pesanan belum dibuat. Periksa rincian terbaru, " +
                "lalu tekan Buat pesanan bila Anda setuju.",
        ).assertIsDisplayed()
        // Menutup dialog TIDAK membuat pesanan.
        rule.onNodeWithText("Lihat rincian").assertHasClickAction().performClick()
        rule.onNodeWithText("Harga berubah").assertDoesNotExist()
        assertEquals(listOf("tutup-harga"), calls)

        // Rincian baru dari server yang tampil; pesanan baru dikirim oleh ketukan eksplisit.
        total.assert(hasText("Rp60.000"))
        rule.onNode(hasText("Gantungan Kunci Uji — Kecil") and hasText("2 × Rp17.500")).assertIsDisplayed()
        placeOrderButton.assertIsEnabled().performClick()
        assertEquals(listOf("tutup-harga", "buat-pesanan"), calls)
    }

    @Test
    fun `status belum diketahui tidak menyebut gagal dan hanya menawarkan periksa status`() {
        show(form(CheckoutStep.Review, review = ReviewState.StatusUnknown(CheckoutTestData.quote())))

        rule.onNodeWithText("Status pesanan belum diketahui").assertIsDisplayed()
        rule.onNodeWithText("Pesanan tidak akan dibuat dua kali.", substring = true).assertIsDisplayed()
        rule.onAllNodesWithText("gagal", substring = true, ignoreCase = true).assertCountEquals(0)
        placeOrderButton.assertDoesNotExist()
        val check = rule.onNodeWithText("Periksa status pesanan")
        check.assertIsEnabled().assertHeightIsAtLeast(48.dp).performClick()
        assertEquals(listOf("periksa-status"), calls)

        updateForm { it.copy(review = ReviewState.StatusUnknown(quote = null, checking = true)) }
        check.assertIsNotEnabled()
    }

    @Test
    fun `cart_invalid menjelaskan tiap barang dengan teks dan mengarahkan ke keranjang`() {
        show(
            form(
                CheckoutStep.Review,
                review = ReviewState.CartInvalid(
                    listOf(
                        CartIssueLine(31, "Gantungan Kunci Uji — Kecil", CartIssueReason.InsufficientStock, 1),
                        CartIssueLine(32, "Stiker Uji — A5", CartIssueReason.Unavailable, 0),
                        CartIssueLine(33, null, CartIssueReason.QuantityLimit, 20),
                    ),
                ),
            ),
        )

        rule.onNode(hasText("Gantungan Kunci Uji — Kecil") and hasText("Stok tidak cukup. Tersedia paling banyak 1."))
            .assertIsDisplayed()
        rule.onNode(hasText("Stiker Uji — A5") and hasText("Tidak lagi tersedia.")).assertIsDisplayed()
        rule.onNode(hasText("Barang di keranjang") and hasText("Melebihi batas per pesanan. Paling banyak 20."))
            .assertIsDisplayed()
        placeOrderButton.assertDoesNotExist()
        rule.onNodeWithText("Perbaiki keranjang").assertHasClickAction().assertHeightIsAtLeast(48.dp).performClick()

        assertEquals(listOf("perbaiki-keranjang"), calls)
    }

    @Test
    fun `quote yang sedang dihitung atau gagal tidak menampilkan tombol Buat pesanan`() {
        show(form(CheckoutStep.Review, review = ReviewState.LoadingQuote))
        rule.onNodeWithText("Menghitung total dari toko…").assertIsDisplayed()
        placeOrderButton.assertDoesNotExist()

        updateForm { it.copy(review = ReviewState.QuoteFailed(ApiError.Timeout)) }
        rule.onNodeWithText("Koneksi terlalu lambat. Silakan coba lagi.").assertIsDisplayed()
        placeOrderButton.assertDoesNotExist()
        rule.onNodeWithText("Coba lagi").performClick()

        updateForm {
            it.copy(review = ReviewState.QuoteFailed(ApiError.Http(503, ApiErrorCodes.CUSTOMER_COMMERCE_DISABLED)))
        }
        rule.onNodeWithText("Toko sedang tidak tersedia").assertIsDisplayed()

        assertEquals(listOf("quote-ulang"), calls)
    }

    @Test
    fun `penolakan server dan pemberitahuan harga tampil di ringkasan tanpa menyembunyikan total`() {
        show(
            form(
                CheckoutStep.Review,
                review = ReviewState.Ready(
                    quote = CheckoutTestData.quote(),
                    notice = ReviewNotice.PricesUpdated,
                    submitError = ApiError.Http(429, ApiErrorCodes.TOO_MANY_REQUESTS),
                ),
            ),
        )

        rule.onNodeWithText("Terlalu banyak percobaan. Tunggu sebentar, lalu coba lagi.").assertIsDisplayed()
        rule.onNodeWithText(
            "Harga di bawah adalah harga terbaru dari toko dan berbeda dari yang tampil di keranjang.",
        ).assertIsDisplayed()
        total.assertIsDisplayed()
        placeOrderButton.assertIsEnabled()
    }
}
