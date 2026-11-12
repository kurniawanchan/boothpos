package com.boothpos.customer.feature.checkout.presentation

import androidx.lifecycle.SavedStateHandle
import androidx.lifecycle.ViewModel
import androidx.lifecycle.viewModelScope
import com.boothpos.customer.core.data.domain.address.AddressRepository
import com.boothpos.customer.core.data.domain.cart.CartRepository
import com.boothpos.customer.core.data.domain.model.Account
import com.boothpos.customer.core.data.domain.model.CartLine
import com.boothpos.customer.core.data.domain.model.CartLineRefresh
import com.boothpos.customer.core.data.domain.model.FulfilmentMethod
import com.boothpos.customer.core.data.domain.model.subtotal
import com.boothpos.customer.core.data.domain.money.Money
import com.boothpos.customer.core.data.domain.session.SessionRepository
import com.boothpos.customer.core.data.domain.session.SessionState
import com.boothpos.customer.core.data.domain.store.StoreConfigRepository
import com.boothpos.customer.core.network.error.ApiError
import com.boothpos.customer.core.network.error.ApiErrorCodes
import com.boothpos.customer.core.network.error.ApiResult
import com.boothpos.customer.core.network.error.isStoreUnavailable
import com.boothpos.customer.core.network.error.isUnauthenticated
import com.boothpos.customer.feature.checkout.domain.CartIssue
import com.boothpos.customer.feature.checkout.domain.CheckoutFingerprint
import com.boothpos.customer.feature.checkout.domain.CheckoutItem
import com.boothpos.customer.feature.checkout.domain.CheckoutRepository
import com.boothpos.customer.feature.checkout.domain.CheckoutRequest
import com.boothpos.customer.feature.checkout.domain.CheckoutRules
import com.boothpos.customer.feature.checkout.domain.IdempotencyKeyGenerator
import com.boothpos.customer.feature.checkout.domain.PendingOrderIntentStore
import com.boothpos.customer.feature.checkout.domain.PlaceOrderOutcome
import com.boothpos.customer.feature.checkout.domain.PlacedOrder
import com.boothpos.customer.feature.checkout.domain.Quote
import com.boothpos.customer.feature.checkout.domain.QuoteOutcome
import dagger.hilt.android.lifecycle.HiltViewModel
import java.math.BigDecimal
import javax.inject.Inject
import kotlin.coroutines.cancellation.CancellationException
import kotlinx.coroutines.Job
import kotlinx.coroutines.channels.Channel
import kotlinx.coroutines.ensureActive
import kotlinx.coroutines.flow.Flow
import kotlinx.coroutines.flow.MutableStateFlow
import kotlinx.coroutines.flow.StateFlow
import kotlinx.coroutines.flow.asStateFlow
import kotlinx.coroutines.flow.collectLatest
import kotlinx.coroutines.flow.combine
import kotlinx.coroutines.flow.first
import kotlinx.coroutines.flow.receiveAsFlow
import kotlinx.coroutines.flow.update
import kotlinx.coroutines.launch

/**
 * Checkout tiga langkah: cara pengambilan -> alamat & kontak -> ringkasan dari quote server.
 *
 * Aturan yang dijaga di sini (kontrak §8):
 * - angka di ringkasan selalu dari quote server; perangkat tidak menghitung total pesanan;
 * - `Idempotency-Key` dibuat SEKALI per niat checkout, disimpan di SavedStateHandle bersama sidik
 *   isi pesanannya, dan dipakai ulang pada setiap pengiriman ulang niat yang sama. Kunci baru hanya
 *   setelah isi pesanan berubah, setelah berhasil, atau setelah `idempotency_key_reused`;
 * - niat yang sedang dikirim juga dicatat di [PendingOrderIntentStore] sampai HANDLER server
 *   menjawab pasti (berhasil, 409 `price_changed`/`cart_invalid`/`idempotency_key_reused`, 422
 *   `validation_failed`/`idempotency_key_required`; lihat [CheckoutRules.settlesPendingIntent]),
 *   supaya pelanggan yang meninggalkan checkout saat statusnya belum diketahui lalu memesan isi
 *   yang sama tidak membuat pesanan kedua (server memutar ulang pesanan pertama). Penolakan
 *   middleware (401/403/404/413/423/429/503) tidak melupakannya;
 * - tidak ada pengiriman ulang otomatis. Bila jawaban tidak sampai, keadaannya "status belum
 *   diketahui" dan pengiriman ulang oleh pelanggan memakai kunci yang sama;
 * - keranjang hanya dikosongkan setelah server menyatakan pesanan tercatat.
 */
