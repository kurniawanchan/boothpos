package com.boothpos.customer.core.network.error

import com.boothpos.customer.core.network.dto.ProblemDto
import com.boothpos.customer.core.network.interceptor.DefaultHeadersInterceptor
import javax.inject.Inject
import kotlinx.serialization.SerializationException
import kotlinx.serialization.json.Json
import kotlinx.serialization.json.JsonArray
import kotlinx.serialization.json.JsonObject
import kotlinx.serialization.json.JsonPrimitive
import retrofit2.Response

/**
 * Mengubah jawaban galat HTTP menjadi [ApiError.Http]. Jawaban server diperlakukan sebagai data
 * tak tepercaya: body dibatasi ukurannya, JSON yang rusak tidak pernah melempar keluar, dan body
 * mentah tidak pernah diteruskan ke UI.
 */
class ProblemJsonParser @Inject constructor(private val json: Json) {

    fun parse(response: Response<*>?): ApiError {
        if (response == null) return ApiError.Unknown
        val body = runCatching {
            response.errorBody()?.use { errorBody ->
                val source = errorBody.source()
                source.request(MAX_BODY_BYTES)
                source.buffer.snapshot(minOf(source.buffer.size, MAX_BODY_BYTES).toInt()).utf8()
            }
        }.getOrNull()
        return parse(
            status = response.code(),
            body = body,
            retryAfter = response.headers()["Retry-After"],
            requestIdHeader = response.headers()[DefaultHeadersInterceptor.REQUEST_ID],
        )
    }

    fun parse(status: Int, body: String?, retryAfter: String? = null, requestIdHeader: String? = null): ApiError.Http {
        val problem = body?.takeIf { it.isNotBlank() }?.let { raw ->
            try {
                json.decodeFromString(ProblemDto.serializer(), raw)
            } catch (_: SerializationException) {
                null
            } catch (_: IllegalArgumentException) {
                null
            }
        }
        val errors = problem?.errors as? JsonObject
        return ApiError.Http(
            status = status,
            code = problem?.code?.takeIf { it.isNotBlank() } ?: codeForStatus(status),
            // Hanya `detail` dari problem+json yang dianggap aman untuk ditampilkan. 423 (gerbang
            // lisensi instalasi) selalu tanpa teks server, juga bila kini datang sebagai problem+json
            // `installation_locked`: isinya urusan pemilik toko, bukan pelanggan.
            detail = problem?.takeIf { it.code != null && status != STATUS_INSTALLATION_LOCKED }
                ?.detail?.takeIf { it.isNotBlank() },
            fieldErrors = errors?.let(::fieldErrorsOf).orEmpty(),
            errors = errors,
            requestId = problem?.requestId ?: requestIdHeader,
            retryAfterSeconds = retryAfter?.trim()?.toLongOrNull()?.takeIf { it >= 0 },
        )
    }

    /**
     * Hanya entri berbentuk `"field": ["pesan", …]`; bentuk lain (`items`, `quote`) tetap di `errors`.
     *
     * Pesan yang berupa kunci terjemahan mentah (`validation.in`, `validation.custom.x`: server
     * yang kehilangan berkas bahasanya) dibuang, supaya layar tidak menampilkan kunci itu ke
     * pelanggan dan jatuh ke teks lokalnya sendiri. Kolomnya TETAP tercatat (dengan daftar kosong)
     * bila semua pesannya dibuang: penanda "kolom ini ditolak" tetap benar bagi layar yang hanya
     * memeriksa keberadaan kolom (mis. `address_id` di checkout).
     */
    private fun fieldErrorsOf(errors: JsonObject): Map<String, List<String>> =
        errors.mapNotNull { (field, value) ->
            val messages = (value as? JsonArray)
                ?.mapNotNull { (it as? JsonPrimitive)?.takeIf(JsonPrimitive::isString)?.content }
                .orEmpty()
            if (messages.isEmpty()) null else field to messages.filterNot(::isUntranslatedKey)
        }.toMap()

    private fun isUntranslatedKey(message: String): Boolean = UNTRANSLATED_KEY.containsMatchIn(message.trim())

    private fun codeForStatus(status: Int): String = when {
        status == 401 -> ApiErrorCodes.UNAUTHENTICATED
        status == 403 -> ApiErrorCodes.FORBIDDEN
        status == 404 -> ApiErrorCodes.NOT_FOUND
        status == 423 -> ApiErrorCodes.STORE_LOCKED
        status == 429 -> ApiErrorCodes.TOO_MANY_REQUESTS
        status >= 500 -> ApiErrorCodes.SERVER_ERROR
        else -> ApiErrorCodes.HTTP_ERROR
    }

    private companion object {
        const val MAX_BODY_BYTES = 64L * 1024
        const val STATUS_INSTALLATION_LOCKED = 423

        /** Kunci terjemahan Laravel yang bocor mentah, mis. `validation.in`. */
        val UNTRANSLATED_KEY = Regex("^validation\\.")
    }
}
