package com.warkop.manager.data

import com.warkop.core.network.NoOutletHeader
import com.warkop.core.network.dto.AuthUserDto
import com.warkop.core.network.dto.BrandingDto
import com.warkop.core.network.dto.FeaturesDto
import com.warkop.core.network.dto.ServerAddressDto
import com.warkop.core.network.dto.SetupOutletsResponse
import com.warkop.manager.data.dto.AttentionDto
import com.warkop.manager.data.dto.TablePage
import com.warkop.manager.data.dto.TableBody
import com.warkop.manager.data.dto.DiningTableDto
import com.warkop.manager.data.dto.QrBatchBody
import com.warkop.manager.data.dto.QrBatchDto
import com.warkop.manager.data.dto.QrUrlDto
import com.warkop.manager.data.dto.StationBody
import com.warkop.manager.data.dto.ModifierGroupDto
import com.warkop.manager.data.dto.AttachBody
import com.warkop.manager.data.dto.TicketFeedDto
import com.warkop.manager.data.dto.TicketStatusBody
import com.warkop.manager.data.dto.VendorDto
import com.warkop.manager.data.dto.VendorBody
import com.warkop.manager.data.dto.MaterialDto
import com.warkop.manager.data.dto.MaterialBody
import com.warkop.manager.data.dto.MaterialAdjustBody
import com.warkop.manager.data.dto.MaterialAdjustResult
import com.warkop.manager.data.dto.MaterialMovementDto
import com.warkop.manager.data.dto.VendorPriceBody
import com.warkop.manager.data.dto.VendorPriceDto
import com.warkop.manager.data.dto.PurchaseOrderDto
import com.warkop.manager.data.dto.PoBody
import com.warkop.manager.data.dto.PoStatusBody
import com.warkop.manager.data.dto.PoPaymentBody
import com.warkop.manager.data.dto.ModifierGroupBriefDto
import com.warkop.manager.data.dto.LinksSaved
import com.warkop.manager.data.dto.RecipeRowDto
import com.warkop.manager.data.dto.RecipeDetailDto
import com.warkop.manager.data.dto.RecipeBody
import com.warkop.manager.data.dto.ModifierOptionGroupDto
import com.warkop.manager.data.dto.IngredientProductDto
import com.warkop.manager.data.dto.MaterialPickDto
import com.warkop.manager.data.dto.ProductPage
import com.warkop.manager.data.dto.ProductSummaryDto
import com.warkop.manager.data.dto.ProductDetailDto
import com.warkop.manager.data.dto.PriceHistoryDto
import com.warkop.manager.data.dto.ProductBody
import com.warkop.manager.data.dto.ProductSavedDto
import com.warkop.manager.data.dto.ProductVariantDto
import com.warkop.manager.data.dto.VariantBody
import com.warkop.manager.data.dto.ReasonCodeDto
import com.warkop.manager.data.dto.ReasonBody
import com.warkop.manager.data.dto.OutletPricesDto
import com.warkop.manager.data.dto.OutletPriceBody
import com.warkop.manager.data.dto.OutletPriceResult
import com.warkop.manager.data.dto.CategoryBody
import com.warkop.manager.data.dto.CategoryDto
import com.warkop.manager.data.dto.DataList
import com.warkop.manager.data.dto.Paged
import com.warkop.manager.data.dto.StationDto
import com.warkop.manager.data.dto.AdjustBody
import com.warkop.manager.data.dto.LowStockDto
import com.warkop.manager.data.dto.MaterialBriefDto
import com.warkop.manager.data.dto.NewTransferBody
import com.warkop.manager.data.dto.ReceiveBody
import com.warkop.manager.data.dto.ScopeDto
import com.warkop.manager.data.dto.StockMovementDto
import com.warkop.manager.data.dto.TransferDetailDto
import com.warkop.manager.data.dto.TransferSummaryDto
import com.warkop.manager.data.dto.VariantLookupDto
import com.warkop.manager.data.dto.DashboardOrdersDto
import com.warkop.manager.data.dto.DashboardSummaryDto
import com.warkop.manager.data.dto.ManagerLoginRequest
import com.warkop.manager.data.dto.ManagerLoginResponse
import com.warkop.manager.data.dto.OpenShiftsDto
import kotlinx.serialization.json.JsonObject
import okhttp3.MultipartBody
import retrofit2.Response
import retrofit2.http.Body
import retrofit2.http.DELETE
import retrofit2.http.GET
import retrofit2.http.Multipart
import retrofit2.http.PATCH
import retrofit2.http.POST
import retrofit2.http.PUT
import retrofit2.http.Part
import retrofit2.http.Path
import retrofit2.http.Query

