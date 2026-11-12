package com.boothpos.customer.core.network

import kotlinx.serialization.json.Json
import okhttp3.Call
import okhttp3.MediaType.Companion.toMediaType
import retrofit2.Retrofit
import retrofit2.converter.kotlinx.serialization.asConverterFactory

object RetrofitFactory {
    /**
     * [callFactory] berupa lambda supaya OkHttpClient (inisialisasi TLS-nya mahal) baru dibuat pada
     * panggilan jaringan pertama, yang selalu terjadi di luar main thread.
     */
    fun create(config: NetworkConfig, json: Json, callFactory: Call.Factory): Retrofit =
        Retrofit.Builder()
            .baseUrl(config.httpUrl)
            .callFactory(callFactory)
            .addConverterFactory(json.asConverterFactory("application/json".toMediaType()))
            .build()
}
