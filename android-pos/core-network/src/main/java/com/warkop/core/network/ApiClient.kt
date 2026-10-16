package com.warkop.core.network

import com.warkop.core.domain.ApiError
import kotlinx.coroutines.Dispatchers
import kotlinx.coroutines.withContext
import kotlinx.serialization.json.Json
import okhttp3.Interceptor
import okhttp3.MediaType.Companion.toMediaType
import okhttp3.OkHttpClient
import okhttp3.Response
import retrofit2.HttpException
import retrofit2.Retrofit
import retrofit2.converter.kotlinx.serialization.asConverterFactory
import java.util.concurrent.TimeUnit

/** Sumber nilai header yang berubah selama sesi; dibaca TIAP permintaan (token diperpanjang, outlet dipilih). */
class RequestContext(
    val token: () -> String?,
    val outletId: () -> Long?,
    val deviceId: () -> Long?,
)

/**
 * Klien HTTP (contracts/android-client.md): batas waktu 10/20 dtk; token + X-Outlet-Id + X-Device-Id; log HANYA
 * metode+path+status (tidak pernah header, badan, PIN, atau nomor kartu — [RedactedLogInterceptor]).
 */
object ApiClient {
    val json = Json {
        ignoreUnknownKeys = true   // server boleh menambah kolom
        explicitNulls = false
        coerceInputValues = true   // null pada kolom ber-nilai-bawaan → bawaan, bukan crash
        encodeDefaults = false
    }

    fun create(baseUrl: String, context: RequestContext, log: (String) -> Unit = {}): PosApi {
        val http = OkHttpClient.Builder()
            .connectTimeout(10, TimeUnit.SECONDS)
            .readTimeout(20, TimeUnit.SECONDS)
            .writeTimeout(20, TimeUnit.SECONDS)
            .addInterceptor(headers(context))
            .addInterceptor(RedactedLogInterceptor(log))
            .build()

        return Retrofit.Builder()
            .baseUrl(normalise(baseUrl))
            .client(http)
            .addConverterFactory(json.asConverterFactory("application/json".toMediaType()))
            .build()
            .create(PosApi::class.java)
    }

    /** "http://10.0.2.2:8010" → ".../api/v1/" (Retrofit butuh garis miring akhir). */
    fun normalise(url: String): String {
        // Alamat tanpa skema ("192.168.1.10:8010") dilengkapi http:// — instalasi lokal umumnya tanpa TLS.
        val withScheme = url.trim().let { if ("://" in it) it else "http://$it" }
        val trimmed = withScheme.trimEnd('/')
        return if (trimmed.endsWith("/api/v1")) "$trimmed/" else "$trimmed/api/v1/"
    }

    private fun headers(ctx: RequestContext) = Interceptor { chain ->
        val b = chain.request().newBuilder().header("Accept", "application/json")
        ctx.token()?.let { b.header("Authorization", "Bearer $it") }
        ctx.outletId()?.let { b.header("X-Outlet-Id", it.toString()) }
        ctx.deviceId()?.let { b.header("X-Device-Id", it.toString()) }
        chain.proceed(b.build())
    }
}

/** Mencatat "GET /tabs/12 → 200 (45 ms)" saja. Header dan badan tidak pernah disentuh. */
class RedactedLogInterceptor(private val log: (String) -> Unit) : Interceptor {
    override fun intercept(chain: Interceptor.Chain): Response {
        val request = chain.request()
        val start = System.nanoTime()
        return try {
            chain.proceed(request).also { log("${request.method} ${request.url.encodedPath} → ${it.code} (${(System.nanoTime() - start) / 1_000_000} ms)") }
        } catch (t: Throwable) {
            log("${request.method} ${request.url.encodedPath} → gagal (${t.javaClass.simpleName}: ${t.message?.take(120)} / ${t.cause?.javaClass?.simpleName}: ${t.cause?.message?.take(120)})")
            throw t
        }
    }
}

/**
 * Menjalankan panggilan Retrofit dan memetakan SEMUA kegagalan ke [ApiError].
 * Blok (termasuk penulisan body kotlinx.serialization dan pemuatan kelas DTO pertama kali) berjalan di Dispatchers.IO,
 * bukan di main thread: pada 2026-10-16 "Order counter baru" membuat UI membeku (ANR) karena verifikasi kelas
 * OpenTabRequestDto memakan 9,8 dtk di main thread.
 */
suspend fun <T> apiCall(block: suspend () -> T): T = try {
    withContext(Dispatchers.IO) { block() }
} catch (e: HttpException) {
    throw ApiErrorMapper.fromHttp(e.code(), runCatching { e.response()?.errorBody()?.string() }.getOrNull())
} catch (e: ApiError) {
    throw e
} catch (e: kotlinx.coroutines.CancellationException) {
    throw e
} catch (e: Throwable) {
    throw ApiErrorMapper.fromThrowable(e)
}
