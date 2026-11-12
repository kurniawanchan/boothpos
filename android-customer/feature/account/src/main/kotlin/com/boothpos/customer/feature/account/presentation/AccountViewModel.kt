package com.boothpos.customer.feature.account.presentation

import androidx.lifecycle.ViewModel
import androidx.lifecycle.viewModelScope
import com.boothpos.customer.core.data.domain.session.SessionRepository
import com.boothpos.customer.core.data.domain.session.SessionState
import com.boothpos.customer.core.data.domain.session.SignOutReason
import com.boothpos.customer.core.network.error.ApiError
import com.boothpos.customer.core.network.error.ApiResult
import com.boothpos.customer.core.network.error.isUnauthenticated
import com.boothpos.customer.feature.account.domain.ProfileRepository
import dagger.hilt.android.lifecycle.HiltViewModel
import javax.inject.Inject
import kotlinx.coroutines.flow.MutableStateFlow
import kotlinx.coroutines.flow.SharingStarted
import kotlinx.coroutines.flow.StateFlow
import kotlinx.coroutines.flow.combine
import kotlinx.coroutines.flow.first
import kotlinx.coroutines.flow.stateIn
import kotlinx.coroutines.flow.update
import kotlinx.coroutines.launch

sealed interface AccountUiState {
    /** Sesi tersimpan belum selesai dibaca. */
    data object Loading : AccountUiState

    /** Belum masuk: ajakan Masuk/Daftar. [sessionExpired] bila sesi sebelumnya ditolak server. */
    data class SignedOut(val sessionExpired: Boolean) : AccountUiState

    data class Profile(
        val name: String,
        val email: String,
        val phone: String?,
        /** Sedang menyegarkan dari server; data sesi tetap tampil. */
        val refreshing: Boolean = false,
        /** Penyegaran terakhir gagal; data yang tampil mungkin belum terbaru. */
        val refreshError: ApiError? = null,
        val confirmingSignOut: Boolean = false,
        val signingOut: Boolean = false,
    ) : AccountUiState {
        // Data pribadi tidak boleh muncul bila state ini tercetak di log atau laporan crash.
        override fun toString(): String =
            "Profile(refreshing=$refreshing, refreshError=$refreshError, " +
                "confirmingSignOut=$confirmingSignOut, signingOut=$signingOut)"
    }
}

/**
 * Layar Akun: ajakan masuk bila belum ada sesi, selain itu profil dari sesi (langsung tampil,
 * juga saat offline) yang disegarkan sekali dari server, plus keluar.
 */
@HiltViewModel
class AccountViewModel @Inject constructor(
    private val session: SessionRepository,
    private val profile: ProfileRepository,
) : ViewModel() {

    private data class Local(
        val refreshing: Boolean = false,
        val refreshError: ApiError? = null,
        val confirmingSignOut: Boolean = false,
        val signingOut: Boolean = false,
    )

    private val local = MutableStateFlow(Local())

    val uiState: StateFlow<AccountUiState> = combine(session.state, local) { sessionState, flags -> toUiState(sessionState, flags) }
        .stateIn(viewModelScope, SharingStarted.WhileSubscribed(5_000), toUiState(session.state.value, Local()))

    init {
        viewModelScope.launch {
            // Hanya saat layar dibuka dengan sesi yang ada; akun yang baru saja masuk sudah terbaru.
            if (session.state.first { it !is SessionState.Loading } is SessionState.SignedIn) refresh()
        }
    }

    /** Dipanggil saat layar dibuka dan dari "Coba lagi"; ketukan ganda tidak mengirim dua permintaan. */
    fun refresh() {
        if (local.value.refreshing || session.state.value !is SessionState.SignedIn) return
        local.update { it.copy(refreshing = true, refreshError = null) }
        viewModelScope.launch {
            val result = profile.refresh()
            local.update {
                it.copy(
                    refreshing = false,
                    // 401 ditangani lapisan sesi (sesi dihapus, layar beralih ke ajakan masuk).
                    refreshError = (result as? ApiResult.Failure)?.error?.takeUnless { error -> error.isUnauthenticated },
                )
            }
        }
    }

    fun requestSignOut() {
        if (local.value.signingOut) return
        local.update { it.copy(confirmingSignOut = true) }
    }

    fun dismissSignOut() {
        local.update { it.copy(confirmingSignOut = false) }
    }

    fun confirmSignOut() {
        if (local.value.signingOut) return
        local.update { it.copy(confirmingSignOut = false, signingOut = true) }
        viewModelScope.launch {
            // Perangkat selalu dibersihkan; pencabutan token di server upaya-terbaik (juga saat offline).
            session.signOut()
            local.value = Local()
        }
    }

    private fun toUiState(sessionState: SessionState, local: Local): AccountUiState = when (sessionState) {
        SessionState.Loading -> AccountUiState.Loading
        is SessionState.SignedOut ->
            AccountUiState.SignedOut(sessionExpired = sessionState.reason == SignOutReason.SessionExpired)
        is SessionState.SignedIn -> AccountUiState.Profile(
            name = sessionState.account.name,
            email = sessionState.account.email,
            phone = sessionState.account.phone,
            refreshing = local.refreshing,
            refreshError = local.refreshError,
            confirmingSignOut = local.confirmingSignOut,
            signingOut = local.signingOut,
        )
    }
}
