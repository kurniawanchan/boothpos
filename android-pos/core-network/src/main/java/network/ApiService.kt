package com.warkop.core.network

import retrofit2.http.GET
import retrofit2.http.Path

// Placeholder Retrofit API service
interface ApiService {
    // Example endpoint
    @GET("/example/{id}")
    suspend fun getExample(@Path("id") id: String): String
}
