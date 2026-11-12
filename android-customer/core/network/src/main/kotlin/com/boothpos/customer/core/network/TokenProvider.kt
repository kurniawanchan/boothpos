package com.boothpos.customer.core.network

/**
 * Sumber token pelanggan untuk [com.boothpos.customer.core.network.interceptor.AuthInterceptor].
 * Dipanggil di thread OkHttp, jadi harus sinkron. Implementasinya ada di :core:data (penyimpan
 * terenkripsi Keystore); modul ini tidak tahu cara token disimpan.
 */
fun interface TokenProvider {
    fun currentToken(): String?
}
