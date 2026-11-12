package com.boothpos.customer.feature.checkout.presentation

import androidx.lifecycle.SavedStateHandle
import app.cash.turbine.test
import com.boothpos.customer.core.data.domain.model.FulfilmentMethod
import com.boothpos.customer.core.data.domain.session.SessionState
import com.boothpos.customer.core.data.domain.validation.FieldIssue
import com.boothpos.customer.core.data.testing.FakeAddressRepository
import com.boothpos.customer.core.data.testing.FakeCartRepository
import com.boothpos.customer.core.data.testing.FakeSessionRepository
import com.boothpos.customer.core.data.testing.FakeStoreConfigRepository
import com.boothpos.customer.core.data.testing.MainDispatcherRule
import com.boothpos.customer.core.data.testing.TestData
import com.boothpos.customer.core.network.error.ApiError
import com.boothpos.customer.core.network.error.ApiErrorCodes
import com.boothpos.customer.core.network.error.ApiResult
import com.boothpos.customer.core.network.error.isStoreUnavailable
import com.boothpos.customer.feature.checkout.domain.CartIssue
import com.boothpos.customer.feature.checkout.domain.CartIssueReason
import com.boothpos.customer.feature.checkout.domain.CheckoutItem
import com.boothpos.customer.feature.checkout.domain.IdempotencyKeyGenerator
import com.boothpos.customer.feature.checkout.domain.PlaceOrderOutcome
import com.boothpos.customer.feature.checkout.domain.QuoteOutcome
import com.boothpos.customer.feature.checkout.presentation.CheckoutTestData.address
import com.boothpos.customer.feature.checkout.presentation.CheckoutTestData.keychain
import com.boothpos.customer.feature.checkout.presentation.CheckoutTestData.sticker
import java.math.BigDecimal
import kotlinx.coroutines.CompletableDeferred
import kotlinx.coroutines.ExperimentalCoroutinesApi
import kotlinx.coroutines.test.TestScope
import kotlinx.coroutines.test.runCurrent
import kotlinx.coroutines.test.runTest
import org.junit.Assert.assertEquals
import org.junit.Assert.assertFalse
import org.junit.Assert.assertNotEquals
import org.junit.Assert.assertNull
import org.junit.Assert.assertTrue
import org.junit.Rule
import org.junit.Test

@OptIn(ExperimentalCoroutinesApi::class)
class CheckoutViewModelTest {

    @get:Rule
    val mainRule = MainDispatcherRule()

    private val account = TestData.account()
    private val session = FakeSessionRepository(SessionState.SignedIn(account, expiresAt = null))
    private val cart = FakeCartRepository(listOf(keychain, sticker))
    private val storeConfig = FakeStoreConfigRepository(
        TestData.storeConfig().copy(courierInstructions = "Dikirim setiap hari kerja."),
    )
    private val addresses = FakeAddressRepository(listOf(address(id = 5), address(id = 6, label = "Kantor", isDefault = false)))
    private val checkout = FakeCheckoutRepository()
    private val savedState = SavedStateHandle()
    private var generatedKeys = 0
    private val keyGenerator = IdempotencyKeyGenerator { "kunci-uji-%04d-abcdefgh".format(++generatedKeys) }
    private val pendingIntents = FakePendingOrderIntentStore()

    private val tooManyRequests = ApiError.Http(429, ApiErrorCodes.TOO_MANY_REQUESTS, retryAfterSeconds = 30)

    private fun viewModel(handle: SavedStateHandle = savedState) =
        CheckoutViewModel(handle, session, cart, storeConfig, addresses, checkout, keyGenerator, pendingIntents)

    private fun CheckoutViewModel.form() = uiState.value as CheckoutUiState.Form

    private fun CheckoutViewModel.ready() = form().review as ReviewState.Ready

    /** Dari layar pertama sampai ringkasan dengan quote dari server tiruan. */
    private fun TestScope.openReview(viewModel: CheckoutViewModel, method: FulfilmentMethod = FulfilmentMethod.Pickup) {
        runCurrent()
        viewModel.selectMethod(method)
        viewModel.continueFromMethod()
        runCurrent()
        viewModel.continueToReview()
        runCurrent()
    }

    // --- prasyarat: sesi, keranjang, konfigurasi ---

    @Test
    fun `tanpa sesi checkout meminta masuk sekali lalu berlanjut setelah masuk dengan keranjang utuh`() =
        runTest(mainRule.dispatcher) {
            session.set(SessionState.SignedOut())
            val viewModel = viewModel()

            viewModel.events.test {
                runCurrent()
                assertEquals(CheckoutEvent.RequireSignIn, awaitItem())
                assertEquals(CheckoutUiState.SignInRequired, viewModel.uiState.value)

                // Kembali dari layar masuk tanpa masuk: tidak dipantulkan ke sana lagi.
                cart.setQuantity(31, 3)
                runCurrent()
                expectNoEvents()
                assertEquals(CheckoutUiState.SignInRequired, viewModel.uiState.value)

                // Tombol pada ajakan masuk.
                viewModel.requestSignIn()
                assertEquals(CheckoutEvent.RequireSignIn, awaitItem())

                session.startSession("tes-token-palsu-123", expiresAt = null, account = account)
                runCurrent()
                assertEquals(CheckoutStep.Method, viewModel.form().step)
                assertEquals(listOf(31L, 32L), cart.current.map { it.variantId })
                expectNoEvents()
            }
            assertTrue(checkout.quoteRequests.isEmpty())
        }

    @Test
    fun `sesi yang belum terbaca tampil sebagai memuat dan keranjang kosong tidak dapat di-checkout`() =
        runTest(mainRule.dispatcher) {
            session.set(SessionState.Loading)
            val viewModel = viewModel()
            runCurrent()
            assertEquals(CheckoutUiState.Loading, viewModel.uiState.value)

            cart.clear()
            session.set(SessionState.SignedIn(account, expiresAt = null))
            runCurrent()

            assertEquals(CheckoutUiState.EmptyCart, viewModel.uiState.value)
        }

