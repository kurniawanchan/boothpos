package com.boothpos.customer.feature.auth.presentation

import com.boothpos.customer.core.data.domain.model.Account
import com.boothpos.customer.core.data.testing.TestData
import com.boothpos.customer.core.network.error.ApiResult
import com.boothpos.customer.feature.auth.domain.AuthRepository
import com.boothpos.customer.feature.auth.domain.RegisterInput
import kotlinx.coroutines.CompletableDeferred

/** Fake repository auth untuk tes ViewModel: merekam panggilan dan dapat ditahan "di tengah jalan". */
internal class FakeAuthRepository : AuthRepository {
    var result: ApiResult<Account> = ApiResult.Success(TestData.account())

    /** Bila diisi, panggilan menunggu sampai gerbang ini diselesaikan (meniru permintaan yang sedang berjalan). */
    var gate: CompletableDeferred<Unit>? = null

    val registrations = mutableListOf<RegisterInput>()
    val signIns = mutableListOf<Pair<String, String>>()

    override suspend fun register(input: RegisterInput): ApiResult<Account> {
        registrations += input
        gate?.await()
        return result
    }

    override suspend fun signIn(email: String, password: String): ApiResult<Account> {
        signIns += email to password
        gate?.await()
        return result
    }
}
