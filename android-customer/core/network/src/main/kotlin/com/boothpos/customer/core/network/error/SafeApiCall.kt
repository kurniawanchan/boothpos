package com.boothpos.customer.core.network.error

import java.io.IOException
import java.io.InterruptedIOException
import javax.inject.Inject
import kotlinx.coroutines.CancellationException
import retrofit2.HttpException
import retrofit2.Response

/**
 * Pembungkus tunggal untuk setiap panggilan Retrofit: pengecualian HTTP/IO berubah menjadi
 * [ApiError] di sini dan tidak di tempat lain. Pembatalan coroutine selalu diteruskan.
 *
 * Pemakaian di repository: `safeApiCall { api.me() }.map { it.data.toDomain() }`.
 */
class SafeApiCall @Inject constructor(private val problemParser: ProblemJsonParser) {

    suspend operator fun <T> invoke(block: suspend () -> T): ApiResult<T> = try {
        ApiResult.Success(block())
    } catch (e: CancellationException) {
        throw e
    } catch (e: HttpException) {
        ApiResult.Failure(problemParser.parse(e.response()))
    } catch (_: InterruptedIOException) {
        // Mencakup SocketTimeoutException dan batas waktu panggilan OkHttp.
        ApiResult.Failure(ApiError.Timeout)
    } catch (_: IOException) {
        ApiResult.Failure(ApiError.Network)
    } catch (_: Exception) {
        // Mis. SerializationException: detailnya sengaja tidak dibawa keluar (lihat ApiError).
        ApiResult.Failure(ApiError.Unknown)
    }

    /** Untuk endpoint tanpa body jawaban (204): logout, hapus alamat. */
    suspend fun unit(block: suspend () -> Response<Unit>): ApiResult<Unit> = invoke {
        val response = block()
        if (!response.isSuccessful) throw HttpException(response)
    }
}