    @Test
    fun `formulir dibuka dengan metode dari konfigurasi petunjuk server dan kontak dari profil`() =
        runTest(mainRule.dispatcher) {
            val viewModel = viewModel()
            runCurrent()

            val form = viewModel.form()
            assertEquals(CheckoutStep.Method, form.step)
            assertEquals(listOf(FulfilmentMethod.Pickup, FulfilmentMethod.Courier), form.methods)
            assertNull("Tidak ada metode yang dipilihkan diam-diam", form.method)
            assertNull(form.instructions)
            assertEquals(account.name, form.contactName)
            assertEquals(account.phone, form.contactPhone)

            // Lanjut tanpa memilih: galat langsung, tidak pindah langkah.
            viewModel.continueFromMethod()
            assertTrue(viewModel.form().methodMissing)
            assertEquals(CheckoutStep.Method, viewModel.form().step)

            viewModel.selectMethod(FulfilmentMethod.Pickup)
            assertEquals("Ambil di meja depan.", viewModel.form().instructions)
            assertFalse(viewModel.form().methodMissing)
            viewModel.selectMethod(FulfilmentMethod.Courier)
            assertEquals("Dikirim setiap hari kerja.", viewModel.form().instructions)
        }

    @Test
    fun `konfigurasi toko yang tidak dapat diambil tampil sebagai galat dan dapat dicoba lagi`() =
        runTest(mainRule.dispatcher) {
            val emptyConfig = FakeStoreConfigRepository()
            val viewModel = CheckoutViewModel(savedState, session, cart, emptyConfig, addresses, checkout, keyGenerator, pendingIntents)
            runCurrent()
            assertEquals(CheckoutUiState.LoadFailed(ApiError.Network), viewModel.uiState.value)

            emptyConfig.refreshResult = ApiResult.Success(TestData.storeConfig())
            viewModel.retryLoad()
            runCurrent()

            assertEquals(CheckoutStep.Method, viewModel.form().step)
            assertEquals(2, emptyConfig.refreshCalls)
        }

    // --- langkah 2: alamat dan kontak ---

    @Test
    fun `kurir memuat alamat memilih alamat utama dan menolak lanjut tanpa alamat atau telepon`() =
        runTest(mainRule.dispatcher) {
            addresses.addresses = emptyList()
            val viewModel = viewModel()
            runCurrent()
            viewModel.selectMethod(FulfilmentMethod.Courier)
            viewModel.continueFromMethod()
            runCurrent()
            assertEquals(AddressesState.Loaded(emptyList()), viewModel.form().addresses)

            viewModel.onContactPhoneChange("   ")
            viewModel.onContactNameChange("")
            viewModel.onNotesChange("x".repeat(501))
            viewModel.continueToReview()

            val form = viewModel.form()
            assertEquals(CheckoutStep.Details, form.step)
            assertTrue(form.addressMissing)
            assertEquals(CheckoutFieldError.Local(FieldIssue.Required), form.contactNameError)
            assertEquals(CheckoutFieldError.Local(FieldIssue.Required), form.contactPhoneError)
            assertEquals(CheckoutFieldError.Local(FieldIssue.TooLong(500)), form.notesError)
            assertTrue("Tidak ada quote untuk isian yang belum sah", checkout.quoteRequests.isEmpty())

            // Mengetik menghapus galat kolom itu.
            viewModel.onContactNameChange("Pelanggan Uji")
            assertNull(viewModel.form().contactNameError)
        }

    @Test
    fun `alamat utama terpilih otomatis dan alamat yang baru ditambahkan langsung terpilih saat kembali`() =
        runTest(mainRule.dispatcher) {
            val viewModel = viewModel()
            runCurrent()
            viewModel.selectMethod(FulfilmentMethod.Courier)
            viewModel.continueFromMethod()
            runCurrent()
            assertEquals(5L, viewModel.form().selectedAddressId)

            viewModel.selectAddress(6)
            assertEquals(6L, viewModel.form().selectedAddressId)

            // Pelanggan menambah alamat di formulir alamat (layar lain), lalu kembali ke checkout.
            addresses.addresses = addresses.addresses + address(id = 9, label = "Kos", isDefault = false)
            viewModel.onScreenShown()
            runCurrent()

            assertEquals(9L, viewModel.form().selectedAddressId)
            assertEquals(9L, viewModel.form().selectedAddress?.id)
        }

    @Test
    fun `gagal memuat alamat tampil sebagai galat bagian itu dan dapat dicoba lagi`() = runTest(mainRule.dispatcher) {
        addresses.failure = ApiError.Network
        val viewModel = viewModel()
        runCurrent()
        viewModel.selectMethod(FulfilmentMethod.Courier)
        viewModel.continueFromMethod()
        runCurrent()
        assertEquals(AddressesState.Failed(ApiError.Network), viewModel.form().addresses)

        addresses.failure = null
        viewModel.retryAddresses()
        runCurrent()

        assertEquals(5L, viewModel.form().selectedAddressId)
    }

    // --- langkah 3: quote ---

    @Test
    fun `ringkasan memakai quote baru dari server tanpa mengirim harga dan menyegarkan cuplikan keranjang`() =
        runTest(mainRule.dispatcher) {
            // Harga server berbeda dari cuplikan di keranjang (Rp15.000).
            checkout.prices[31] = BigDecimal("16000.00")
            val viewModel = viewModel()

            openReview(viewModel)

            val request = checkout.quoteRequests.single()
            assertEquals(FulfilmentMethod.Pickup, request.method)
            assertNull(request.addressId)
            assertEquals(listOf(CheckoutItem(31, 2), CheckoutItem(32, 1)), request.items)
            assertEquals(account.name, request.contactName)
            assertEquals(account.phone, request.contactPhone)
            assertNull(request.notes)

            val ready = viewModel.ready()
            // 2 x 16000.00 + 25000.00, dari server; bukan hitungan perangkat.
            assertEquals(BigDecimal("57000.00"), ready.quote.total)
            assertEquals(ReviewNotice.PricesUpdated, ready.notice)
            assertFalse(ready.placing)
            assertEquals(BigDecimal("16000.00"), cart.current.first { it.variantId == 31L }.unitPrice)
            assertEquals("Penyegaran cuplikan tidak memicu quote kedua", 1, checkout.quoteRequests.size)
            assertTrue(checkout.placements.isEmpty())
        }

    @Test
    fun `kurir mengirim alamat terpilih dan ongkos kirim menunggu konfirmasi toko`() = runTest(mainRule.dispatcher) {
        val viewModel = viewModel()

        openReview(viewModel, FulfilmentMethod.Courier)

        assertEquals(5L, checkout.quoteRequests.single().addressId)
        assertTrue(viewModel.ready().quote.shippingCostPending)
        assertNull(viewModel.ready().notice)
    }

