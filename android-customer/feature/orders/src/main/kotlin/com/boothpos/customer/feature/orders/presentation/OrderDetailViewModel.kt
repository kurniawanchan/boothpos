package com.boothpos.customer.feature.orders.presentation

import androidx.lifecycle.SavedStateHandle
import androidx.lifecycle.ViewModel
import androidx.lifecycle.viewModelScope
import com.boothpos.customer.core.data.domain.session.SessionRepository
import com.boothpos.customer.core.data.domain.session.SessionState
import com.boothpos.customer.core.data.domain.session.SignOutReason
import com.boothpos.customer.core.network.error.ApiError
import com.boothpos.customer.core.network.error.ApiErrorCodes
import com.boothpos.customer.core.network.error.ApiResult
import com.boothpos.customer.core.network.error.isUnauthenticated
import com.boothpos.customer.feature.orders.domain.OrderDetail
import com.boothpos.customer.feature.orders.domain.OrderTracking
import com.boothpos.customer.feature.orders.domain.OrdersRepository
import dagger.hilt.android.lifecycle.HiltViewModel
import javax.inject.Inject
import kotlinx.coroutines.Job
import kotlinx.coroutines.async
import kotlinx.coroutines.channels.Channel
import kotlinx.coroutines.coroutineScope
import kotlinx.coroutines.ensureActive
import kotlinx.coroutines.flow.Flow
import kotlinx.coroutines.flow.MutableStateFlow
import kotlinx.coroutines.flow.StateFlow
import kotlinx.coroutines.flow.asStateFlow
import kotlinx.coroutines.flow.receiveAsFlow
import kotlinx.coroutines.launch

sealed interface OrderDetailUiState {
    data object Loading : OrderDetailUiState

    data class Error(val error: ApiError, val retrying: Boolean = false) : OrderDetailUiState

    /** Server menjawab 404 `not_found`: nomor tidak ada, atau pesanan itu milik akun lain. */
    data object NotFound : OrderDetailUiState

    /** Tidak ada sesi: detail pesanan tidak ditampilkan. [sessionExpired] bila sesi ditolak server. */
    data class SignedOut(val sessionExpired: Boolean) : OrderDetailUiState

    data class Content(
        val order: OrderDetail,
        /** Dari `updated_by == "store"`: pelacakan dicatat staf toko, bukan langsung dari kurir. */
        val updatedByStore: Boolean,
        val refreshing: Boolean = false,
        /** Penyegaran terakhir gagal; data yang tampil mungkin belum terbaru. */
        val refreshError: ApiError? = null,
        /** Pemberitahuan "Anda akan membuka situs kurir di luar aplikasi" sedang tampil. */
        val confirmingHandoff: Boolean = false,
    ) : OrderDetailUiState {
        /** Bukan null hanya bila server mengirim tautan pelacakan https yang wajar. */
        val trackingLink: String? get() = order.shipment?.trackingLink
    }
}

sealed interface OrderDetailEvent {
    /** Buka [url] di peramban luar. Selalu tautan yang sudah lolos `TrackingLink`. */
    data class OpenExternalUrl(val url: String) : OrderDetailEvent
}

/**
 * Detail satu pesanan: rincian dari `GET /customer/orders/{nomor}` dan pelacakan dari
 * `GET …/tracking` (yang membawa tanda "diperbarui oleh toko"), dimuat bersamaan. Hanya membaca:
 * pelanggan tidak punya aksi yang mengubah status pesanan.
 */
