package com.boothpos.customer.core.network

import com.boothpos.customer.core.network.interceptor.AuthInterceptor
import okhttp3.Interceptor
import okhttp3.logging.HttpLoggingInterceptor

/**
 * Varian DEBUG: satu baris per permintaan/jawaban (metode, URL, status, durasi). Level BASIC tidak
 * mencetak header maupun body, jadi kredensial, alamat, dan token tidak pernah masuk logcat;
 * `redactHeader` tetap dipasang sebagai pengaman bila level suatu saat dinaikkan saat debugging.
 */
internal fun buildTypeInterceptors(
    logger: HttpLoggingInterceptor.Logger = HttpLoggingInterceptor.Logger.DEFAULT,
): List<Interceptor> = listOf(
    HttpLoggingInterceptor(logger).apply {
        level = HttpLoggingInterceptor.Level.BASIC
        redactHeader(AuthInterceptor.AUTHORIZATION)
        redactHeader("Idempotency-Key")
    },
)