    @Test
    fun `setiap masuk ke ringkasan meminta quote baru dan jawaban quote yang ditinggalkan dibuang`() =
        runTest(mainRule.dispatcher) {
            val viewModel = viewModel()
            openReview(viewModel)
            assertEquals(1, checkout.quoteRequests.size)

            viewModel.back()
            assertEquals(CheckoutStep.Details, viewModel.form().step)
            assertEquals(ReviewState.Idle, viewModel.form().review)

            val gate = CompletableDeferred<Unit>()
            checkout.quoteGate = gate
            viewModel.continueToReview()
            runCurrent()
            assertEquals(ReviewState.LoadingQuote, viewModel.form().review)
            assertEquals(2, checkout.quoteRequests.size)

            // Mundur saat quote masih berjalan: jawabannya tidak boleh menimpa state.
            viewModel.back()
            gate.complete(Unit)
            runCurrent()
            assertEquals(CheckoutStep.Details, viewModel.form().step)
            assertEquals(ReviewState.Idle, viewModel.form().review)
        }

    @Test
    fun `quote yang gagal dapat dicoba lagi dan toko tidak tersedia tampil sebagai galat toko`() =
        runTest(mainRule.dispatcher) {
            checkout.quoteOutcomes += QuoteOutcome.Failure(ApiError.Network)
            checkout.quoteOutcomes += QuoteOutcome.Failure(
                ApiError.Http(503, ApiErrorCodes.CUSTOMER_COMMERCE_DISABLED, retryAfterSeconds = 3600),
            )
            val viewModel = viewModel()

            openReview(viewModel)
            assertEquals(ReviewState.QuoteFailed(ApiError.Network), viewModel.form().review)

            viewModel.retryQuote()
            runCurrent()
            assertTrue((viewModel.form().review as ReviewState.QuoteFailed).error.isStoreUnavailable)

            viewModel.retryQuote()
            runCurrent()
            assertEquals(BigDecimal("55000.00"), viewModel.ready().quote.total)
            assertEquals(3, checkout.quoteRequests.size)
        }

    @Test
    fun `cart_invalid pada quote menandai baris di keranjang dan mengarahkan ke perbaikan keranjang`() =
        runTest(mainRule.dispatcher) {
            checkout.quoteOutcomes += QuoteOutcome.CartInvalid(
                listOf(
                    CartIssue(31, CartIssueReason.InsufficientStock, maxQuantity = 1),
                    CartIssue(32, CartIssueReason.Unavailable, maxQuantity = 0),
                ),
            )
            val viewModel = viewModel()

            openReview(viewModel)

            val invalid = viewModel.form().review as ReviewState.CartInvalid
            assertEquals(
                listOf(
                    CartIssueLine(31, "Gantungan Kunci Uji — Kecil", CartIssueReason.InsufficientStock, 1),
                    CartIssueLine(32, "Stiker Uji — A5", CartIssueReason.Unavailable, 0),
                ),
                invalid.lines,
            )
            // Jumlah pilihan pelanggan tidak diubah; hanya batasnya, supaya Keranjang menandai barisnya.
            assertEquals(listOf(2, 1), cart.current.map { it.quantity })
            assertEquals(listOf(1, 0), cart.current.map { it.maxQuantity })

            viewModel.events.test {
                viewModel.fixCart()
                assertEquals(CheckoutEvent.BackToCart, awaitItem())
            }
        }

    @Test
    fun `galat validasi server untuk alamat mengembalikan ke langkah dua dengan galat di tempatnya`() =
        runTest(mainRule.dispatcher) {
            checkout.quoteOutcomes += QuoteOutcome.Failure(
                ApiError.Http(
                    status = 422,
                    code = ApiErrorCodes.VALIDATION_FAILED,
                    fieldErrors = mapOf(
                        "address_id" to listOf("Alamat tidak ditemukan."),
                        "contact.phone" to listOf("Nomor telepon wajib diisi."),
                    ),
                ),
            )
            val viewModel = viewModel()

            openReview(viewModel, FulfilmentMethod.Courier)

            val form = viewModel.form()
            assertEquals(CheckoutStep.Details, form.step)
            assertTrue(form.addressMissing)
            assertEquals(CheckoutFieldError.Server("Nomor telepon wajib diisi."), form.contactPhoneError)
            assertEquals(ReviewState.Idle, form.review)
        }

    // --- membuat pesanan ---

    @Test
    fun `pesanan berhasil mengirim kunci dan total quote lalu baru mengosongkan keranjang`() =
        runTest(mainRule.dispatcher) {
            val viewModel = viewModel()
            openReview(viewModel)
            val gate = CompletableDeferred<Unit>()
            checkout.placeGate = gate

            viewModel.events.test {
                viewModel.placeOrder()
                runCurrent()

                val sent = checkout.placements.single()
                assertEquals("kunci-uji-0001-abcdefgh", sent.key)
                assertEquals(BigDecimal("55000.00"), sent.expectedTotal)
                assertEquals(listOf(CheckoutItem(31, 2), CheckoutItem(32, 1)), sent.request.items)
                assertTrue(viewModel.ready().placing)
                assertEquals("Keranjang tidak dikosongkan sebelum server menjawab", 2, cart.current.size)
                expectNoEvents()

                gate.complete(Unit)
                runCurrent()

                assertEquals(CheckoutEvent.OrderPlaced(CheckoutTestData.ORDER_NUMBER), awaitItem())
                assertEquals(CheckoutUiState.Placed(CheckoutTestData.ORDER_NUMBER), viewModel.uiState.value)
                assertTrue(cart.current.isEmpty())
            }
            assertEquals(1, generatedKeys)

            // Layar dipulihkan setelah berhasil: tetap "pesanan dibuat", bukan keranjang kosong.
            val restored = viewModel(savedState)
            runCurrent()
            assertEquals(CheckoutUiState.Placed(CheckoutTestData.ORDER_NUMBER), restored.uiState.value)
        }

