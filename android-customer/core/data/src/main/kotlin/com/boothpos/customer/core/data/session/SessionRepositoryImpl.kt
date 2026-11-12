package com.boothpos.customer.core.data.session

import com.boothpos.customer.core.data.di.ApplicationScope
import com.boothpos.customer.core.data.domain.model.Account
import com.boothpos.customer.core.data.domain.session.SessionRepository
import com.boothpos.customer.core.data.domain.session.SessionState
import com.boothpos.customer.core.data.domain.session.SignOutReason
import com.boothpos.customer.core.data.domain.session.UserScopedDataCleaner
import com.boothpos.customer.core.data.remote.SessionApi
import com.boothpos.customer.core.data.security.StoredSession
import com.boothpos.customer.core.data.security.TokenStore
import com.boothpos.customer.core.network.UnauthorizedEvents
import com.boothpos.customer.core.network.error.SafeApiCall
import dagger.Lazy
import java.time.Clock
import java.time.Instant
import javax.inject.Inject
import javax.inject.Singleton
import kotlinx.coroutines.CancellationException
import kotlinx.coroutines.CoroutineScope
import kotlinx.coroutines.CoroutineStart
import kotlinx.coroutines.flow.MutableStateFlow
import kotlinx.coroutines.flow.StateFlow
import kotlinx.coroutines.flow.asStateFlow
import kotlinx.coroutines.launch
import kotlinx.coroutines.sync.Mutex
import kotlinx.coroutines.sync.withLock

/** Pencabutan token di server (`POST /customer/auth/logout`). Dipisah supaya sesi dapat diuji tanpa HTTP. */
fun interface TokenRevoker {
    suspend fun revoke(token: String)
}

class ApiTokenRevoker @Inject constructor(
    private val api: Lazy<SessionApi>,
    private val safeApiCall: SafeApiCall,
) : TokenRevoker {
    // Upaya terbaik: hasilnya sengaja diabaikan. Token yang gagal dicabut tetap kedaluwarsa sendiri.
    override suspend fun revoke(token: String) {
        safeApiCall.unit { api.get().logout("Bearer $token") }
    }
}

@Singleton
class SessionRepositoryImpl @Inject constructor(
    private val tokenStore: TokenStore,
    private val cleaners: Set<@JvmSuppressWildcards UserScopedDataCleaner>,
    private val tokenRevoker: TokenRevoker,
    unauthorizedEvents: UnauthorizedEvents,
    private val clock: Clock,
    @ApplicationScope private val scope: CoroutineScope,
) : SessionRepository {

    private val mutex = Mutex()
    private val _state = MutableStateFlow<SessionState>(SessionState.Loading)
    override val state: StateFlow<SessionState> = _state.asStateFlow()

    init {
        // UNDISPATCHED: langganan terpasang sebelum konstruktor selesai, jadi 401 pertama tidak terlewat.
        scope.launch(start = CoroutineStart.UNDISPATCHED) {
            unauthorizedEvents.rejectedTokens.collect { rejected -> expireIfCurrent(rejected) }
        }
        scope.launch { restore() }
    }

    private suspend fun restore() {
        mutex.withLock {
            // Sesi yang dimulai sebelum pemulihan selesai tidak boleh ditimpa.
            if (_state.value != SessionState.Loading) return
            val stored = tokenStore.load()
            if (stored == null) {
                _state.value = SessionState.SignedOut()
            } else if (stored.isExpired()) {
                clearLocal(SignOutReason.SessionExpired)
            } else {
                _state.value = SessionState.SignedIn(stored.account, stored.expiresAt)
            }
        }
    }

    override suspend fun startSession(token: String, expiresAt: Instant?, account: Account) {
        mutex.withLock {
            tokenStore.save(StoredSession(token, expiresAt, account))
            _state.value = SessionState.SignedIn(account, expiresAt)
        }
    }

    override suspend fun updateAccount(account: Account) {
        mutex.withLock {
            val current = tokenStore.load() ?: return
            tokenStore.save(current.copy(account = account))
            _state.value = SessionState.SignedIn(account, current.expiresAt)
        }
    }

    override suspend fun signOut() {
        val token = mutex.withLock {
            val current = tokenStore.peekToken()
            clearLocal(SignOutReason.UserRequested)
            current
        }
        // Perangkat sudah bersih; pencabutan di server tidak boleh menahan layar (mis. saat offline).
        if (token != null) {
            scope.launch {
                try {
                    tokenRevoker.revoke(token)
                } catch (e: CancellationException) {
                    throw e
                } catch (_: Exception) {
                    // Upaya terbaik: token yang gagal dicabut tetap kedaluwarsa sendiri di server.
                }
            }
        }
    }

    /** 401 hanya mengakhiri sesi bila token yang ditolak memang token yang sedang dipakai. */
    private suspend fun expireIfCurrent(rejectedToken: String) {
        mutex.withLock {
            if (tokenStore.peekToken() == rejectedToken) clearLocal(SignOutReason.SessionExpired)
        }
    }

    private suspend fun clearLocal(reason: SignOutReason) {
        tokenStore.clear()
        cleaners.forEach { cleaner ->
            try {
                cleaner.clear(reason)
            } catch (e: CancellationException) {
                throw e
            } catch (_: Exception) {
                // Satu pembersih yang gagal tidak boleh membuat pelanggan tetap "masuk".
            }
        }
        _state.value = SessionState.SignedOut(reason)
    }

    private fun StoredSession.isExpired(): Boolean = expiresAt != null && !clock.instant().isBefore(expiresAt)
}
