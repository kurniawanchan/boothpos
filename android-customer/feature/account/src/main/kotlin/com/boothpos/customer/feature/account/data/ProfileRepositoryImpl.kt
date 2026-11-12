package com.boothpos.customer.feature.account.data

import com.boothpos.customer.core.data.domain.model.Account
import com.boothpos.customer.core.data.domain.session.SessionRepository
import com.boothpos.customer.core.data.domain.session.SessionState
import com.boothpos.customer.core.data.remote.AccountDto
import com.boothpos.customer.core.data.remote.toDomain
import com.boothpos.customer.core.network.dto.DataEnvelope
import com.boothpos.customer.core.network.error.ApiResult
import com.boothpos.customer.core.network.error.SafeApiCall
import com.boothpos.customer.feature.account.domain.ProfileRepository
import javax.inject.Inject
import kotlinx.serialization.json.JsonNull
import kotlinx.serialization.json.JsonPrimitive

class ProfileRepositoryImpl @Inject constructor(
    private val api: ProfileApi,
    private val safeApiCall: SafeApiCall,
    private val session: SessionRepository,
) : ProfileRepository {

    override suspend fun refresh(): ApiResult<Account> = safeApiCall { api.me() }.syncSession()

    override suspend fun update(name: String, phone: String?): ApiResult<Account> =
        safeApiCall {
            api.update(
                UpdateProfileRequestDto(
                    name = name.trim(),
                    phone = phone?.trim()?.takeIf { it.isNotEmpty() }?.let(::JsonPrimitive) ?: JsonNull,
                ),
            )
        }.syncSession()

    private suspend fun ApiResult<DataEnvelope<AccountDto>>.syncSession(): ApiResult<Account> = when (this) {
        is ApiResult.Failure -> this
        is ApiResult.Success -> {
            val account = value.data.toDomain()
            // Jawaban yang datang terlambat (pelanggan sudah keluar, atau akun lain sudah masuk)
            // tidak boleh menimpa akun di sesi yang sekarang.
            val current = session.state.value as? SessionState.SignedIn
            if (current?.account?.id == account.id) session.updateAccount(account)
            ApiResult.Success(account)
        }
    }
}
