package com.boothpos.customer.core.network

import okhttp3.HttpUrl
import okhttp3.HttpUrl.Companion.toHttpUrl

/**
 * Alamat dasar API (`…/api/v1/`). Nilainya datang dari BuildConfig milik :app (properti Gradle
 * `boothpos.apiBaseUrl`), tidak pernah ditulis per lingkungan di dalam kode.
 */
data class NetworkConfig(val baseUrl: String) {
    init {
        require(baseUrl.endsWith("/")) { "Base URL API harus diakhiri '/'" }
    }

    val httpUrl: HttpUrl = baseUrl.toHttpUrl()
}