    @Test
    fun `ketukan ganda pada Buat pesanan mengirim tepat satu permintaan`() = runTest(mainRule.dispatcher) {
        val viewModel = viewModel()
        openReview(viewModel)
        val gate = CompletableDeferred<Unit>()
        checkout.placeGate = gate

        viewModel.placeOrder()
        viewModel.placeOrder()
        runCurrent()
        viewModel.placeOrder()
        runCurrent()

        assertEquals(1, checkout.placements.size)
        assertTrue(viewModel.form().submitting)
        // Selama dikirim, mundur tidak berlaku.
        viewModel.back()
        assertEquals(CheckoutStep.Review, viewModel.form().step)

        gate.complete(Unit)
        runCurrent()
        assertEquals(1, checkout.placements.size)
        assertEquals(CheckoutUiState.Placed(CheckoutTestData.ORDER_NUMBER), viewModel.uiState.value)
    }

    @Test
    fun `kunci dibuat sekali per niat dan dipakai ulang pada setiap percobaan ulang`() = runTest(mainRule.dispatcher) {
        checkout.placeOutcomes += PlaceOrderOutcome.Failure(tooManyRequests)
        checkout.placeOutcomes += PlaceOrderOutcome.Failure(tooManyRequests)
        val viewModel = viewModel()
        openReview(viewModel)

        viewModel.placeOrder()
        runCurrent()
        // Penolakan pasti (429): pesanan tidak dibuat, pesan tampil, tidak ada pengiriman ulang otomatis.
        assertEquals(tooManyRequests, viewModel.ready().submitError)
        assertFalse(viewModel.ready().placing)
        assertEquals(1, checkout.placements.size)
        assertEquals(2, cart.current.size)

        viewModel.placeOrder()
        runCurrent()
        viewModel.placeOrder()
        runCurrent()

        assertEquals(3, checkout.placements.size)
        assertEquals(listOf("kunci-uji-0001-abcdefgh"), checkout.placements.map { it.key }.distinct())
        assertEquals(1, generatedKeys)
        assertEquals(CheckoutUiState.Placed(CheckoutTestData.ORDER_NUMBER), viewModel.uiState.value)
    }

    @Test
    fun `kunci baru dipakai setelah isi keranjang berubah`() = runTest(mainRule.dispatcher) {
        checkout.placeOutcomes += PlaceOrderOutcome.Failure(tooManyRequests)
        val viewModel = viewModel()
        openReview(viewModel)
        viewModel.placeOrder()
        runCurrent()

        // Keranjang berubah: rincian dihitung ulang dan ini niat yang berbeda.
        cart.setQuantity(31, 3)
        runCurrent()
        assertEquals(BigDecimal("70000.00"), viewModel.ready().quote.total)
        assertNull("Galat percobaan lama tidak ikut ke rincian baru", viewModel.ready().submitError)
        viewModel.placeOrder()
        runCurrent()

        val keys = checkout.placements.map { it.key }
        assertEquals(listOf("kunci-uji-0001-abcdefgh", "kunci-uji-0002-abcdefgh"), keys)
        assertEquals(BigDecimal("70000.00"), checkout.placements.last().expectedTotal)
        assertEquals(listOf(CheckoutItem(31, 3), CheckoutItem(32, 1)), checkout.placements.last().request.items)
    }

    @Test
    fun `kunci baru dipakai setelah metode atau alamat berubah tetapi tidak untuk percobaan ulang yang sama`() =
        runTest(mainRule.dispatcher) {
            repeat(3) { checkout.placeOutcomes += PlaceOrderOutcome.Failure(tooManyRequests) }
            val viewModel = viewModel()
            openReview(viewModel)
            viewModel.placeOrder()
            runCurrent()

            // Metode diganti menjadi kurir.
            viewModel.back()
            viewModel.back()
            viewModel.selectMethod(FulfilmentMethod.Courier)
            viewModel.continueFromMethod()
            runCurrent()
            viewModel.continueToReview()
            runCurrent()
            viewModel.placeOrder()
            runCurrent()

            // Alamat diganti.
            viewModel.back()
            viewModel.selectAddress(6)
            viewModel.continueToReview()
            runCurrent()
            viewModel.placeOrder()
            runCurrent()

            // Percobaan ulang tanpa perubahan apa pun.
            viewModel.placeOrder()
            runCurrent()

            assertEquals(
                listOf(
                    "kunci-uji-0001-abcdefgh",
                    "kunci-uji-0002-abcdefgh",
                    "kunci-uji-0003-abcdefgh",
                    "kunci-uji-0003-abcdefgh",
                ),
                checkout.placements.map { it.key },
            )
            assertEquals(listOf(null, 5L, 6L, 6L), checkout.placements.map { it.request.addressId })
        }

    @Test
    fun `price_changed menampilkan quote baru dan menunggu konfirmasi ulang dengan kunci yang sama`() =
        runTest(mainRule.dispatcher) {
            val newQuote = CheckoutTestData.quote(keychainPrice = "17500.00")
            checkout.placeOutcomes += PlaceOrderOutcome.PriceChanged(newQuote)
            val viewModel = viewModel()
            openReview(viewModel)

            viewModel.placeOrder()
            runCurrent()

            val ready = viewModel.ready()
            assertEquals(newQuote, ready.quote)
            assertEquals(PriceChange(BigDecimal("55000.00"), BigDecimal("60000.00")), ready.priceChange)
            assertFalse(ready.placing)
            assertEquals("Tidak ada pengiriman ulang otomatis", 1, checkout.placements.size)
            assertEquals("Pesanan belum dibuat: keranjang utuh", 2, cart.current.size)
            assertEquals(BigDecimal("17500.00"), cart.current.first { it.variantId == 31L }.unitPrice)

            viewModel.dismissPriceChange()
            assertNull(viewModel.ready().priceChange)
            assertEquals(1, checkout.placements.size)

            // Konfirmasi eksplisit: total baru, kunci tetap (isi pesanan sama, hanya harganya berubah).
            viewModel.placeOrder()
            runCurrent()
            val second = checkout.placements.last()
            assertEquals(BigDecimal("60000.00"), second.expectedTotal)
            assertEquals(checkout.placements.first().key, second.key)
            assertEquals(CheckoutUiState.Placed(CheckoutTestData.ORDER_NUMBER), viewModel.uiState.value)
        }

