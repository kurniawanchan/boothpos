package com.boothpos.customer.feature.auth.data

import com.boothpos.customer.core.data.di.ApplicationScope
import com.boothpos.customer.core.data.domain.model.Account
import com.boothpos.customer.core.data.domain.session.SessionRepository
import com.boothpos.customer.core.data.domain.time.IsoTime
import com.boothpos.customer.core.data.remote.toDomain
import com.boothpos.customer.core.data.session.TokenRevoker
import com.boothpos.customer.core.network.error.ApiError
import com.boothpos.customer.core.network.error.ApiResult
import com.boothpos.customer.core.network.error.SafeApiCall
import com.boothpos.customer.feature.auth.domain.AuthRepository
import com.boothpos.customer.feature.auth.domain.CredentialRules
import com.boothpos.customer.feature.auth.domain.RegisterInput
import javax.inject.Inject
import kotlinx.coroutines.CancellationException
import kotlinx.coroutines.CoroutineScope
import kotlinx.coroutines.launch

class AuthRepositoryImpl @Inject constructor(
    private val api: AuthApi,
    private val safeApiCall: SafeApiCall,
    private val session: SessionRepository,
    private val tokenRevoker: TokenRevoker,
    @ApplicationScope private val scope: CoroutineScope,
) : AuthRepository {

    override suspend fun register(input: RegisterInput): ApiResult<Account> =
        safeApiCall {
            api.register(
                RegisterRequestDto(
                    name = input.name.trim(),
                    email = CredentialRules.normaliseEmail(input.email),
                    // Kata sandi dikirim apa adanya: spasi di awal/akhir adalah bagian dari kata sandi.
                    password = input.password,
                    phone = input.phone?.trim()?.takeIf { it.isNotEmpty() },
                ),
            )
        }.startSession()

    override suspend fun signIn(email: String, password: String): ApiResult<Account> =
        safeApiCall {
            api.login(LoginRequestDto(email = CredentialRules.normaliseEmail(email), password = password))
        }.startSession()

    /**
     * Token hanya lewat di sini: langsung diserahkan ke [SessionRepository] (penyimpanan
     * terenkripsi) dan tidak pernah dikembalikan ke pemanggil.
     *
     * Menyimpan sesi bisa gagal di perangkat (disk penuh, berkas/kunci Keystore bermasalah) padahal
     * server sudah menjawab sukses. Itu BUKAN crash: hasilnya [ApiError.SessionStorage] (pesan
     * tenang "coba lagi"), dan token yang tidak sempat tersimpan dicabut di server (upaya terbaik),
     * karena tanpa itu ia menjadi kredensial yatim yang masih berlaku tetapi tak dimiliki siapa pun.
     */
    private suspend fun ApiResult<AuthResponseDto>.startSession(): ApiResult<Account> = when (this) {
        is ApiResult.Failure -> this
        is ApiResult.Success -> {
            if (value.token.isBlank()) {
                // Jawaban sukses tanpa token tidak dapat dipakai; jangan membuat sesi kosong.
                ApiResult.Failure(ApiError.Unknown)
            } else {
                val account = value.account.toDomain()
                try {
                    session.startSession(value.token, IsoTime.parse(value.expiresAt), account)
                    ApiResult.Success(account)
                } catch (e: CancellationException) {
                    throw e
                } catch (_: Exception) {
                    // Sengaja tanpa log dan tanpa membawa pengecualiannya: pesan kripto/IO tidak
                    // boleh memuat isi berkas, apalagi token.
                    revokeUnstored(value.token)
                    ApiResult.Failure(ApiError.SessionStorage)
                }
            }
        }
    }

    /** Di scope aplikasi dan tanpa ditunggu: layar tidak boleh menahan galat sampai jaringan menjawab. */
    private fun revokeUnstored(token: String) {
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
