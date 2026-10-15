package com.warkop.core.network

import com.warkop.core.network.dto.*
import retrofit2.http.Body
import retrofit2.http.GET
import retrofit2.http.POST
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
}