    @Test
    fun `price_changed tanpa quote yang terbaca meminta quote baru dari server`() = runTest(mainRule.dispatcher) {
        checkout.placeOutcomes += PlaceOrderOutcome.PriceChanged(quote = null)
        val viewModel = viewModel()
        openReview(viewModel)
        checkout.prices[32] = BigDecimal("30000.00")

        viewModel.placeOrder()
        runCurrent()

        assertEquals(2, checkout.quoteRequests.size)
        assertEquals(PriceChange(BigDecimal("55000.00"), BigDecimal("60000.00")), viewModel.ready().priceChange)
        assertEquals(1, checkout.placements.size)
    }

    @Test
    fun `cart_invalid saat membuat pesanan menandai baris dan tidak mengosongkan keranjang`() =
        runTest(mainRule.dispatcher) {
            checkout.placeOutcomes += PlaceOrderOutcome.CartInvalid(
                listOf(CartIssue(31, CartIssueReason.QuantityLimit, maxQuantity = 1)),
            )
            val viewModel = viewModel()
            openReview(viewModel)

            viewModel.placeOrder()
            runCurrent()

            val invalid = viewModel.form().review as ReviewState.CartInvalid
            assertEquals(CartIssueReason.QuantityLimit, invalid.lines.single().reason)
            assertEquals(2, cart.current.size)
            assertEquals(1, cart.current.first { it.variantId == 31L }.maxQuantity)
            // "Buat pesanan" tidak tersedia sampai keranjang diperbaiki.
            viewModel.placeOrder()
            runCurrent()
            assertEquals(1, checkout.placements.size)
        }

    @Test
    fun `idempotency_key_reused memulai niat baru yang harus dikonfirmasi ulang`() = runTest(mainRule.dispatcher) {
        checkout.placeOutcomes += PlaceOrderOutcome.IdempotencyKeyReused
        val viewModel = viewModel()
        openReview(viewModel)

        viewModel.placeOrder()
        runCurrent()

        assertEquals(ReviewNotice.ConfirmAgain, viewModel.ready().notice)
        assertEquals(2, checkout.quoteRequests.size)
        assertEquals("Tidak dikirim ulang sendiri dengan kunci baru", 1, checkout.placements.size)
        assertEquals(2, cart.current.size)

        viewModel.placeOrder()
        runCurrent()
        assertEquals(
            listOf("kunci-uji-0001-abcdefgh", "kunci-uji-0002-abcdefgh"),
            checkout.placements.map { it.key },
        )
    }

    @Test
    fun `timeout setelah dikirim menjadi status belum diketahui dan periksa ulang memakai kunci yang sama`() =
        runTest(mainRule.dispatcher) {
            checkout.placeOutcomes += PlaceOrderOutcome.Failure(ApiError.Timeout)
            val viewModel = viewModel()
            openReview(viewModel)

            viewModel.placeOrder()
            runCurrent()

            val unknown = viewModel.form().review as ReviewState.StatusUnknown
            assertFalse(unknown.checking)
            assertTrue(viewModel.form().statusUnknown)
            assertEquals("Tidak ada pengiriman ulang otomatis", 1, checkout.placements.size)
            assertEquals("Keranjang tidak dikosongkan selama hasilnya belum pasti", 2, cart.current.size)
            // Tidak ada jalan untuk membuat pesanan baru atau mundur dari keadaan ini.
            viewModel.placeOrder()
            viewModel.back()
            runCurrent()
            assertEquals(1, checkout.placements.size)
            assertEquals(CheckoutStep.Review, viewModel.form().step)

            val gate = CompletableDeferred<Unit>()
            checkout.placeGate = gate
            viewModel.retryUnknownStatus()
            viewModel.retryUnknownStatus()
            runCurrent()
            assertTrue((viewModel.form().review as ReviewState.StatusUnknown).checking)
            assertEquals("Ketukan ganda pada periksa status mengirim satu permintaan", 2, checkout.placements.size)

            val (first, retry) = checkout.placements
            assertEquals(first.key, retry.key)
            assertEquals(first.expectedTotal, retry.expectedTotal)
            assertEquals(first.request, retry.request)
            assertEquals(1, generatedKeys)

            // Server memutar ulang pesanan yang sudah tercatat (200): baru sekarang keranjang dikosongkan.
            gate.complete(Unit)
            runCurrent()
            assertEquals(CheckoutUiState.Placed(CheckoutTestData.ORDER_NUMBER), viewModel.uiState.value)
            assertTrue(cart.current.isEmpty())
        }

    @Test
    fun `koneksi putus jawaban tak terbaca dan galat server tidak pernah dilaporkan sebagai gagal`() =
        runTest(mainRule.dispatcher) {
            val uncertain = listOf(
                ApiError.Network,
                ApiError.Unknown,
                ApiError.Http(500, ApiErrorCodes.SERVER_ERROR),
                ApiError.Http(503, ApiErrorCodes.SERVER_ERROR),
            )
            uncertain.forEach { checkout.placeOutcomes += PlaceOrderOutcome.Failure(it) }
            val viewModel = viewModel()
            openReview(viewModel)

            viewModel.placeOrder()
            runCurrent()
            assertTrue(viewModel.form().statusUnknown)
            repeat(uncertain.size - 1) {
                viewModel.retryUnknownStatus()
                runCurrent()
                assertTrue(viewModel.form().statusUnknown)
            }

            assertEquals(1, checkout.placements.map { it.key }.distinct().size)
            assertEquals(2, cart.current.size)

            // Akhirnya server menjawab pasti (mis. harga berubah): kembali ke ringkasan untuk dikonfirmasi.
            checkout.placeOutcomes += PlaceOrderOutcome.PriceChanged(CheckoutTestData.quote(keychainPrice = "17500.00"))
            viewModel.retryUnknownStatus()
            runCurrent()
            assertEquals(BigDecimal("60000.00"), viewModel.ready().quote.total)
            assertEquals(5, checkout.placements.size)
        }