@HiltViewModel
class OrderDetailViewModel @Inject constructor(
    savedStateHandle: SavedStateHandle,
    private val repository: OrdersRepository,
    private val session: SessionRepository,
) : ViewModel() {

    private val orderNumber: String =
        checkNotNull(savedStateHandle.get<String>(OrderDetailRoute.ORDER_NUMBER_ARG)) { "Rute detail pesanan tanpa nomor" }

    // Semua penulisan state terjadi di main thread (viewModelScope), jadi tidak ada penulis serentak.
    private val _uiState = MutableStateFlow<OrderDetailUiState>(OrderDetailUiState.Loading)
    val uiState: StateFlow<OrderDetailUiState> = _uiState.asStateFlow()

    private val _events = Channel<OrderDetailEvent>(Channel.BUFFERED)
    val events: Flow<OrderDetailEvent> = _events.receiveAsFlow()

    /** Akun yang datanya sedang tampil/dimuat; null bila tidak ada. */
    private var accountId: Long? = null
    private var loadJob: Job? = null

    init {
        viewModelScope.launch { session.state.collect(::onSessionChanged) }
    }

    /** Tarik-untuk-segarkan, "Perbarui status", dan "Coba lagi". Ketukan ganda tidak mengirim dua kali. */
    fun refresh() {
        when (val state = _uiState.value) {
            OrderDetailUiState.Loading, OrderDetailUiState.NotFound, is OrderDetailUiState.SignedOut -> return
            is OrderDetailUiState.Error -> if (state.retrying) return
            is OrderDetailUiState.Content -> if (state.refreshing) return
        }
        load(keepShown = true)
    }

    /** "Lacak paket": hanya menampilkan pemberitahuan; tautan baru dibuka setelah disetujui. */
    fun onTrackParcelClick() {
        val content = _uiState.value as? OrderDetailUiState.Content ?: return
        if (content.trackingLink == null) return
        _uiState.value = content.copy(confirmingHandoff = true)
    }

    fun dismissHandoff() {
        val content = _uiState.value as? OrderDetailUiState.Content ?: return
        _uiState.value = content.copy(confirmingHandoff = false)
    }

    fun confirmHandoff() {
        val content = _uiState.value as? OrderDetailUiState.Content ?: return
        if (!content.confirmingHandoff) return
        _uiState.value = content.copy(confirmingHandoff = false)
        // Diperiksa lagi saat dibuka: penyegaran bisa saja sudah menghapus tautannya.
        val link = content.trackingLink ?: return
        viewModelScope.launch { _events.send(OrderDetailEvent.OpenExternalUrl(link)) }
    }

    private fun onSessionChanged(state: SessionState) {
        when (state) {
            SessionState.Loading -> Unit
            is SessionState.SignedOut -> {
                loadJob?.cancel()
                accountId = null
                // Kontak dan alamat pesanan tidak boleh tertinggal di layar setelah keluar.
                _uiState.value = OrderDetailUiState.SignedOut(sessionExpired = state.reason == SignOutReason.SessionExpired)
            }
            // Masuk lagi (mis. setelah sesi berakhir) memuat ulang; perubahan profil akun yang sama tidak.
            is SessionState.SignedIn -> if (state.account.id != accountId) {
                accountId = state.account.id
                load(keepShown = false)
            }
        }
    }

    private fun load(keepShown: Boolean) {
        loadJob?.cancel()
        _uiState.value = when (val state = _uiState.value.takeIf { keepShown }) {
            is OrderDetailUiState.Content -> state.copy(refreshing = true, refreshError = null)
            is OrderDetailUiState.Error -> state.copy(retrying = true)
            else -> OrderDetailUiState.Loading
        }
        loadJob = viewModelScope.launch {
            val (detail, tracking) = coroutineScope {
                val detail = async { repository.order(orderNumber) }
                val tracking = async { repository.tracking(orderNumber) }
                detail.await() to tracking.await()
            }
            // Sesi berganti atau penyegaran baru dimulai selama menunggu: jawaban ini tidak boleh tampil.
            ensureActive()
            // Pemberitahuan tautan bisa dibuka/ditutup selama penyegaran: pakai state TERKINI.
            val shown = _uiState.value as? OrderDetailUiState.Content
            _uiState.value = when (detail) {
                is ApiResult.Success -> content(detail.value, tracking, shown)
                is ApiResult.Failure -> failure(detail.error, shown)
            }
        }
    }

    private fun content(
        detail: OrderDetail,
        tracking: ApiResult<OrderTracking>,
        shown: OrderDetailUiState.Content?,
    ): OrderDetailUiState {
        val trackingError = (tracking as? ApiResult.Failure)?.error
        // Sesi berakhir di antara kedua permintaan: perlakukan seperti 401 pada rinciannya.
        if (trackingError?.isUnauthenticated == true) return signedOutAfterRejection()
        val latest = (tracking as? ApiResult.Success)?.value
        // Pelacakan menimpa pengiriman dan linimasa hanya bila menggambarkan keadaan yang sama
        // dengan rinciannya; kalau toko mengubah status di antara dua permintaan, rincian (yang
        // utuh: status, biaya, alasan batal) dipakai apa adanya sampai penyegaran berikutnya.
        val consistent = latest?.takeIf { it.orderNumber == detail.summary.orderNumber && it.status == detail.summary.status }
        val order = if (consistent != null) detail.copy(shipment = consistent.shipment, timeline = consistent.timeline) else detail
        return OrderDetailUiState.Content(
            order = order,
            updatedByStore = latest?.updatedByStore ?: shown?.updatedByStore ?: false,
            refreshError = trackingError,
            confirmingHandoff = shown?.confirmingHandoff == true && order.shipment?.trackingLink != null,
        )
    }

    private fun failure(error: ApiError, shown: OrderDetailUiState.Content?): OrderDetailUiState = when {
        error.isUnauthenticated -> signedOutAfterRejection()
        error is ApiError.Http && error.code == ApiErrorCodes.NOT_FOUND -> OrderDetailUiState.NotFound
        shown != null -> shown.copy(refreshing = false, refreshError = error)
        else -> OrderDetailUiState.Error(error)
    }

    /**
     * 401: lapisan sesi menghapus token dan :app membuka layar masuk. Rincian dibuang di sini
     * juga, tanpa menunggu perubahan sesi itu tiba; masuk lagi memuat ulang pesanan ini.
     */
    private fun signedOutAfterRejection(): OrderDetailUiState {
        accountId = null
        return OrderDetailUiState.SignedOut(sessionExpired = true)
    }
}