@HiltViewModel
class CheckoutViewModel @Inject constructor(
    private val savedState: SavedStateHandle,
    private val session: SessionRepository,
    private val cart: CartRepository,
    private val storeConfig: StoreConfigRepository,
    private val addresses: AddressRepository,
    private val checkout: CheckoutRepository,
    private val keyGenerator: IdempotencyKeyGenerator,
    private val pendingIntents: PendingOrderIntentStore,
) : ViewModel() {

    // Semua penulisan state terjadi di main thread (viewModelScope), jadi tidak ada penulis serentak.
    private val _uiState = MutableStateFlow<CheckoutUiState>(
        savedState.get<String>(KEY_PLACED)?.let(CheckoutUiState::Placed) ?: CheckoutUiState.Loading,
    )
    val uiState: StateFlow<CheckoutUiState> = _uiState.asStateFlow()

    private val _events = Channel<CheckoutEvent>(Channel.BUFFERED)
    val events: Flow<CheckoutEvent> = _events.receiveAsFlow()

    private var cartLines: List<CartLine> = emptyList()
    private var quoteJob: Job? = null
    private var addressJob: Job? = null

    init {
        viewModelScope.launch {
            combine(session.state, cart.lines) { sessionState, lines -> sessionState to lines }
                .collectLatest { (sessionState, lines) -> onInputs(sessionState, lines) }
        }
    }

    // --- sesi, keranjang, dan pembukaan formulir ---

    private suspend fun onInputs(sessionState: SessionState, lines: List<CartLine>) {
        // Pesanan sudah tercatat: keranjang yang baru dikosongkan bukan "keranjang kosong".
        if (_uiState.value is CheckoutUiState.Placed) return
        val previousItems = cartLines.toItems()
        cartLines = lines
        when (sessionState) {
            SessionState.Loading -> if (form() == null) _uiState.value = CheckoutUiState.Loading
            is SessionState.SignedOut -> {
                quoteJob?.cancel()
                addressJob?.cancel()
                _uiState.value = CheckoutUiState.SignInRequired
                // Sekali saja per kunjungan: kembali dari layar masuk tanpa masuk tidak memantulkan
                // pelanggan ke sana lagi; ia melihat ajakan masuk dengan tombolnya.
                if (savedState.get<Boolean>(KEY_SIGN_IN_PROMPTED) != true) {
                    savedState[KEY_SIGN_IN_PROMPTED] = true
                    _events.send(CheckoutEvent.RequireSignIn)
                }
            }
            is SessionState.SignedIn -> {
                savedState[KEY_SIGN_IN_PROMPTED] = false
                val form = form()
                when {
                    lines.isEmpty() -> if (form == null || !(form.submitting || form.statusUnknown)) {
                        quoteJob?.cancel()
                        addressJob?.cancel()
                        _uiState.value = CheckoutUiState.EmptyCart
                    }
                    form == null -> openForm(sessionState.account)
                    previousItems != lines.toItems() -> onCartItemsChanged(form)
                }
            }
        }
    }

    /** Membangun formulir dari saved state (bila ada) dan profil; dipakai juga setelah masuk lagi. */
    private suspend fun openForm(account: Account) {
        val savedAccountId = savedState.get<Long>(KEY_ACCOUNT_ID)
        if (savedAccountId != null && savedAccountId != account.id) clearSavedForm()
        savedState[KEY_ACCOUNT_ID] = account.id
        if (_uiState.value !is CheckoutUiState.LoadFailed) _uiState.value = CheckoutUiState.Loading

        val config = storeConfig.config.first() ?: when (val result = storeConfig.refresh()) {
            is ApiResult.Success -> result.value
            is ApiResult.Failure -> {
                _uiState.value = CheckoutUiState.LoadFailed(result.error)
                return
            }
        }
        val methods = FulfilmentMethod.entries.filter { it in config.fulfilmentMethods }
        if (methods.isEmpty()) {
            _uiState.value = CheckoutUiState.LoadFailed(ApiError.Unknown)
            return
        }
        val method = FulfilmentMethod.fromWire(savedState.get<String>(KEY_METHOD))?.takeIf { it in methods }
            ?: methods.singleOrNull()
        val savedStep = savedState.get<String>(KEY_STEP)?.let { name -> CheckoutStep.entries.firstOrNull { it.name == name } }
        val step = if (method == null) CheckoutStep.Method else savedStep ?: CheckoutStep.Method
        // Proses dimatikan saat pesanan sedang dikirim: hasilnya tidak diketahui, jadi JANGAN
        // menawarkan "Buat pesanan" baru; tawarkan pengiriman ulang dengan kunci yang tersimpan.
        val resultUnknown = step == CheckoutStep.Review &&
            savedState.get<String>(KEY_PENDING_TOTAL) != null &&
            savedState.get<String>(KEY_INTENT_KEY) != null

        val form = CheckoutUiState.Form(
            step = step,
            methods = methods,
            method = method,
            pickupInstructions = config.pickupInstructions,
            courierInstructions = config.courierInstructions,
            contactName = savedState[KEY_NAME] ?: account.name,
            contactPhone = savedState[KEY_PHONE] ?: account.phone.orEmpty(),
            notes = savedState[KEY_NOTES] ?: "",
            selectedAddressId = savedState.get<Long>(KEY_ADDRESS_ID),
            review = if (resultUnknown) ReviewState.StatusUnknown(quote = null) else ReviewState.Idle,
        )
        _uiState.value = form
        if (form.needsAddress && step != CheckoutStep.Method) loadAddresses()
        if (step == CheckoutStep.Review && !resultUnknown) requestQuote()
    }

    fun retryLoad() {
        val failed = _uiState.value as? CheckoutUiState.LoadFailed ?: return
        if (failed.retrying) return
        val account = (session.state.value as? SessionState.SignedIn)?.account ?: return
        _uiState.value = failed.copy(retrying = true)
        viewModelScope.launch { openForm(account) }
    }

    /** Tombol "Masuk" pada ajakan masuk. */
    fun requestSignIn() {
        _events.trySend(CheckoutEvent.RequireSignIn)
    }

    private fun onCartItemsChanged(form: CheckoutUiState.Form) {
        if (form.submitting || form.statusUnknown) return
        // Rincian yang tampil tidak lagi menggambarkan keranjang: hitung ulang, jangan dipertahankan.
        if (form.step == CheckoutStep.Review) requestQuote() else updateForm { it.copy(review = ReviewState.Idle) }
    }

    // --- langkah 1: cara pengambilan ---

    fun selectMethod(method: FulfilmentMethod) {
        val form = form() ?: return
        if (form.submitting || method !in form.methods) return
        savedState[KEY_METHOD] = method.wireValue
        updateForm { it.copy(method = method, methodMissing = false, review = ReviewState.Idle) }
    }

    fun continueFromMethod() {
        val form = form() ?: return
        if (form.step != CheckoutStep.Method) return
        if (form.method == null) {
            updateForm { it.copy(methodMissing = true) }
            return
        }
        setStep(CheckoutStep.Details)
        if (form.needsAddress) loadAddresses()
    }

    // --- langkah 2: alamat dan kontak ---

    fun onContactNameChange(value: String) {
        savedState[KEY_NAME] = value
        updateForm { it.copy(contactName = value, contactNameError = null) }
    }

    fun onContactPhoneChange(value: String) {
        savedState[KEY_PHONE] = value
        updateForm { it.copy(contactPhone = value, contactPhoneError = null) }
    }

    fun onNotesChange(value: String) {
        savedState[KEY_NOTES] = value
        updateForm { it.copy(notes = value, notesError = null) }
    }

    fun selectAddress(addressId: Long) {
        savedState[KEY_ADDRESS_ID] = addressId
        updateForm { it.copy(selectedAddressId = addressId, addressMissing = false) }
    }

    fun retryAddresses() = loadAddresses()

    /**
     * Dipanggil setiap kali layar checkout tampil lagi (mis. kembali dari formulir alamat): daftar
     * alamat dimuat ulang, dan alamat yang baru saja ditambahkan langsung terpilih.
     */
    fun onScreenShown() {
        val form = form() ?: return
        if (form.needsAddress && form.step != CheckoutStep.Method && !form.submitting) loadAddresses()
    }

    fun continueToReview() {
        val form = form() ?: return
        if (form.step != CheckoutStep.Details) return
        val nameIssue = CheckoutRules.contactName(form.contactName)
        val phoneIssue = CheckoutRules.contactPhone(form.contactPhone)
        val notesIssue = CheckoutRules.notes(form.notes)
        val addressMissing = form.needsAddress && form.selectedAddress == null
        if (nameIssue != null || phoneIssue != null || notesIssue != null || addressMissing) {
            updateForm {
                it.copy(
                    contactNameError = nameIssue?.let(CheckoutFieldError::Local),
                    contactPhoneError = phoneIssue?.let(CheckoutFieldError::Local),
                    notesError = notesIssue?.let(CheckoutFieldError::Local),
                    addressMissing = addressMissing,
                )
            }
            return
        }
        setStep(CheckoutStep.Review)
        requestQuote()
    }

    /** Mundur satu langkah. Tidak berlaku selama pesanan dikirim atau statusnya belum diketahui. */
    fun back() {
        val form = form() ?: return
        if (form.submitting || form.statusUnknown) return
        when (form.step) {
            CheckoutStep.Method -> Unit
            CheckoutStep.Details -> setStep(CheckoutStep.Method)
            CheckoutStep.Review -> {
                quoteJob?.cancel()
                // Rincian dibuang: ringkasan berikutnya selalu memakai quote baru.
                setStep(CheckoutStep.Details)
                updateForm { it.copy(review = ReviewState.Idle) }
            }
        }
    }

    private fun loadAddresses() {
        val form = form() ?: return
        if (addressJob?.isActive == true) return
        if (form.addresses !is AddressesState.Loaded) updateForm { it.copy(addresses = AddressesState.Loading) }
        addressJob = viewModelScope.launch {
            when (val result = addresses.list()) {
                is ApiResult.Success -> updateForm { current ->
                    val loaded = result.value
                    val knownIds = (current.addresses as? AddressesState.Loaded)?.addresses?.map { it.id }?.toSet()
                    val added = if (knownIds == null) emptyList() else loaded.filter { it.id !in knownIds }
                    val selected = when {
                        added.size == 1 -> added.single().id
                        loaded.any { it.id == current.selectedAddressId } -> current.selectedAddressId
                        else -> (loaded.firstOrNull { it.isDefault } ?: loaded.firstOrNull())?.id
                    }
                    savedState[KEY_ADDRESS_ID] = selected
                    current.copy(
                        addresses = AddressesState.Loaded(loaded),
                        selectedAddressId = selected,
                        // Peringatan "pilih alamat" hanya hilang sendiri bila pelanggan baru saja menambah
                        // alamat; pemilihan otomatis lain (mis. alamat lama terhapus) harus tetap terlihat.
                        addressMissing = current.addressMissing && added.size != 1,
                    )
                }
                is ApiResult.Failure ->
                    // 401 ditangani alur sesi; daftar yang sudah tampil tidak diganti galat.
                    if (!result.error.isUnauthenticated) {
                        updateForm {
                            if (it.addresses is AddressesState.Loaded) it else it.copy(addresses = AddressesState.Failed(result.error))
                        }
                    }
            }
        }
    }

    // --- langkah 3: ringkasan (quote) ---

    fun retryQuote() {
        val form = form() ?: return
        if (form.step != CheckoutStep.Review) return
        if (form.review is ReviewState.QuoteFailed || form.review is ReviewState.Idle) requestQuote()
    }

    fun dismissPriceChange() {
        updateForm { form ->
            val ready = form.review as? ReviewState.Ready ?: return@updateForm form
            form.copy(review = ready.copy(priceChange = null))
        }
    }

    /** Tombol "Perbaiki keranjang" pada `cart_invalid`. */
    fun fixCart() {
        _events.trySend(CheckoutEvent.BackToCart)
    }

    private fun requestQuote(notice: ReviewNotice? = null, previousTotal: BigDecimal? = null) {
        val request = buildRequest() ?: return
        val localSubtotal = cartLines.subtotal()
        quoteJob?.cancel()
        updateForm { it.copy(review = ReviewState.LoadingQuote) }
        quoteJob = viewModelScope.launch {
            val outcome = checkout.quote(request)
            // Jawaban untuk permintaan yang sudah digantikan (mundur, keranjang berubah) dibuang.
            ensureActive()
            when (outcome) {
                is QuoteOutcome.Success -> showQuote(
                    quote = outcome.quote,
                    notice = notice
                        ?: ReviewNotice.PricesUpdated.takeIf { localSubtotal.compareTo(outcome.quote.subtotal) != 0 },
                    priceChange = previousTotal
                        ?.takeIf { it.compareTo(outcome.quote.total) != 0 }
                        ?.let { PriceChange(it, outcome.quote.total) },
                )
                is QuoteOutcome.CartInvalid -> showCartInvalid(outcome.issues)
                is QuoteOutcome.Failure -> onQuoteFailure(outcome.error)
            }
        }
    }

    private suspend fun showQuote(quote: Quote, notice: ReviewNotice?, priceChange: PriceChange?) {
        updateForm { it.copy(review = ReviewState.Ready(quote = quote, notice = notice, priceChange = priceChange)) }
        // Cuplikan harga/batas di keranjang ikut diperbarui, supaya Keranjang menampilkan angka terkini.
        refreshCartSnapshots(quote.lines.map { CartLineRefresh(it.variantId, it.unitPrice, it.maxQuantity) })
    }

    /** `cart_invalid`: baris bermasalah ditandai di keranjang lokal supaya bisa diperbaiki di sana. */
    private suspend fun showCartInvalid(issues: List<CartIssue>) {
        val byVariant = cartLines.associateBy { it.variantId }
        val lines = issues.map { issue ->
            CartIssueLine(
                variantId = issue.variantId,
                name = byVariant[issue.variantId]?.let { "${it.productName} — ${it.variantName}" },
                reason = issue.reason,
                maxQuantity = issue.maxQuantity,
            )
        }
        updateForm { it.copy(review = ReviewState.CartInvalid(lines)) }
        refreshCartSnapshots(issues.map { CartLineRefresh(it.variantId, unitPrice = null, maxQuantity = it.maxQuantity) })
    }

    private suspend fun refreshCartSnapshots(updates: List<CartLineRefresh>) {
        try {
            cart.refresh(updates)
        } catch (e: CancellationException) {
            throw e
        } catch (_: Exception) {
            // Hanya cuplikan tampilan; kegagalannya tidak boleh menggagalkan checkout.
        }
    }

    private fun onQuoteFailure(error: ApiError) {
        when {
            // Sesi berakhir: alur sesi membuka layar masuk; setelah masuk lagi quote diminta ulang.
            error.isUnauthenticated -> updateForm { it.copy(review = ReviewState.Idle) }
            applyServerFieldErrors(error) -> Unit
            else -> updateForm { it.copy(review = ReviewState.QuoteFailed(error)) }
        }
    }

    /**
     * 422 `validation_failed` untuk kolom yang ada di langkah 2: kembali ke sana dengan galat di
     * kolomnya. Mengembalikan false bila tidak ada kolom yang dikenali.
     */
    private fun applyServerFieldErrors(error: ApiError): Boolean {
        val http = error as? ApiError.Http ?: return false
        if (http.code != ApiErrorCodes.VALIDATION_FAILED) return false
        val name = http.fieldErrors.firstMessage(FIELD_CONTACT_NAME)
        val phone = http.fieldErrors.firstMessage(FIELD_CONTACT_PHONE) ?: http.fieldErrors.firstMessage(FIELD_CONTACT)
        val notes = http.fieldErrors.firstMessage(FIELD_NOTES)
        val address = http.fieldErrors.containsKey(FIELD_ADDRESS)
        if (name == null && phone == null && notes == null && !address) return false
        setStep(CheckoutStep.Details)
        updateForm {
            it.copy(
                review = ReviewState.Idle,
                contactNameError = name,
                contactPhoneError = phone,
                notesError = notes,
                addressMissing = address,
            )
        }
        // Alamat yang ditolak kemungkinan sudah dihapus dari perangkat lain.
        if (address) loadAddresses()
        return true
    }

    // --- membuat pesanan ---

    /** "Buat pesanan". Ketukan kedua saat permintaan masih berjalan tidak mengirim apa pun. */
    fun placeOrder() {
        val form = form() ?: return
        val ready = form.review as? ReviewState.Ready ?: return
        if (ready.placing) return
        val request = buildRequest() ?: return
        // Ditandai sinkron sebelum coroutine apa pun: ketukan kedua sudah melihat `placing`.
        updateForm {
            it.copy(review = ready.copy(placing = true, priceChange = null, notice = null, submitError = null))
        }
        viewModelScope.launch {
            submit(request, intentKeyFor(request), expectedTotal = ready.quote.total, quote = ready.quote)
        }
    }

    /** "Periksa status pesanan": mengirim ulang dengan kunci YANG SAMA; server memutar ulang pesanan yang sudah tercatat. */
    fun retryUnknownStatus() {
        val form = form() ?: return
        val unknown = form.review as? ReviewState.StatusUnknown ?: return
        if (unknown.checking) return
        val request = buildRequest() ?: return
        val key = savedState.get<String>(KEY_INTENT_KEY)
        val expectedTotal = Money.parse(savedState.get<String>(KEY_PENDING_TOTAL))
        if (key == null || expectedTotal == null) {
            // Tidak ada yang dapat dikirim ulang dengan aman: mulai dari quote baru dan konfirmasi ulang.
            // Niat tertunda yang tersimpan TIDAK dilupakan: bila isinya sama, kuncinya dipakai lagi.
            clearPendingTotal()
            requestQuote(notice = ReviewNotice.ConfirmAgain)
            return
        }
        updateForm { it.copy(review = unknown.copy(checking = true)) }
        viewModelScope.launch { submit(request, key, expectedTotal, quote = unknown.quote) }
    }

    /** Tombol "Lihat pesanan" pada layar pesanan dibuat (bila navigasi otomatisnya terlewat). */
    fun viewPlacedOrder() {
        val placed = _uiState.value as? CheckoutUiState.Placed ?: return
        _events.trySend(CheckoutEvent.OrderPlaced(placed.orderNumber))
    }

    private suspend fun submit(request: CheckoutRequest, key: String, expectedTotal: BigDecimal, quote: Quote?) {
        // Ditandai SEBELUM dikirim: bila proses mati di tengah jalan, pemulihan tahu hasilnya tidak diketahui.
        savedState[KEY_PENDING_TOTAL] = Money.toWire(expectedTotal)
        // Juga di luar layar ini: meninggalkan checkout tidak boleh membuat kunci baru untuk isi yang sama.
        savedState.get<Long>(KEY_ACCOUNT_ID)?.let { accountId ->
            safely { pendingIntents.remember(accountId, CheckoutFingerprint.of(request), key) }
        }
        when (val outcome = checkout.placeOrder(request, expectedTotal, key)) {
            is PlaceOrderOutcome.Placed -> onPlaced(outcome.order)
            is PlaceOrderOutcome.PriceChanged -> {
                // Pesanan TIDAK dibuat. Isi pesanan sama, jadi kuncinya tetap; yang berubah hanya total.
                clearPending()
                val newQuote = outcome.quote
                if (newQuote != null) {
                    showQuote(newQuote, notice = null, priceChange = PriceChange(expectedTotal, newQuote.total))
                } else {
                    requestQuote(previousTotal = expectedTotal)
                }
            }
            is PlaceOrderOutcome.CartInvalid -> {
                clearPending()
                showCartInvalid(outcome.issues)
            }
            PlaceOrderOutcome.IdempotencyKeyReused -> {
                // Niat baru: kunci lama dibuang, rincian dihitung ulang, pelanggan mengonfirmasi lagi.
                clearPending()
                clearIntent()
                requestQuote(notice = ReviewNotice.ConfirmAgain)
            }
            is PlaceOrderOutcome.Failure -> onSubmitFailure(outcome.error, quote)
        }
    }

    private suspend fun onPlaced(order: PlacedOrder) {
        savedState[KEY_PLACED] = order.orderNumber
        clearPending()
        clearIntent()
        quoteJob?.cancel()
        addressJob?.cancel()
        // State lebih dulu, supaya keranjang yang dikosongkan tidak tampil sebagai "keranjang kosong".
        _uiState.value = CheckoutUiState.Placed(order.orderNumber)
        try {
            cart.clear()
        } catch (e: CancellationException) {
            throw e
        } catch (_: Exception) {
            // Pesanan sudah tercatat; keranjang yang gagal dikosongkan tidak boleh menutupi itu.
        }
        _events.send(CheckoutEvent.OrderPlaced(order.orderNumber))
    }

    private fun onSubmitFailure(error: ApiError, quote: Quote?) {
        // Penolakan = server menjawab 4xx (termasuk 423) atau 503 fitur dimatikan: pesanan TIDAK
        // dibuat oleh permintaan ini. Selain itu (timeout, koneksi putus, jawaban tak terbaca, 5xx)
        // server mungkin sudah mencatat pesanannya, jadi TIDAK boleh dilaporkan sebagai gagal.
        val rejected = error.isStoreUnavailable || (error is ApiError.Http && error.status in 400..499)
        if (!rejected) {
            updateForm { it.copy(review = ReviewState.StatusUnknown(quote)) }
            return
        }
        // Menolak permintaan ini belum berarti niatnya selesai. Penolakan middleware (401, 403, 404,
        // 413, 423, 429, 503) datang SEBELUM server mencari kunci idempotensi, jadi tidak mengatakan
        // apa pun tentang percobaan sebelumnya yang jawabannya hilang; niat tertunda di luar layar
        // DIPERTAHANKAN supaya kunjungan berikutnya dengan isi yang sama memakai kunci yang sama.
        // Hanya jawaban handler (409/422 tertentu) yang melupakannya. Penanda "sedang dikirim" milik
        // layar ini tetap dibersihkan: tampilan per kasus (sesi berakhir, toko tidak tersedia, galat
        // ringkasan) tidak berubah.
        if (CheckoutRules.settlesPendingIntent(error)) clearPending() else clearPendingTotal()
        when {
            // Sesi berakhir: keranjang dan isian tetap; alur sesi membuka layar masuk.
            error.isUnauthenticated -> updateForm { it.copy(review = ReviewState.Idle) }
            error.isStoreUnavailable -> updateForm { it.copy(review = ReviewState.QuoteFailed(error)) }
            applyServerFieldErrors(error) -> Unit
            else -> {
                if ((error as? ApiError.Http)?.code == ApiErrorCodes.IDEMPOTENCY_KEY_REQUIRED) clearIntent()
                updateForm { form ->
                    form.copy(
                        review = quote?.let { ReviewState.Ready(it, submitError = error) } ?: ReviewState.QuoteFailed(error),
                    )
                }
            }
        }
    }

    /**
     * Kunci untuk niat ini. Selama sidik isi pesanan sama dengan yang tersimpan, kunci lama dipakai
     * ulang (percobaan ulang niat yang sama); isi yang berubah mendapat kunci baru.
     */
    private suspend fun intentKeyFor(request: CheckoutRequest): String {
        val fingerprint = CheckoutFingerprint.of(request)
        val saved = savedState.get<String>(KEY_INTENT_KEY)
        if (saved != null && savedState.get<String>(KEY_INTENT_FINGERPRINT) == fingerprint) return saved
        // Niat yang sama dari kunjungan checkout sebelumnya yang hasilnya belum pasti: kunci lamanya.
        val accountId = savedState.get<Long>(KEY_ACCOUNT_ID)
        val pending = accountId?.let { id -> safely { pendingIntents.keyFor(id, fingerprint) } }
        val key = pending ?: keyGenerator.newKey()
        savedState[KEY_INTENT_KEY] = key
        savedState[KEY_INTENT_FINGERPRINT] = fingerprint
        return key
    }

    private fun clearIntent() {
        savedState.remove<String>(KEY_INTENT_KEY)
        savedState.remove<String>(KEY_INTENT_FINGERPRINT)
    }

    /** Server sudah menjawab pasti: niat tidak lagi tertunda, di layar ini maupun di luar layar. */
    private fun clearPending() {
        clearPendingTotal()
        viewModelScope.launch { safely { pendingIntents.forget() } }
    }

    /** Hanya penanda "sedang dikirim" milik layar ini; niat tertunda yang tersimpan tidak disentuh. */
    private fun clearPendingTotal() {
        savedState.remove<String>(KEY_PENDING_TOTAL)
    }

    /**
     * Penyimpanan niat tertunda adalah pengaman tambahan: kegagalannya (disk penuh, berkas rusak)
     * tidak boleh menghalangi pemesanan. Perlindungan di SavedStateHandle tetap berlaku.
     */
    private suspend fun <T> safely(block: suspend () -> T): T? = try {
        block()
    } catch (e: CancellationException) {
        throw e
    } catch (_: Exception) {
        null
    }

    private fun clearSavedForm() {
        listOf(KEY_METHOD, KEY_STEP, KEY_NAME, KEY_PHONE, KEY_NOTES).forEach { savedState.remove<String>(it) }
        savedState.remove<Long>(KEY_ADDRESS_ID)
        clearIntent()
        // Niat tertunda akun sebelumnya terikat id akunnya, jadi tidak pernah cocok untuk akun ini.
        clearPendingTotal()
    }

    /** Isi pesanan dari state saat ini. Null (dan kembali ke langkah 2) bila kurir tanpa alamat. */
    private fun buildRequest(): CheckoutRequest? {
        val form = form() ?: return null
        val method = form.method ?: return null
        val addressId = if (form.needsAddress) form.selectedAddressId else null
        if (form.needsAddress && addressId == null) {
            setStep(CheckoutStep.Details)
            updateForm { it.copy(review = ReviewState.Idle, addressMissing = true) }
            return null
        }
        return CheckoutRequest(
            method = method,
            addressId = addressId,
            contactName = form.contactName.trim(),
            contactPhone = form.contactPhone.trim(),
            items = cartLines.toItems(),
            notes = form.notes.trim().takeIf { it.isNotEmpty() },
        )
    }

    private fun setStep(step: CheckoutStep) {
        savedState[KEY_STEP] = step.name
        updateForm { it.copy(step = step) }
    }

    private fun form(): CheckoutUiState.Form? = _uiState.value as? CheckoutUiState.Form

    private inline fun updateForm(transform: (CheckoutUiState.Form) -> CheckoutUiState.Form) {
        _uiState.update { state -> (state as? CheckoutUiState.Form)?.let(transform) ?: state }
    }

    private fun List<CartLine>.toItems(): List<CheckoutItem> = map { CheckoutItem(it.variantId, it.quantity) }

    private fun Map<String, List<String>>.firstMessage(field: String): CheckoutFieldError? =
        this[field]?.firstOrNull { it.isNotBlank() }?.let(CheckoutFieldError::Server)

    private companion object {
        const val KEY_STEP = "checkout_step"
        const val KEY_METHOD = "checkout_method"
        const val KEY_NAME = "checkout_contact_name"
        const val KEY_PHONE = "checkout_contact_phone"
        const val KEY_NOTES = "checkout_notes"
        const val KEY_ADDRESS_ID = "checkout_address_id"
        const val KEY_ACCOUNT_ID = "checkout_account_id"
        const val KEY_INTENT_KEY = "checkout_idempotency_key"
        const val KEY_INTENT_FINGERPRINT = "checkout_intent_fingerprint"
        const val KEY_PENDING_TOTAL = "checkout_pending_expected_total"
        const val KEY_PLACED = "checkout_placed_order_number"
        const val KEY_SIGN_IN_PROMPTED = "checkout_sign_in_prompted"

        const val FIELD_CONTACT = "contact"
        const val FIELD_CONTACT_NAME = "contact.name"
        const val FIELD_CONTACT_PHONE = "contact.phone"
        const val FIELD_NOTES = "notes"
        const val FIELD_ADDRESS = "address_id"
    }
}