    @Test
    fun `proses mati saat pesanan dikirim dipulihkan sebagai status belum diketahui dengan kunci tersimpan`() =
        runTest(mainRule.dispatcher) {
            val first = viewModel(savedState)
            openReview(first)
            // Jawaban tidak pernah datang: proses dimatikan sistem saat permintaan berjalan.
            checkout.placeGate = CompletableDeferred()
            first.placeOrder()
            runCurrent()

            checkout.placeGate = null
            val restored = viewModel(savedState)
            runCurrent()

            val form = restored.form()
            assertEquals(CheckoutStep.Review, form.step)
            assertEquals(FulfilmentMethod.Pickup, form.method)
            assertEquals(ReviewState.StatusUnknown(quote = null), form.review)
            assertEquals("Tidak meminta quote baru seolah belum pernah mengirim", 1, checkout.quoteRequests.size)

            restored.retryUnknownStatus()
            runCurrent()

            val (original, retry) = checkout.placements
            assertEquals(original.key, retry.key)
            assertEquals(original.expectedTotal, retry.expectedTotal)
            assertEquals(original.request, retry.request)
            assertEquals(CheckoutUiState.Placed(CheckoutTestData.ORDER_NUMBER), restored.uiState.value)
        }

    @Test
    fun `sesi berakhir saat membuat pesanan mempertahankan keranjang dan isian lalu berlanjut setelah masuk lagi`() =
        runTest(mainRule.dispatcher) {
            checkout.placeOutcomes += PlaceOrderOutcome.Failure(ApiError.Http(401, ApiErrorCodes.UNAUTHENTICATED))
            val viewModel = viewModel()
            runCurrent()
            viewModel.selectMethod(FulfilmentMethod.Pickup)
            viewModel.continueFromMethod()
            viewModel.onNotesChange("Tolong dibungkus rapi.")
            viewModel.continueToReview()
            runCurrent()

            viewModel.placeOrder()
            runCurrent()
            // Lapisan sesi menghapus sesi (401); checkout tidak menampilkan galat sendiri.
            session.expire()
            runCurrent()
            assertEquals(CheckoutUiState.SignInRequired, viewModel.uiState.value)
            assertEquals(2, cart.current.size)

            session.startSession("tes-token-palsu-456", expiresAt = null, account = account)
            runCurrent()

            val form = viewModel.form()
            assertEquals(CheckoutStep.Review, form.step)
            assertEquals("Tolong dibungkus rapi.", form.notes)
            assertEquals(BigDecimal("55000.00"), viewModel.ready().quote.total)
            // 401 adalah penolakan pasti, bukan "status belum diketahui"; niatnya sama, kuncinya sama.
            viewModel.placeOrder()
            runCurrent()
            assertEquals(1, checkout.placements.map { it.key }.distinct().size)
            assertEquals(CheckoutUiState.Placed(CheckoutTestData.ORDER_NUMBER), viewModel.uiState.value)
        }

    @Test
    fun `akun lain yang masuk tidak mewarisi isian dan kunci akun sebelumnya`() = runTest(mainRule.dispatcher) {
        checkout.placeOutcomes += PlaceOrderOutcome.Failure(tooManyRequests)
        val viewModel = viewModel()
        openReview(viewModel)
        viewModel.placeOrder()
        runCurrent()

        session.expire()
        runCurrent()
        val other = TestData.account(id = 2, name = "Pelanggan Lain", email = "pelanggan.lain@example.test", phone = null)
        session.startSession("tes-token-palsu-789", expiresAt = null, account = other)
        runCurrent()

        val form = viewModel.form()
        assertEquals(CheckoutStep.Method, form.step)
        assertNull(form.method)
        assertEquals("Pelanggan Lain", form.contactName)
        assertEquals("", form.contactPhone)

        viewModel.selectMethod(FulfilmentMethod.Pickup)
        viewModel.continueFromMethod()
        viewModel.onContactPhoneChange("0800-1111-1111")
        viewModel.continueToReview()
        runCurrent()
        viewModel.placeOrder()
        runCurrent()
        assertNotEquals(checkout.placements.first().key, checkout.placements.last().key)
    }

    @Test
    fun `toko tidak tersedia saat membuat pesanan tampil sebagai galat toko tanpa mengosongkan keranjang`() =
        runTest(mainRule.dispatcher) {
            checkout.placeOutcomes += PlaceOrderOutcome.Failure(
                ApiError.Http(503, ApiErrorCodes.CUSTOMER_COMMERCE_DISABLED, retryAfterSeconds = 3600),
            )
            checkout.placeOutcomes += PlaceOrderOutcome.Failure(ApiError.Http(423, ApiErrorCodes.STORE_LOCKED))
            val viewModel = viewModel()
            openReview(viewModel)

            viewModel.placeOrder()
            runCurrent()
            assertTrue((viewModel.form().review as ReviewState.QuoteFailed).error.isStoreUnavailable)
            assertFalse(viewModel.form().statusUnknown)
            assertEquals(2, cart.current.size)

            // "Coba lagi" meminta quote baru; pesanan hanya dikirim lagi oleh ketukan eksplisit.
            viewModel.retryQuote()
            runCurrent()
            assertEquals(1, checkout.placements.size)
            viewModel.placeOrder()
            runCurrent()
            assertTrue((viewModel.form().review as ReviewState.QuoteFailed).error.isStoreUnavailable)
            assertEquals(checkout.placements.first().key, checkout.placements.last().key)
        }

    // --- niat tertunda di luar layar checkout ---

    @Test
    fun `meninggalkan checkout saat status belum diketahui lalu memesan isi yang sama memakai kunci yang sama`() =
        runTest(mainRule.dispatcher) {
            checkout.placeOutcomes += PlaceOrderOutcome.Failure(ApiError.Timeout)
            val first = viewModel(SavedStateHandle())
            openReview(first)
            first.placeOrder()
            runCurrent()
            assertTrue(first.form().statusUnknown)
            assertEquals("Niat dicatat sebelum dikirim", "kunci-uji-0001-abcdefgh", pendingIntents.entry?.key)

            // Kunjungan checkout baru (SavedStateHandle kosong), isi pesanan sama persis.
            val second = viewModel(SavedStateHandle())
            openReview(second)
            second.placeOrder()
            runCurrent()

            assertEquals(listOf("kunci-uji-0001-abcdefgh"), checkout.placements.map { it.key }.distinct())
            assertEquals(1, generatedKeys)
            assertEquals(CheckoutUiState.Placed(CheckoutTestData.ORDER_NUMBER), second.uiState.value)
            assertNull("Jawaban pasti melupakan niat tertunda", pendingIntents.entry)
        }

