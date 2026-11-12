package com.boothpos.customer.core.network

import com.boothpos.customer.core.network.dto.DataEnvelope
import com.boothpos.customer.core.network.dto.PagedEnvelope
import kotlinx.serialization.Serializable
import okhttp3.Interceptor
import okhttp3.OkHttpClient
import retrofit2.Response
import retrofit2.http.Body
import retrofit2.http.DELETE
import retrofit2.http.GET
import retrofit2.http.POST
import retrofit2.http.Path
import retrofit2.http.Url

@Serializable
data class NameDto(val name: String)

// Nilai jelas-palsu; tidak ada kredensial sungguhan di tes.
@Serializable
data class LoginBody(val email: String, val password: String)

interface TestApi {
    @GET("customer/me")
    suspend fun me(): DataEnvelope<NameDto>

    @GET("storefront/products")
    suspend fun products(): PagedEnvelope<NameDto>

    @POST("customer/auth/login")
    suspend fun login(@Body body: LoginBody): DataEnvelope<NameDto>

    @DELETE("customer/addresses/{id}")
    suspend fun deleteAddress(@Path("id") id: Long): Response<Unit>

    @GET
    suspend fun absolute(@Url url: String): DataEnvelope<NameDto>
}

fun testApi(
    baseUrl: String,
    tokenProvider: TokenProvider = TokenProvider { null },
    events: UnauthorizedEvents = UnauthorizedEvents(),
    extraInterceptors: List<Interceptor> = emptyList(),
    customize: OkHttpClient.Builder.() -> Unit = {},
): TestApi {
    val config = NetworkConfig(baseUrl)
    val client = HttpClientFactory.create(config, tokenProvider, events, extraInterceptors)
        .newBuilder().apply(customize).build()
    return RetrofitFactory.create(config, NetworkJson.create(), client).create(TestApi::class.java)
}