/**
 * 094 — antarmuka Retrofit aplikasi manajemen (kontrak `contracts/manager-api.md` bagian B). Endpoint SAMA dengan yang
 * dipakai web; tidak ada endpoint khusus selain login. Ditambah per modul oleh tugas berikutnya.
 */
interface ManagerApi {
    // --- B0: sambungan & sesi ---
    @GET("pos-setup/branding") suspend fun branding(): BrandingDto
    @GET("server-address") suspend fun serverAddress(): ServerAddressDto
    @GET("pos-setup/outlets") suspend fun setupOutlets(): SetupOutletsResponse
    @GET("pos-setup/outlets/{id}/branding") suspend fun outletBranding(@Path("id") outletId: Long): BrandingDto
    @POST("auth/manager-sessions") suspend fun login(@Body body: ManagerLoginRequest): ManagerLoginResponse
    @GET("auth/me") suspend fun me(): AuthUserDto
    @GET("settings/features") suspend fun features(): FeaturesDto
    @POST("auth/logout") suspend fun logout(): Response<Unit>

    // --- B1: Dashboard (menu `dashboard`). Ekspor CSV sengaja tidak ada di aplikasi. ---
    @GET("dashboard/summary") suspend fun dashboardSummary(@Query("period") period: String): DashboardSummaryDto
    @GET("dashboard/attention") suspend fun dashboardAttention(): AttentionDto
    @GET("dashboard/shifts") suspend fun dashboardShifts(): OpenShiftsDto
    /** Mentah: kunci rupiah yang TIDAK ADA (tanpa menu `reports`) harus dibedakan dari yang bernilai null. */
    @GET("dashboard/materials") suspend fun dashboardMaterials(@Query("period") period: String): JsonObject
    @GET("dashboard/orders") suspend fun dashboardOrders(
        @Query("period") period: String,
        @Query("tab") tab: String = "all",
        @Query("page") page: Int = 1,
        @Query("per_page") perPage: Int = 20,
    ): DashboardOrdersDto

    // --- B4: Kategori (izin lewat CategoryPolicy di server). ---
    @GET("categories") suspend fun categories(
        @Query("search") search: String? = null,
        @Query("page") page: Int = 1,
        @Query("per_page") perPage: Int = 25,
    ): Paged<CategoryDto>
    @GET("categories/{id}") suspend fun category(@Path("id") id: Long): CategoryDto
    @POST("categories") suspend fun createCategory(@Body body: CategoryBody): CategoryDto
    @PUT("categories/{id}") suspend fun updateCategory(@Path("id") id: Long, @Body body: CategoryBody): CategoryDto
    /** Server MENONAKTIFKAN (hapus lunak); 409 bila masih ada sub-kategori/produk aktif. */
    @DELETE("categories/{id}") suspend fun deleteCategory(@Path("id") id: Long): Response<Unit>
    @Multipart @POST("categories/{id}/image") suspend fun uploadCategoryImage(@Path("id") id: Long, @Part image: MultipartBody.Part): CategoryDto

    /** Cakupan outlet pengguna. Web memanggilnya tanpa X-Outlet-Id (skipOutletHeader); di sini header justru tidak menyempitkan daftar ini. */
    @GET("me/scope") suspend fun scope(): ScopeDto

