package com.boothpos.customer.feature.orders.presentation

import androidx.lifecycle.ViewModel
import androidx.lifecycle.viewModelScope
import com.boothpos.customer.core.data.domain.session.SessionRepository
import com.boothpos.customer.core.data.domain.session.SessionState
import com.boothpos.customer.core.data.domain.session.SignOutReason
import com.boothpos.customer.core.network.error.ApiError
import com.boothpos.customer.core.network.error.ApiResult
import com.boothpos.customer.core.network.error.isUnauthenticated
import com.boothpos.customer.feature.orders.domain.OrderSummary
import com.boothpos.customer.feature.orders.domain.OrdersRepository
import dagger.hilt.android.lifecycle.HiltViewModel
import javax.inject.Inject
import kotlinx.coroutines.Job
import kotlinx.coroutines.ensureActive
import kotlinx.coroutines.flow.MutableStateFlow
import kotlinx.coroutines.flow.StateFlow
import kotlinx.coroutines.flow.asStateFlow
import kotlinx.coroutines.flow.update
import kotlinx.coroutines.launch

sealed interface OrdersUiState {
    /** Sesi tersimpan belum selesai dibaca, atau halaman pertama sedang dimuat. */
    data object Loading : OrdersUiState

    /** Belum masuk: ajakan masuk. [sessionExpired] bila sesi sebelumnya ditolak server. */
    data class SignedOut(val sessionExpired: Boolean) : OrdersUiState

    /** Halaman pertama gagal dan tidak ada yang dapat ditampilkan. */
    data class Error(val error: ApiError, val retrying: Boolean = false) : OrdersUiState

    /** Akun ini belum pernah membuat pesanan. */
    data object Empty : OrdersUiState

    data class Orders(
        val items: List<OrderSummary>,
        val canLoadMore: Boolean = false,
        val loadingMore: Boolean = false,
        /** Halaman berikutnya gagal dimuat; pesanan yang sudah ada tetap tampil. */
        val loadMoreError: ApiError? = null,
        val refreshing: Boolean = false,
        /** Bukan null: penyegaran terakhir gagal, jadi yang tampil mungkin bukan data terbaru. */
        val staleReason: ApiError? = null,
    ) : OrdersUiState
}

/**
 * Daftar "Pesanan Saya": terbaru lebih dulu, halaman demi halaman, hanya untuk akun yang sedang
 * masuk. Daftar mengikuti sesi: keluar (atau sesi berakhir) langsung mengosongkannya, dan akun
 * lain yang masuk tidak pernah melihat pesanan akun sebelumnya.
 */