    @Test
    fun `niat tertunda tidak dipakai untuk isi lain atau milik akun lain`() =
        runTest(mainRule.dispatcher) {
            checkout.placeOutcomes += PlaceOrderOutcome.Failure(ApiError.Network)
            checkout.placeOutcomes += PlaceOrderOutcome.Failure(ApiError.Network)
            val first = viewModel(SavedStateHandle())
            openReview(first)
            first.placeOrder()
            runCurrent()
            val firstFingerprint = pendingIntents.entry?.fingerprint

            // Isi lain (catatan berbeda) adalah niat lain: kunci baru, dan niat tertundanya menggantikan yang lama.
            val other = viewModel(SavedStateHandle())
            runCurrent()
            other.selectMethod(FulfilmentMethod.Pickup)
            other.continueFromMethod()
            other.onNotesChange("Tolong dibungkus rapi.")
            other.continueToReview()
            runCurrent()
            other.placeOrder()
            runCurrent()
            assertEquals(listOf("kunci-uji-0001-abcdefgh", "kunci-uji-0002-abcdefgh"), checkout.placements.map { it.key })
            assertEquals("kunci-uji-0002-abcdefgh", pendingIntents.entry?.key)

            // Kunci tertunda milik akun lain dengan sidik yang sama tidak pernah dipakai akun ini.
            pendingIntents.entry = FakePendingOrderIntentStore.Entry(
                accountId = account.id + 100,
                fingerprint = requireNotNull(firstFingerprint),
                key = "kunci-akun-lain-abcdefgh",
            )
            val third = viewModel(SavedStateHandle())
            openReview(third)
            third.placeOrder()
            runCurrent()
            assertEquals("kunci-uji-0003-abcdefgh", checkout.placements.last().key)
            assertEquals(CheckoutUiState.Placed(CheckoutTestData.ORDER_NUMBER), third.uiState.value)
        }

    @Test
    fun `penyimpanan niat yang rusak tidak menghalangi pemesanan`() = runTest(mainRule.dispatcher) {
        pendingIntents.failing = true
        val viewModel = viewModel()
        openReview(viewModel)

        viewModel.placeOrder()
        runCurrent()

        assertEquals(1, checkout.placements.size)
        assertEquals(CheckoutUiState.Placed(CheckoutTestData.ORDER_NUMBER), viewModel.uiState.value)
    }

    // --- penolakan middleware vs jawaban handler (niat tertunda) ---

    /**
     * Skenario celah: pesanan tercatat tetapi jawabannya hilang ("status belum diketahui"), lalu
     * "Periksa status pesanan" DITOLAK [rejection]. Penolakan middleware terjadi SEBELUM pencarian
     * kunci idempotensi di server, jadi tidak mengatakan apa pun tentang percobaan pertama: niat
     * tertunda harus tetap tersimpan, dan kunjungan checkout berikutnya dengan isi yang sama harus
     * memakai kunci yang SAMA (server memutar ulang pesanan pertama), bukan kunci baru.
     */
    private fun TestScope.assertRejectionKeepsPendingIntent(
        rejection: ApiError,
        expectedReview: (ReviewState) -> Boolean,
    ) {
        checkout.placeOutcomes += PlaceOrderOutcome.Failure(ApiError.Timeout)
        checkout.placeOutcomes += PlaceOrderOutcome.Failure(rejection)
        val first = viewModel(SavedStateHandle())
        openReview(first)
        first.placeOrder()
        runCurrent()
        assertTrue(first.form().statusUnknown)

        first.retryUnknownStatus()
        runCurrent()

        assertEquals(2, checkout.placements.size)
        val review = first.form().review
        assertTrue("Tampilan per kasus tidak berubah: $review", expectedReview(review))
        assertEquals("Niat tertunda tetap tersimpan", "kunci-uji-0001-abcdefgh", pendingIntents.entry?.key)

        // Meninggalkan checkout lalu kembali dengan isi yang sama.
        val second = viewModel(SavedStateHandle())
        openReview(second)
        second.placeOrder()
        runCurrent()

        assertEquals(
            "Kunci yang sama dipakai ulang, tidak ada kunci baru",
            setOf("kunci-uji-0001-abcdefgh"),
            checkout.placements.map { it.key }.toSet(),
        )
        assertEquals(1, generatedKeys)
        assertEquals(CheckoutUiState.Placed(CheckoutTestData.ORDER_NUMBER), second.uiState.value)
        assertNull("Jawaban pasti (berhasil) baru melupakan niat", pendingIntents.entry)
    }

    private fun isReadyWithSubmitError(error: ApiError): (ReviewState) -> Boolean =
        { review -> (review as? ReviewState.Ready)?.submitError == error }

    @Test
    fun `401 pada periksa status tidak melupakan niat tertunda dan sesi berakhir tetap menjaga isian`() =
        runTest(mainRule.dispatcher) {
            assertRejectionKeepsPendingIntent(ApiError.Http(401, ApiErrorCodes.UNAUTHENTICATED)) { it == ReviewState.Idle }
        }

    @Test
    fun `403 pada periksa status tidak melupakan niat tertunda`() = runTest(mainRule.dispatcher) {
        val forbidden = ApiError.Http(403, ApiErrorCodes.FORBIDDEN)
        assertRejectionKeepsPendingIntent(forbidden, isReadyWithSubmitError(forbidden))
    }

    @Test
    fun `404 pada periksa status tidak melupakan niat tertunda`() = runTest(mainRule.dispatcher) {
        val notFound = ApiError.Http(404, ApiErrorCodes.NOT_FOUND)
        assertRejectionKeepsPendingIntent(notFound, isReadyWithSubmitError(notFound))
    }

    @Test
    fun `413 payload_too_large tidak melupakan niat tertunda dan tampil sebagai galat ringkasan`() =
        runTest(mainRule.dispatcher) {
            // Kode baru dari server yang tak dikenal aplikasi: diperlakukan umum menurut statusnya (4xx).
            val tooLarge = ApiError.Http(413, "payload_too_large", detail = "Isi permintaan terlalu besar.")
            assertRejectionKeepsPendingIntent(tooLarge, isReadyWithSubmitError(tooLarge))
        }

    @Test
    fun `413 tanpa problem json juga tidak melupakan niat tertunda`() = runTest(mainRule.dispatcher) {
        val bare = ApiError.Http(413, ApiErrorCodes.HTTP_ERROR)
        assertRejectionKeepsPendingIntent(bare, isReadyWithSubmitError(bare))
    }