    // --- B6: Stok (menu `stock`). Outlet = header X-Outlet-Id (outlet terpilih). ---
    @GET("stock/movements") suspend fun stockMovements(
        @Query("type") type: String? = null,
        @Query("page") page: Int = 1,
        @Query("per_page") perPage: Int = 25,
    ): Paged<StockMovementDto>
    @GET("stock/low") suspend fun lowStock(): DataList<LowStockDto>
    @POST("stock/adjustments") suspend fun adjustStock(@Body body: AdjustBody): Response<Unit>
    @GET("variants/lookup") suspend fun lookupVariants(
        @Query("q") q: String,
        @Query("limit") limit: Int = 8,
        @Query("stock_outlet") stockOutlet: Long? = null,
    ): DataList<VariantLookupDto>
    @GET("materials") suspend fun searchMaterials(@Query("search") search: String, @Query("per_page") perPage: Int = 8): Paged<MaterialBriefDto>
    @GET("stock/transfers") suspend fun transfers(@Query("status") status: String? = null, @Query("page") page: Int = 1): Paged<TransferSummaryDto>
    @GET("stock/transfers/{id}") suspend fun transfer(@Path("id") id: Long): TransferDetailDto
    @POST("stock/transfers") suspend fun createTransfer(@Body body: NewTransferBody): TransferSummaryDto
    @POST("stock/transfers/{id}/receive") suspend fun receiveTransfer(@Path("id") id: Long, @Body body: ReceiveBody): TransferSummaryDto
    @POST("stock/transfers/{id}/cancel") suspend fun cancelTransfer(@Path("id") id: Long): TransferSummaryDto

    // --- B5: Produk. Daftar & ringkasan TANPA X-Outlet-Id + `outlet_id[]` (pola 070, @NoOutletHeader). ---
    @NoOutletHeader @GET("products") suspend fun products(
        @Query("tab") tab: String,
        @Query("outlet_id[]") outletIds: List<Long>,
        @Query("q") q: String? = null,
        @Query("status[]") status: List<String> = emptyList(),
        @Query("category_id[]") categoryIds: List<Long> = emptyList(),
        @Query("page") page: Int = 1,
        @Query("per_page") perPage: Int = 15,
    ): ProductPage
    @NoOutletHeader @GET("products/summary") suspend fun productSummary(@Query("outlet_id[]") outletIds: List<Long>): ProductSummaryDto
    @GET("products/{id}") suspend fun product(@Path("id") id: Long): ProductDetailDto
    @GET("products/{id}/price-history") suspend fun priceHistory(@Path("id") id: Long, @Query("page") page: Int = 1): Paged<PriceHistoryDto>
    @POST("products") suspend fun createProduct(@Body body: ProductBody): ProductSavedDto
    @PUT("products/{id}") suspend fun updateProduct(@Path("id") id: Long, @Body body: ProductBody): ProductSavedDto
    @POST("products/{id}/variants") suspend fun createVariant(@Path("id") productId: Long, @Body body: VariantBody): ProductVariantDto
    @PUT("variants/{id}") suspend fun updateVariant(@Path("id") id: Long, @Body body: VariantBody): Response<Unit>
    @Multipart @POST("products/{id}/image") suspend fun productImage(@Path("id") id: Long, @Part image: MultipartBody.Part): Response<Unit>
    @Multipart @POST("variants/{id}/image") suspend fun variantImage(@Path("id") id: Long, @Part image: MultipartBody.Part): Response<Unit>
    @GET("reason-codes") suspend fun reasonCodes(@Query("category") category: String): DataList<ReasonCodeDto>
    @POST("products/{id}/sold-out") suspend fun markSoldOut(@Path("id") id: Long, @Body body: ReasonBody): Response<Unit>
    @DELETE("products/{id}/sold-out") suspend fun undoSoldOut(@Path("id") id: Long): Response<Unit>
    @POST("products/{id}/discontinue") suspend fun discontinue(@Path("id") id: Long, @Body body: ReasonBody): Response<Unit>
    @POST("products/{id}/reactivate") suspend fun reactivate(@Path("id") id: Long, @Body body: Map<String, String> = emptyMap()): Response<Unit>
    @GET("variants/{id}/outlet-prices") suspend fun outletPrices(@Path("id") variantId: Long): OutletPricesDto
    @PUT("variants/{id}/outlet-prices/{outlet}") suspend fun setOutletPrice(@Path("id") variantId: Long, @Path("outlet") outletId: Long, @Body body: OutletPriceBody): OutletPriceResult

