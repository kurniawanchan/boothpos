package com.boothpos.customer.ui

import androidx.lifecycle.ViewModel
import androidx.lifecycle.viewModelScope
import com.boothpos.customer.core.data.domain.cart.CartRepository
import com.boothpos.customer.core.data.domain.session.SessionRepository
import com.boothpos.customer.core.data.domain.session.SessionState
import com.boothpos.customer.core.data.domain.session.SignOutReason
import com.boothpos.customer.core.data.domain.store.StoreConfigRepository
import com.boothpos.customer.core.network.error.ApiResult
import com.boothpos.customer.core.network.error.isStoreUnavailable
import com.boothpos.customer.feature.auth.domain.OnboardingRepository
import dagger.hilt.android.lifecycle.HiltViewModel
import javax.inject.Inject
import kotlinx.coroutines.channels.Channel
import kotlinx.coroutines.flow.Flow
import kotlinx.coroutines.flow.MutableStateFlow
import kotlinx.coroutines.flow.SharingStarted
import kotlinx.coroutines.flow.StateFlow
import kotlinx.coroutines.flow.combine
import kotlinx.coroutines.flow.first
import kotlinx.coroutines.flow.receiveAsFlow
import kotlinx.coroutines.flow.stateIn
import kotlinx.coroutines.flow.update
import kotlinx.coroutines.launch

enum class StoreStatus {
    /** Belum ada jawaban maupun konfigurasi tersimpan: jangan menutup apa pun. */
    Unknown,
    Open,

    /** Fitur dimatikan (`enabled=false` / 503) atau instalasi terkunci lisensi (423). */
    Closed,
}

data class AppUiState(
    val storeStatus: StoreStatus = StoreStatus.Unknown,
    val checkingStore: Boolean = false,
    val storeName: String? = null,
    val cartItemCount: Int = 0,
)

sealed interface AppEvent {
    /**
     * Sesi berakhir saat aplikasi dipakai (401): arahkan ke layar masuk. Pesannya ("Sesi berakhir,
     * silakan masuk lagi") ditampilkan layar masuk itu sendiri, bukan snackbar yang hilang.
     */
    data object SessionExpired : AppEvent

    /** Pembukaan pertama tanpa sesi: tawarkan Masuk / Daftar / lihat toko tanpa akun, sekali saja. */
    data object ShowWelcome : AppEvent
}

/** Keadaan bingkai aplikasi: status toko, lencana keranjang, sambutan pertama, dan sesi berakhir. */
@HiltViewModel
class AppViewModel @Inject constructor(
    private val storeConfig: StoreConfigRepository,
    cart: CartRepository,
    session: SessionRepository,
    onboarding: OnboardingRepository,
) : ViewModel() {

    private data class StoreCheck(val checking: Boolean = false, val lockedOut: Boolean = false)

    private val storeCheck = MutableStateFlow(StoreCheck())

    val uiState: StateFlow<AppUiState> =
        combine(storeConfig.config, storeCheck, cart.itemCount) { config, check, cartCount ->
            AppUiState(
                storeStatus = when {
                    check.lockedOut -> StoreStatus.Closed
                    config == null -> StoreStatus.Unknown
                    config.enabled -> StoreStatus.Open
                    else -> StoreStatus.Closed
                },
                checkingStore = check.checking,
                storeName = config?.storeName?.takeIf { it.isNotBlank() },
                cartItemCount = cartCount,
            )
        }.stateIn(viewModelScope, SharingStarted.WhileSubscribed(5_000), AppUiState())

    private val _events = Channel<AppEvent>(Channel.BUFFERED)
    val events: Flow<AppEvent> = _events.receiveAsFlow()

    init {
        refreshStore()
        viewModelScope.launch {
            if (onboarding.isWelcomePending()) {
                // Ditandai lebih dulu: sambutan tidak boleh muncul lagi walau pelanggan langsung
                // menutupnya atau aplikasi dimatikan saat layar itu tampil.
                onboarding.markWelcomeSeen()
                if (session.state.first { it !is SessionState.Loading } is SessionState.SignedOut) {
                    _events.send(AppEvent.ShowWelcome)
                }
            }
        }
        viewModelScope.launch {
            var wasSignedIn = false
            session.state.collect { state ->
                // Hanya peralihan saat aplikasi dipakai; sesi yang sudah kedaluwarsa sejak awal
                // tidak memaksa pelanggan ke layar masuk ketika ia baru membuka katalog.
                if (wasSignedIn && state is SessionState.SignedOut && state.reason == SignOutReason.SessionExpired) {
                    _events.send(AppEvent.SessionExpired)
                }
                wasSignedIn = state is SessionState.SignedIn
            }
        }
    }

    /** Dipanggil saat start dan dari tombol "Coba lagi"; ketukan ganda tidak mengirim dua permintaan. */
    fun refreshStore() {
        if (storeCheck.value.checking) return
        storeCheck.update { it.copy(checking = true) }
        viewModelScope.launch {
            val result = storeConfig.refresh()
            storeCheck.value = StoreCheck(
                checking = false,
                // Galat jaringan biasa BUKAN "toko tutup": layar fitur menampilkan keadaan offline-nya sendiri.
                lockedOut = result is ApiResult.Failure && result.error.isStoreUnavailable,
            )
        }
    }
}