@HiltViewModel
class OrdersViewModel @Inject constructor(
    private val session: SessionRepository,
    private val repository: OrdersRepository,
) : ViewModel() {

    // Semua penulisan state terjadi di main thread (viewModelScope), jadi tidak ada penulis serentak.
    private val _uiState = MutableStateFlow<OrdersUiState>(OrdersUiState.Loading)
    val uiState: StateFlow<OrdersUiState> = _uiState.asStateFlow()

    /** Akun pemilik daftar yang sedang tampil/dimuat; null bila tidak ada. */
    private var accountId: Long? = null
    private var nextPage = FIRST_PAGE
    private var loadJob: Job? = null

    init {
        viewModelScope.launch { session.state.collect(::onSessionChanged) }
    }

    /**
     * Dipanggil setiap kali layar tampil (juga saat kembali dari detail atau setelah checkout):
     * status pesanan diubah toko kapan saja, jadi daftar yang sudah ada disegarkan tanpa
     * membuang halaman yang sudah dimuat.
     */
    fun onScreenShown() {
        when (_uiState.value) {
            // Pemuatan pertama masih berjalan (atau sesi belum terbaca).
            OrdersUiState.Loading -> Unit
            is OrdersUiState.SignedOut -> {
                // Sesi sudah ada lagi tetapi layar ini belum mengetahuinya (mis. setelah 401).
                val signedIn = session.state.value as? SessionState.SignedIn ?: return
                accountId = signedIn.account.id
                loadFirstPage(Reload.Fresh)
            }
            else -> startRefresh(Reload.Merge)
        }
    }

    /** Tarik-untuk-segarkan, tombol muat ulang, dan "Coba lagi". Ketukan ganda tidak mengirim dua kali. */
    fun refresh() = startRefresh(Reload.Replace)

    /** Dipanggil layar ketika guliran mendekati akhir daftar. */
    fun loadMore() {
        val content = _uiState.value as? OrdersUiState.Orders ?: return
        // Setelah gagal, halaman berikutnya hanya dicoba lagi atas permintaan pelanggan.
        if (content.loadMoreError != null) return
        startLoadMore(content)
    }

    fun retryLoadMore() {
        val content = _uiState.value as? OrdersUiState.Orders ?: return
        startLoadMore(content)
    }

    private fun onSessionChanged(state: SessionState) {
        when (state) {
            SessionState.Loading -> Unit
            is SessionState.SignedOut -> {
                loadJob?.cancel()
                accountId = null
                _uiState.value = OrdersUiState.SignedOut(sessionExpired = state.reason == SignOutReason.SessionExpired)
            }
            // Perubahan profil akun yang sama (nama/telepon) tidak memuat ulang daftar.
            is SessionState.SignedIn -> if (state.account.id != accountId) {
                accountId = state.account.id
                loadFirstPage(Reload.Fresh)
            }
        }
    }

    private fun startRefresh(mode: Reload) {
        when (val state = _uiState.value) {
            OrdersUiState.Loading, is OrdersUiState.SignedOut -> return
            is OrdersUiState.Error -> if (state.retrying) return
            is OrdersUiState.Orders -> if (state.refreshing) return
            OrdersUiState.Empty -> Unit
        }
        loadFirstPage(mode)
    }

    private fun loadFirstPage(mode: Reload) {
        // Juga membatalkan pemuatan halaman berikutnya yang sedang berjalan.
        loadJob?.cancel()
        val kept = _uiState.value.takeIf { mode != Reload.Fresh }
        _uiState.value = when (kept) {
            is OrdersUiState.Orders -> kept.copy(refreshing = true, loadingMore = false, loadMoreError = null)
            is OrdersUiState.Error -> kept.copy(retrying = true)
            else -> OrdersUiState.Loading
        }
        loadJob = viewModelScope.launch {
            val result = repository.orders(FIRST_PAGE)
            // Sesi berganti atau penyegaran baru dimulai selama menunggu: jawaban ini tidak boleh tampil.
            ensureActive()
            _uiState.value = when (result) {
                is ApiResult.Success -> {
                    val shown = (kept as? OrdersUiState.Orders)?.takeIf { mode == Reload.Merge && nextPage > FIRST_PAGE + 1 }
                    val page = result.value
                    when {
                        // Halaman-halaman berikutnya sudah dimuat: pertahankan, perbarui yang terbaru saja.
                        shown != null -> shown.copy(
                            items = (page.items + shown.items).distinctBy(OrderSummary::orderNumber),
                            refreshing = false,
                            staleReason = null,
                        )
                        page.items.isEmpty() -> OrdersUiState.Empty
                        else -> {
                            nextPage = FIRST_PAGE + 1
                            // Nomor pesanan menjadi kunci baris daftar: tidak boleh ada yang ganda.
                            OrdersUiState.Orders(
                                items = page.items.distinctBy(OrderSummary::orderNumber),
                                canLoadMore = page.page.hasMore,
                            )
                        }
                    }
                }
                is ApiResult.Failure -> firstPageFailure(result.error, kept)
            }
        }
    }

    private fun firstPageFailure(error: ApiError, kept: OrdersUiState?): OrdersUiState = when {
        error.isUnauthenticated -> signedOutAfterRejection()
        kept is OrdersUiState.Orders ->
            kept.copy(refreshing = false, loadingMore = false, loadMoreError = null, staleReason = error)
        else -> OrdersUiState.Error(error)
    }

    private fun startLoadMore(content: OrdersUiState.Orders) {
        if (!content.canLoadMore || content.loadingMore || content.refreshing) return
        val page = nextPage
        updateOrders { it.copy(loadingMore = true, loadMoreError = null) }
        loadJob = viewModelScope.launch {
            val result = repository.orders(page)
            ensureActive()
            when (result) {
                is ApiResult.Success -> {
                    nextPage = page + 1
                    val loaded = result.value
                    updateOrders { current ->
                        current.copy(
                            // Pesanan baru menggeser daftar di server di antara dua halaman: tanpa duplikat.
                            items = (current.items + loaded.items).distinctBy(OrderSummary::orderNumber),
                            // Halaman kosong menghentikan pemuatan, apa pun kata `meta`.
                            canLoadMore = loaded.page.hasMore && loaded.items.isNotEmpty(),
                            loadingMore = false,
                        )
                    }
                }
                is ApiResult.Failure ->
                    if (result.error.isUnauthenticated) {
                        _uiState.value = signedOutAfterRejection()
                    } else {
                        updateOrders { it.copy(loadingMore = false, loadMoreError = result.error) }
                    }
            }
        }
    }

    /**
     * 401: lapisan sesi menghapus token dan :app membuka layar masuk. Daftar dikosongkan di sini
     * juga, tanpa menunggu perubahan sesi itu tiba, supaya pesanan tidak tertinggal di layar.
     */
    private fun signedOutAfterRejection(): OrdersUiState {
        accountId = null
        return OrdersUiState.SignedOut(sessionExpired = true)
    }

    private fun updateOrders(transform: (OrdersUiState.Orders) -> OrdersUiState.Orders) {
        _uiState.update { state -> if (state is OrdersUiState.Orders) transform(state) else state }
    }

    private enum class Reload {
        /** Buang yang tampil (akun baru masuk). */
        Fresh,

        /** Ganti daftar dengan halaman pertama yang baru (penyegaran atas permintaan pelanggan). */
        Replace,

        /** Perbarui pesanan terbaru, pertahankan halaman berikutnya yang sudah dimuat. */
        Merge,
    }

    private companion object {
        const val FIRST_PAGE = 1
    }
}