    // --- B5 lanjutan: tautan modifier (T068) dan resep (T069). ---
    @GET("modifier-groups") suspend fun modifierGroups(): DataList<ModifierGroupBriefDto>
    @PUT("products/{id}/modifier-links") suspend fun saveModifierLinks(@Path("id") productId: Long, @Body body: com.warkop.manager.domain.LinksBody): LinksSaved
    @GET("products/{id}/recipes") suspend fun recipes(@Path("id") productId: Long): DataList<RecipeRowDto>
    @GET("recipes/{id}") suspend fun recipe(@Path("id") id: Long): RecipeDetailDto
    @POST("products/{id}/recipes") suspend fun createRecipe(@Path("id") productId: Long, @Body body: RecipeBody): RecipeDetailDto
    @PUT("recipes/{id}") suspend fun updateRecipe(@Path("id") id: Long, @Body body: RecipeBody): RecipeDetailDto
    @DELETE("recipes/{id}") suspend fun deleteRecipe(@Path("id") id: Long): Response<Unit>
    @GET("products/{id}/recipe-modifier-options") suspend fun recipeModifierOptions(@Path("id") productId: Long): DataList<ModifierOptionGroupDto>
    @GET("recipe-ingredients") suspend fun recipeIngredients(
        @Query("exclude_product_id") excludeProductId: Long,
        @Query("search") search: String? = null,
        @Query("per_page") perPage: Int = 30,
    ): Paged<IngredientProductDto>
    @GET("materials") suspend fun materialsForRecipe(
        @Query("search") search: String? = null,
        @Query("is_active") active: Int = 1,
        @Query("per_page") perPage: Int = 30,
    ): Paged<MaterialPickDto>

    // --- B7: Vendor (menu `vendors`). ---
    @GET("vendors") suspend fun vendors(@Query("search") search: String? = null, @Query("is_active") active: Int? = null, @Query("page") page: Int = 1, @Query("per_page") perPage: Int = 25): Paged<VendorDto>
    @POST("vendors") suspend fun createVendor(@Body body: VendorBody): VendorDto
    @PUT("vendors/{id}") suspend fun updateVendor(@Path("id") id: Long, @Body body: VendorBody): VendorDto
    @GET("vendors/{id}") suspend fun vendor(@Path("id") id: Long): VendorDto
    @DELETE("vendors/{id}") suspend fun deleteVendor(@Path("id") id: Long): Response<Unit>

    // --- B8: Bahan (menu `materials`). Saldo `outlet_stock` disaring outlet header. ---
    @GET("materials") suspend fun materials(
        @Query("search") search: String? = null,
        @Query("kind") kind: String? = null,
        @Query("stock_status") stockStatus: String? = null,
        @Query("page") page: Int = 1,
        @Query("per_page") perPage: Int = 25,
    ): Paged<MaterialDto>
    @GET("materials/{id}") suspend fun material(@Path("id") id: Long): MaterialDto
    @POST("materials") suspend fun createMaterial(@Body body: MaterialBody): MaterialDto
    @PUT("materials/{id}") suspend fun updateMaterial(@Path("id") id: Long, @Body body: MaterialBody): MaterialDto
    @DELETE("materials/{id}") suspend fun deleteMaterial(@Path("id") id: Long): Response<Unit>
    @POST("materials/{id}/adjustments") suspend fun adjustMaterial(@Path("id") id: Long, @Body body: MaterialAdjustBody): MaterialAdjustResult
    @GET("materials/{id}/movements") suspend fun materialMovements(@Path("id") id: Long, @Query("page") page: Int = 1): Paged<MaterialMovementDto>
    @POST("materials/{id}/vendor-prices") suspend fun createVendorPrice(@Path("id") materialId: Long, @Body body: VendorPriceBody): VendorPriceDto
    @PUT("vendor-prices/{id}") suspend fun updateVendorPrice(@Path("id") id: Long, @Body body: VendorPriceBody): VendorPriceDto
    @DELETE("vendor-prices/{id}") suspend fun deleteVendorPrice(@Path("id") id: Long): Response<Unit>

