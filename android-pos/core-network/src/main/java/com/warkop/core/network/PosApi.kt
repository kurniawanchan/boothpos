package com.warkop.core.network

import com.warkop.core.network.dto.*
import retrofit2.Response
import retrofit2.http.Body
import retrofit2.http.HTTP
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
    suspend fun products(@Query("page") page: Int, @Query("per_page") perPage: Int = 100, @Query("pos") pos: Int? = null): ProductPage
    /** 055 — kategori kasir menurut mode sistem (sumber kategori tablet). */
    @GET("pos/categories") suspend fun posCategories(): PosCategoriesPage

    /** `since` = etag terakhir; server menjawab 304 tanpa badan bila tak berubah. */
    @GET("menu/availability?pos=1")
    suspend fun availability(@Query("since") since: String?): Response<AvailabilityDto>

    // --- shift & kas (S2 / US3) ---
    @GET("sessions/current") suspend fun currentSession(): Response<SessionDto>
    @POST("sessions") suspend fun openSession(@Body body: OpenSessionRequest): SessionDto
    @GET("sessions/{id}/x-report") suspend fun xReport(@Path("id") id: Long): XReportDto
    @GET("sessions/{id}/cash-movements") suspend fun cashMovements(@Path("id") id: Long): CashMovementPage
    /** Kunci per niat: permintaan ulang dengan kunci sama tidak mencatat baris kedua (server: EnsureIdempotent). */
    @POST("sessions/{id}/cash-movements")
    suspend fun recordCashMovement(@Path("id") id: Long, @Header("Idempotency-Key") key: String, @Body body: CashMovementRequest): CashMovementDto
    /** Kunci per niat tutup: server menjawab ulang dengan hasil tersimpan bila permintaan diulang (bukan 409). */
    @POST("sessions/{id}/close")
    suspend fun closeSession(@Path("id") id: Long, @Header("Idempotency-Key") key: String, @Body body: CloseSessionRequest): ClosedSessionDto
    @GET("settings/features") suspend fun features(): FeaturesDto

    // --- persetujuan (S3 / US4) ---
    /** Penyetuju yang memenuhi syarat untuk satu aksi; server tidak menyertakan peminta. */
    @GET("overrides/approvers") suspend fun approvers(@Query("action") action: String): List<ApproverDto>
    @POST("overrides") suspend fun issueOverride(@Body body: OverrideRequest): OverrideResponse

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
    @POST("tabs/{id}/payments/{pay}/verify") suspend fun verifyPayment(@Path("id") id: Long, @Path("pay") pay: Long, @Body body: VerifyPaymentRequestDto): TabDto
    @POST("tabs/{id}/payments/{pay}/fail") suspend fun failPayment(@Path("id") id: Long, @Path("pay") pay: Long, @Body body: FailPaymentRequestDto): TabDto
    /** Void baris terkirim: DELETE dengan badan JSON (alasan, status dapur, keputusan stok, token persetujuan). */
    @HTTP(method = "DELETE", path = "tabs/{id}/items/{item}", hasBody = true)
    suspend fun voidLine(@Path("id") id: Long, @Path("item") item: Long, @Body body: VoidLineRequestDto): TabDto
    @POST("tabs/{id}/cancel") suspend fun cancelTab(@Path("id") id: Long, @Body body: CancelTabRequestDto): TabDto
    @POST("products/{id}/sold-out") suspend fun soldOut(@Path("id") id: Long, @Body body: SoldOutRequestDto): Response<Unit>
    @GET("reason-codes") suspend fun reasonCodes(@Query("category") category: String): ReasonCodesPage
    @GET("payment-channels") suspend fun paymentChannels(): PaymentChannelsPage
    /** 056 amandemen — unggah gambar QR kanal (multipart, field `qr_image`). Respons tidak dipakai; kanal dimuat ulang. */
    @retrofit2.http.Multipart
    @POST("payment-channels/{id}")
    suspend fun uploadQrImage(@Path("id") id: Long, @retrofit2.http.Part image: okhttp3.MultipartBody.Part): retrofit2.Response<okhttp3.ResponseBody>
    @POST("tabs/{id}/prebill") suspend fun prebill(@Path("id") id: Long): TabDto
    /** Heartbeat perangkat (US6). Tanpa Idempotency-Key: bukan tulisan bisnis, aman diulang. */
    @POST("devices/heartbeat") suspend fun heartbeat(@Body body: HeartbeatRequestDto): HeartbeatResponseDto
    /** 054 / US3 — alamat server yang dipilih admin; header X-Device-Id dipasang klien. Dipanggil sebelum login. */
    @GET("devices/server-address") suspend fun serverAddress(): ServerAddressDto
    /** Struk server (T038). Order yang masih terbuka menjawab 409; layar struk hanya dibuka untuk order selesai. */
    @GET("orders/{id}/receipt") suspend fun receipt(@Path("id") id: Long): ReceiptDto
    @GET("orders") suspend fun closedOrders(@Query("per_page") perPage: Int): ClosedOrdersPage
    /** 050 / T008 — satu tiket dapur untuk cetak ulang KOT. Server menjawab 403 bila pengguna tanpa akses dapur/denah. */
    @GET("tickets/{id}") suspend fun ticket(@Path("id") id: Long): TicketDto

    // --- diskon, bagi rata, cetak ulang, refund (048 / S4 — US5). Setiap tulis membawa Idempotency-Key. ---
    @POST("tabs/{id}/discounts")
    suspend fun setDiscount(@Path("id") id: Long, @Header("Idempotency-Key") key: String, @Body body: DiscountRequestDto): TabDto
    @DELETE("tabs/{id}/discounts")
    suspend fun clearDiscount(@Path("id") id: Long, @Header("Idempotency-Key") key: String): TabDto
    @POST("tabs/{id}/split")
    suspend fun splitEqual(@Path("id") id: Long, @Header("Idempotency-Key") key: String, @Body body: SplitEqualRequestDto): SplitEqualResponseDto
    /** Bagi per item: rute yang sama dengan bagi rata (kontrak yang sudah ada), tubuh berbeda. */
    @POST("tabs/{id}/split")
    suspend fun splitItems(@Path("id") id: Long, @Header("Idempotency-Key") key: String, @Body body: SplitItemsRequestDto): SplitItemsResponseDto
    @POST("orders/{id}/reprints")
    suspend fun reprint(@Path("id") id: Long, @Header("Idempotency-Key") key: String, @Body body: ReprintRequestDto): ReprintResponseDto
    @POST("orders/{id}/refund")
    suspend fun refund(@Path("id") id: Long, @Header("Idempotency-Key") key: String, @Body body: RefundRequestDto): TabDto
}
