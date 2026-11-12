package com.boothpos.customer.core.data.remote

import retrofit2.Response
import retrofit2.http.Header
import retrofit2.http.POST

interface SessionApi {
    /**
     * Token dikirim eksplisit: saat logout dipanggil, token sudah dihapus dari perangkat, jadi
     * interseptor tidak lagi dapat menempelkannya.
     */
    @POST("customer/auth/logout")
    suspend fun logout(@Header("Authorization") bearer: String): Response<Unit>
}
