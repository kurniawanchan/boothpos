package com.boothpos.customer.feature.account.presentation

import com.boothpos.customer.core.data.domain.model.Account
import com.boothpos.customer.core.data.domain.session.SessionRepository
import com.boothpos.customer.core.data.domain.session.SessionState
import com.boothpos.customer.core.network.error.ApiError
import com.boothpos.customer.core.network.error.ApiResult
import com.boothpos.customer.feature.account.domain.ProfileRepository
import kotlinx.coroutines.CompletableDeferred

/**
 * Fake profil untuk tes ViewModel. Seperti implementasi aslinya, hasil yang berhasil ikut
 * memperbarui akun di sesi.
 */
internal class FakeProfileRepository(private val session: SessionRepository) : ProfileRepository {
    /** Bila diisi, setiap panggilan berikutnya gagal dengan galat ini. */
    var failure: ApiError? = null

    /** Akun yang dikembalikan `refresh()`; null berarti akun sesi saat ini apa adanya. */
    var serverAccount: Account? = null

    /** Bila diisi, panggilan menunggu sampai gerbang ini diselesaikan. */
    var gate: CompletableDeferred<Unit>? = null

    var refreshCalls = 0
        private set
    val updates = mutableListOf<Pair<String, String?>>()

    private fun current(): Account? = (session.state.value as? SessionState.SignedIn)?.account

    override suspend fun refresh(): ApiResult<Account> {
        refreshCalls++
        gate?.await()
        failure?.let { return ApiResult.Failure(it) }
        val account = serverAccount ?: current() ?: return ApiResult.Failure(ApiError.Unknown)
        session.updateAccount(account)
        return ApiResult.Success(account)
    }

    override suspend fun update(name: String, phone: String?): ApiResult<Account> {
        updates += name to phone
        gate?.await()
        failure?.let { return ApiResult.Failure(it) }
        val account = current()?.copy(name = name.trim(), phone = phone?.trim())
            ?: return ApiResult.Failure(ApiError.Unknown)
        session.updateAccount(account)
        return ApiResult.Success(account)
    }
}