    @Test
    fun `423 terkunci tidak melupakan niat tertunda dan tampil sebagai toko tidak tersedia`() =
        runTest(mainRule.dispatcher) {
            val locked = ApiError.Http(423, ApiErrorCodes.STORE_LOCKED)
            assertRejectionKeepsPendingIntent(locked) { (it as? ReviewState.QuoteFailed)?.error == locked }
        }

    @Test
    fun `423 installation_locked tidak melupakan niat tertunda`() = runTest(mainRule.dispatcher) {
        val locked = ApiError.Http(423, "installation_locked")
        assertRejectionKeepsPendingIntent(locked) { (it as? ReviewState.QuoteFailed)?.error == locked }
    }

    @Test
    fun `429 pada periksa status tidak melupakan niat tertunda`() = runTest(mainRule.dispatcher) {
        assertRejectionKeepsPendingIntent(tooManyRequests, isReadyWithSubmitError(tooManyRequests))
    }

    @Test
    fun `503 fitur dimatikan tidak melupakan niat tertunda dan tampil sebagai toko tidak tersedia`() =
        runTest(mainRule.dispatcher) {
            val disabled = ApiError.Http(503, ApiErrorCodes.CUSTOMER_COMMERCE_DISABLED, retryAfterSeconds = 3600)
            assertRejectionKeepsPendingIntent(disabled) { (it as? ReviewState.QuoteFailed)?.error == disabled }
        }

    @Test
    fun `status di luar daftar jawaban handler tidak melupakan niat tertunda`() = runTest(mainRule.dispatcher) {
        // 400 umum: bukan salah satu jawaban pasti yang dikenal (409/422 dengan kode tertentu).
        val badRequest = ApiError.Http(400, ApiErrorCodes.HTTP_ERROR)
        assertRejectionKeepsPendingIntent(badRequest, isReadyWithSubmitError(badRequest))
    }

    @Test
    fun `409 dan 422 dengan kode yang tidak dikenal tidak melupakan niat tertunda`() = runTest(mainRule.dispatcher) {
        // Mis. 409 "permintaan dengan kunci ini sedang diproses": justru menandakan percobaan sebelumnya ada.
        val inProgress = ApiError.Http(409, "idempotency_in_progress")
        assertRejectionKeepsPendingIntent(inProgress, isReadyWithSubmitError(inProgress))
    }

    @Test
    fun `422 dengan kode tak dikenal tidak melupakan niat tertunda`() = runTest(mainRule.dispatcher) {
        val unknown = ApiError.Http(422, "kode_baru_dari_server")
        assertRejectionKeepsPendingIntent(unknown, isReadyWithSubmitError(unknown))
    }

    /** Status belum diketahui, lalu server (handler) menjawab PASTI: niat tertunda dilupakan. */
    private fun TestScope.assertHandlerAnswerForgetsPendingIntent(answer: PlaceOrderOutcome) {
        checkout.placeOutcomes += PlaceOrderOutcome.Failure(ApiError.Timeout)
        checkout.placeOutcomes += answer
        val viewModel = viewModel(SavedStateHandle())
        openReview(viewModel)
        viewModel.placeOrder()
        runCurrent()
        assertEquals("kunci-uji-0001-abcdefgh", pendingIntents.entry?.key)

        viewModel.retryUnknownStatus()
        runCurrent()

        assertNull("Jawaban pasti dari handler melupakan niat tertunda: $answer", pendingIntents.entry)
    }

    @Test
    fun `409 price_changed dari handler melupakan niat tertunda`() =
        runTest(mainRule.dispatcher) {
            assertHandlerAnswerForgetsPendingIntent(PlaceOrderOutcome.PriceChanged(CheckoutTestData.quote(keychainPrice = "17500.00")))
        }

    @Test
    fun `409 cart_invalid dari handler melupakan niat tertunda`() = runTest(mainRule.dispatcher) {
        assertHandlerAnswerForgetsPendingIntent(
            PlaceOrderOutcome.CartInvalid(listOf(CartIssue(31, CartIssueReason.QuantityLimit, maxQuantity = 1))),
        )
    }

    @Test
    fun `409 idempotency_key_reused dari handler melupakan niat tertunda`() = runTest(mainRule.dispatcher) {
        assertHandlerAnswerForgetsPendingIntent(PlaceOrderOutcome.IdempotencyKeyReused)
    }

    @Test
    fun `422 validation_failed dari handler melupakan niat tertunda`() = runTest(mainRule.dispatcher) {
        assertHandlerAnswerForgetsPendingIntent(
            PlaceOrderOutcome.Failure(
                ApiError.Http(422, ApiErrorCodes.VALIDATION_FAILED, fieldErrors = mapOf("contact.phone" to listOf("Nomor tidak valid."))),
            ),
        )
    }

    @Test
    fun `422 idempotency_key_required dari handler melupakan niat tertunda dan kuncinya`() = runTest(mainRule.dispatcher) {
        assertHandlerAnswerForgetsPendingIntent(
            PlaceOrderOutcome.Failure(ApiError.Http(422, ApiErrorCodes.IDEMPOTENCY_KEY_REQUIRED)),
        )
    }

    @Test
    fun `penolakan pertama tanpa percobaan sebelumnya juga menyimpan niat tanpa mengubah tampilan`() =
        runTest(mainRule.dispatcher) {
            // 429 pada percobaan PERTAMA: tidak ada yang tercatat di server; menyimpan kunci tidak merugikan
            // (kunci yang sama dipakai ulang oleh pesanan berikutnya, yang memang belum ada).
            checkout.placeOutcomes += PlaceOrderOutcome.Failure(tooManyRequests)
            val viewModel = viewModel(SavedStateHandle())
            openReview(viewModel)

            viewModel.placeOrder()
            runCurrent()

            assertEquals(tooManyRequests, viewModel.ready().submitError)
            assertFalse(viewModel.form().statusUnknown)
            assertEquals("kunci-uji-0001-abcdefgh", pendingIntents.entry?.key)
            viewModel.placeOrder()
            runCurrent()
            assertEquals(1, checkout.placements.map { it.key }.distinct().size)
            assertEquals(CheckoutUiState.Placed(CheckoutTestData.ORDER_NUMBER), viewModel.uiState.value)
        }
}
