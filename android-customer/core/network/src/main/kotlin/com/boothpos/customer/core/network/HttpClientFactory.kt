package com.boothpos.customer.core.network

import com.boothpos.customer.core.network.interceptor.AuthInterceptor
import com.boothpos.customer.core.network.interceptor.DefaultHeadersInterceptor
import java.util.concurrent.TimeUnit
import okhttp3.Interceptor
import okhttp3.OkHttpClient

object HttpClientFactory {
    /**
     * `retryOnConnectionFailure(false)`: OkHttp tidak boleh diam-diam mengulang permintaan. Checkout
     * yang terkirim dua kali hanya aman bila pengulangan itu disengaja dan membawa
     * `Idempotency-Key` yang sama; keputusan itu milik fitur checkout, bukan pustaka HTTP.
     *
     * `callTimeout(60 dtk)`: batas TOTAL satu panggilan (menyambung + menulis + membaca, termasuk
     * koneksi yang menetes byte demi byte), karena `readTimeout` hanya menghitung jeda antarbyte dan
     * panggilan yang terus "hidup" tanpa jawaban bisa menahan layar tanpa batas. Terlampaui, OkHttp
     * melempar `InterruptedIOException` yang `SafeApiCall` petakan ke `ApiError.Timeout`; untuk
     * checkout itu masuk jalur "status pesanan belum diketahui" (kirim ulang dengan kunci yang
     * sama), bukan "gagal". Angkanya sengaja di atas `connect + read` (10 + 30 dtk) supaya batas
     * per-tahap tetap yang lebih dulu berlaku pada jaringan yang sekadar lambat.
     *
     * [extraInterceptors] berisi logging teredaksi pada build debug dan kosong pada build rilis.
     */
    fun create(
        config: NetworkConfig,
        tokenProvider: TokenProvider,
        unauthorizedEvents: UnauthorizedEvents,
        extraInterceptors: List<Interceptor> = buildTypeInterceptors(),
    ): OkHttpClient {
        val builder = OkHttpClient.Builder()
            .connectTimeout(CONNECT_TIMEOUT_SECONDS, TimeUnit.SECONDS)
            .readTimeout(IO_TIMEOUT_SECONDS, TimeUnit.SECONDS)
            .writeTimeout(IO_TIMEOUT_SECONDS, TimeUnit.SECONDS)
            .callTimeout(CALL_TIMEOUT_SECONDS, TimeUnit.SECONDS)
            .retryOnConnectionFailure(false)
            // Pengalihan HTTPS -> HTTP tidak pernah diikuti (transport).
            .followSslRedirects(false)
            .addInterceptor(DefaultHeadersInterceptor())
            .addInterceptor(AuthInterceptor(config.httpUrl, tokenProvider, unauthorizedEvents))
        extraInterceptors.forEach(builder::addInterceptor)
        return builder.build()
    }

    const val CONNECT_TIMEOUT_SECONDS = 10L
    const val IO_TIMEOUT_SECONDS = 30L
    const val CALL_TIMEOUT_SECONDS = 60L
}
