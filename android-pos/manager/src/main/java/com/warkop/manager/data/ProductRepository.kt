package com.warkop.manager.data

import android.net.Uri
import com.warkop.core.network.apiCall
import com.warkop.manager.data.dto.AdjustBody
import com.warkop.manager.data.dto.CategoryDto
import com.warkop.manager.data.dto.OutletPriceBody
import com.warkop.manager.data.dto.OutletPriceResult
import com.warkop.manager.data.dto.OutletPricesDto
import com.warkop.manager.data.dto.OutletRef
import com.warkop.manager.data.dto.Paged
import com.warkop.manager.data.dto.PriceHistoryDto
import com.warkop.manager.data.dto.ProductBody
import com.warkop.manager.data.dto.ProductDetailDto
import com.warkop.manager.data.dto.ProductPage
import com.warkop.manager.data.dto.ProductSavedDto
import com.warkop.manager.data.dto.ProductSummaryDto
import com.warkop.manager.data.dto.ProductVariantDto
import com.warkop.manager.data.dto.ReasonBody
import com.warkop.manager.data.dto.ReasonCodeDto
import com.warkop.manager.data.dto.StationDto
import com.warkop.manager.data.dto.VariantBody
import com.warkop.manager.domain.ProductSaveApi
import okhttp3.MultipartBody

/** 094 / T062 — Produk. Daftar/ringkasan memilih outlet lewat `outlet_id[]` tanpa header (pola 070). */
class ProductRepository(
    private val api: () -> ManagerApi,
    /** Foto terpilih → bagian multipart `image` (layar: `ImageUpload.part(resolver, uri)`). */
    private val photoPart: (Uri) -> MultipartBody.Part = { error("foto tidak tersedia") },
) : ProductSaveApi {
    suspend fun page(tab: String, outletIds: List<Long>, q: String?, status: List<String>, page: Int): ProductPage =
        apiCall { api().products(tab, outletIds, q?.takeIf { it.isNotBlank() }, status, page = page) }

    suspend fun summary(outletIds: List<Long>): ProductSummaryDto = apiCall { api().productSummary(outletIds) }
    suspend fun detail(id: Long): ProductDetailDto = apiCall { api().product(id) }
    suspend fun priceHistory(id: Long, page: Int): Paged<PriceHistoryDto> = apiCall { api().priceHistory(id, page) }

    suspend fun reasonCodes(category: String): List<ReasonCodeDto> = apiCall { api().reasonCodes(category) }.data.filter { it.isActive }
    suspend fun markSoldOut(id: Long, body: ReasonBody) = apiUnit { api().markSoldOut(id, body) }
    suspend fun undoSoldOut(id: Long) = apiUnit { api().undoSoldOut(id) }
    suspend fun discontinue(id: Long, body: ReasonBody) = apiUnit { api().discontinue(id, body) }
    suspend fun reactivate(id: Long) = apiUnit { api().reactivate(id) }

    suspend fun outletPrices(variantId: Long): OutletPricesDto = apiCall { api().outletPrices(variantId) }
    suspend fun setOutletPrice(variantId: Long, outletId: Long, body: OutletPriceBody): OutletPriceResult = apiCall { api().setOutletPrice(variantId, outletId, body) }

    // Pilihan formulir: semua dimuat sekali; gagal = daftar kosong (formulir tetap bisa dipakai, server penegak).
    suspend fun categories(): List<CategoryDto> = apiCall { api().categories(null, 1, 100) }.data.filter { it.isActive }
    suspend fun stations(): List<StationDto> = apiCall { api().stations() }.data
    suspend fun outlets(): Pair<List<OutletRef>, Boolean> = apiCall { api().scope() }.let { it.outlets to it.multi }

    // --- T068: tautan modifier ---
    suspend fun modifierGroups(): List<com.warkop.manager.domain.GroupChoice> = apiCall { api().modifierGroups() }.data.map {
        com.warkop.manager.domain.GroupChoice(it.id, it.name, it.selectionType == "single", it.isRequired, it.minSelect, it.maxSelect, it.isActive)
    }
    /** true = langsung berlaku; false = menunggu rilis menu berikutnya. */
    suspend fun saveLinks(productId: Long, body: com.warkop.manager.domain.LinksBody): Boolean = apiCall { api().saveModifierLinks(productId, body) }.applied

    // --- T069: resep ---
    suspend fun recipes(productId: Long) = apiCall { api().recipes(productId) }.data
    suspend fun recipe(id: Long) = apiCall { api().recipe(id) }
    suspend fun saveRecipe(productId: Long, id: Long?, body: com.warkop.manager.data.dto.RecipeBody) =
        apiCall { if (id == null) api().createRecipe(productId, body) else api().updateRecipe(id, body) }
    suspend fun deleteRecipe(id: Long) = apiUnit { api().deleteRecipe(id) }
    suspend fun recipeOptions(productId: Long) = apiCall { api().recipeModifierOptions(productId) }.data

    /** Cari bahan resep: bahan (`material`) atau produk-bahan (`product`, tanpa produk ini sendiri). */
    suspend fun searchIngredients(kind: String, q: String, productId: Long): List<com.warkop.manager.domain.RecipeLine> =
        if (kind == "product") apiCall { api().recipeIngredients(productId, q) }.data.map {
            com.warkop.manager.domain.RecipeLine(kind = "product", itemId = it.variantId, name = "${it.productName} — ${it.variantName}", unit = it.unit)
        } else apiCall { api().materialsForRecipe(q) }.data.map {
            com.warkop.manager.domain.RecipeLine(kind = "material", itemId = it.id, name = it.name, unit = it.unit)
        }

    // --- ProductSaveApi ---
    override suspend fun create(body: ProductBody): ProductSavedDto = apiCall { api().createProduct(body) }
    override suspend fun update(id: Long, body: ProductBody): ProductSavedDto = apiCall { api().updateProduct(id, body) }
    override suspend fun createVariant(productId: Long, body: VariantBody): ProductVariantDto = apiCall { api().createVariant(productId, body) }
    override suspend fun updateVariant(id: Long, body: VariantBody) = apiUnit { api().updateVariant(id, body) }
    override suspend fun productImage(id: Long, uri: String) = apiUnit { api().productImage(id, photoPart(Uri.parse(uri))) }
    override suspend fun variantImage(id: Long, uri: String) = apiUnit { api().variantImage(id, photoPart(Uri.parse(uri))) }
    override suspend fun adjust(body: AdjustBody) = apiUnit { api().adjustStock(body) }
}
