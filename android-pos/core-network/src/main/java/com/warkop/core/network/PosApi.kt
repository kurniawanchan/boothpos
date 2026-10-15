package com.warkop.core.network

import com.warkop.core.network.dto.*
import retrofit2.Response
import retrofit2.http.Body
import retrofit2.http.DELETE
import retrofit2.http.GET
import retrofit2.http.Header
import retrofit2.http.PATCH
import retrofit2.http.POST
import retrofit2.http.Path
import retrofit2.http.Query

/**
 * Antarmuka Retrofit ke API server (docs/openapi-pos-mvp.yaml). Dipakai HANYA oleh RemoteBackend; layar tidak
 * mengenalnya. Bagian ditambah per irisan (identitas → katalog/denah → order → shift → persetujuan).
 */
interface PosApi {
    // --- identitas & perangkat (S1) ---
    @POST("auth/login") suspend fun login(@Body body: LoginRequest): LoginResponse
    @GET("me/scope") suspend fun scope(): ScopeResponse
    @GET("auth/me") suspend fun me(): AuthUserDto
    @POST("auth/logout") suspend fun logout(): retrofit2.Response<Unit>
    @POST("devices/pairings") suspend fun pairDevice(@Body body: PairDeviceRequest): DeviceDto
    @GET("staff/roster") suspend fun roster(@Query("outlet_id") outletId: Long): RosterResponse
    @POST("auth/pin-sessions") suspend fun pinLogin(@Body body: PinLoginRequest): PinLoginResponse
    @POST("auth/token-refresh") suspend fun refreshToken(): RefreshResponse

    // --- katalog (S1) ---
    @GET("products?pos=1&with_variants=1&with_modifiers=1")
    suspend fun products(@Query("page") page: Int, @Query("per_page") perPage: Int = 100): ProductPage

    /** `since` = etag terakhir; server menjawab 304 tanpa badan bila tak berubah. */
    @GET("menu/availability?pos=1")
    suspend fun availability(@Query("since") since: String?): Response<AvailabilityDto>

    // --- shift (dasar; pecahan/kas/X-report di S2) ---
    @GET("sessions/current") suspend fun currentSession(): Response<SessionDto>
    @POST("sessions") suspend fun openSession(@Body body: OpenSessionRequest): SessionDto

    // --- denah & pesanan (S1/US2) ---
    /** `version` = penanda denah terakhir; jawaban `unchanged=true` bila tak berubah. */
    @GET("floor") suspend fun floor(@Query("version") version: String?): FloorDto
    @POST("tabs") suspend fun openTab(@Body body: OpenTabRequestDto): TabDto
    @GET("tabs/{id}") suspend fun tab(@Path("id") id: Long): TabDto
    @POST("tabs/{id}/items")
    suspend fun addLine(@Path("id") id: Long, @Header("Idempotency-Key") key: String, @Body body: AddLineRequestDto): TabDto
    @PATCH("tabs/{id}/items/{item}")
    suspend fun updateLine(@Path("id") id: Long, @Path("item") item: Long, @Header("Idempotency-Key") key: String, @Body body: UpdateLineRequestDto): TabDto
    @DELETE("tabs/{id}/items/{item}")
    suspend fun removeLine(@Path("id") id: Long, @Path("item") item: Long, @Header("Idempotency-Key") key: String): TabDto
    @POST("tabs/{id}/send") suspend fun send(@Path("id") id: Long, @Header("Idempotency-Key") key: String): SendResponseDto
    @POST("tabs/{id}/payments") suspend fun pay(@Path("id") id: Long, @Body body: PayRequestDto): TabDto
    @POST("tabs/{id}/prebill") suspend fun prebill(@Path("id") id: Long): TabDto
    @GET("orders") suspend fun closedOrders(@Query("per_page") perPage: Int): ClosedOrdersPage
}
