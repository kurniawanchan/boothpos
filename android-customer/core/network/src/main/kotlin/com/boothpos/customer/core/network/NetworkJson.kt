package com.boothpos.customer.core.network

import kotlinx.serialization.json.Json

object NetworkJson {
    /**
     * `ignoreUnknownKeys`: server boleh menambah field tanpa merusak aplikasi lama.
     * `explicitNulls = false`: field nullable yang tidak dikirim server terbaca sebagai null, dan
     * null tidak ikut dikirim pada body permintaan.
     */
    fun create(): Json = Json {
        ignoreUnknownKeys = true
        explicitNulls = false
    }
}
