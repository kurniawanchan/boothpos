package com.boothpos.customer.core.network.interceptor

import java.util.UUID
import okhttp3.Interceptor
import okhttp3.Response

/**
 * Header yang sama untuk setiap panggilan API. `X-Request-Id` dibuat di klien supaya satu
 * permintaan dapat dilacak di log server tanpa membawa data pribadi apa pun (server menerima
 * pola `^[A-Za-z0-9._-]{8,64}$`, UUID memenuhinya).
 */
class DefaultHeadersInterceptor(
    private val newRequestId: () -> String = { UUID.randomUUID().toString() },
) : Interceptor {
    override fun intercept(chain: Interceptor.Chain): Response {
        val original = chain.request()
        val builder = original.newBuilder()
            .header("Accept", "application/json")
            .header("Accept-Language", "id")
        if (original.header(REQUEST_ID) == null) {
            builder.header(REQUEST_ID, newRequestId())
        }
        return chain.proceed(builder.build())
    }

    companion object {
        const val REQUEST_ID = "X-Request-Id"
    }
}