    // --- B9: PO (menu `purchase_orders`). Outlet PO dari BODY, bukan header. ---
    @GET("purchase-orders") suspend fun purchaseOrders(@Query("status") status: String? = null, @Query("outlet_id") outletId: Long? = null, @Query("page") page: Int = 1, @Query("per_page") perPage: Int = 25): Paged<PurchaseOrderDto>
    @GET("purchase-orders/{id}") suspend fun purchaseOrder(@Path("id") id: Long): PurchaseOrderDto
    @POST("purchase-orders") suspend fun createPurchaseOrder(@Body body: PoBody): PurchaseOrderDto
    @PUT("purchase-orders/{id}") suspend fun updatePurchaseOrder(@Path("id") id: Long, @Body body: PoBody): PurchaseOrderDto
    @DELETE("purchase-orders/{id}") suspend fun deletePurchaseOrder(@Path("id") id: Long): Response<Unit>
    @PATCH("purchase-orders/{id}/status") suspend fun setPurchaseOrderStatus(@Path("id") id: Long, @Body body: PoStatusBody): PurchaseOrderDto
    @POST("purchase-orders/{id}/payments") suspend fun payPurchaseOrder(@Path("id") id: Long, @Body body: PoPaymentBody): PurchaseOrderDto

    // --- B2: Dapur & Bar (menu `kitchen`; antar = `kitchen` atau `floor`). ---
    @GET("stations/{id}/tickets") suspend fun tickets(@Path("id") stationId: Long, @Query("version") version: String? = null): TicketFeedDto
    @PATCH("ticket-items/{id}/status") suspend fun setTicketItemStatus(@Path("id") itemId: Long, @Body body: TicketStatusBody): Response<Unit>

    // --- B3: Pengaturan Restoran (menu `dining_setup`). Daftar meja/stasiun TANPA header + `outlet_id[]` (pola 071). ---
    @NoOutletHeader @GET("dining-tables") suspend fun diningTables(
        @Query("outlet_id[]") outletIds: List<Long>,
        @Query("q") q: String? = null,
        @Query("page") page: Int = 1,
        @Query("per_page") perPage: Int = 25,
    ): TablePage
    @POST("dining-tables") suspend fun createTable(@Body body: TableBody): DiningTableDto
    @PUT("dining-tables/{id}") suspend fun updateTable(@Path("id") id: Long, @Body body: TableBody): DiningTableDto
    @DELETE("dining-tables/{id}") suspend fun deleteTable(@Path("id") id: Long): Response<Unit>
    @NoOutletHeader @POST("dining-tables/qr-batch") suspend fun tableQr(@Body body: QrBatchBody): QrBatchDto
    @NoOutletHeader @POST("dining-tables/{id}/qr/rotate") suspend fun rotateTableQr(@Path("id") id: Long): QrUrlDto
    @NoOutletHeader @GET("stations") suspend fun stationsAdmin(@Query("outlet_id[]") outletIds: List<Long>): DataList<StationDto>
    @POST("stations") suspend fun createStation(@Body body: StationBody): StationDto
    @PUT("stations/{id}") suspend fun updateStation(@Path("id") id: Long, @Body body: StationBody): StationDto
    @DELETE("stations/{id}") suspend fun deleteStation(@Path("id") id: Long): Response<Unit>
    @GET("modifier-groups") suspend fun modifierGroupsFull(): DataList<ModifierGroupDto>
    @POST("modifier-groups") suspend fun createModifierGroup(@Body body: JsonObject): ModifierGroupDto
    @PUT("modifier-groups/{id}") suspend fun updateModifierGroup(@Path("id") id: Long, @Body body: JsonObject): ModifierGroupDto
    @DELETE("modifier-groups/{id}") suspend fun deleteModifierGroup(@Path("id") id: Long): Response<Unit>
    @PUT("modifier-groups/{id}/attachments") suspend fun attachModifierGroup(@Path("id") id: Long, @Body body: AttachBody): ModifierGroupDto

    /** Stasiun aktif outlet terpilih (header X-Outlet-Id), untuk pemilih stasiun. */
    @GET("stations") suspend fun stations(@Query("active") active: Int = 1): DataList<StationDto>
}
