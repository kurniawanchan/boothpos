package com.warkop.manager.data.dto

import kotlinx.serialization.EncodeDefault
import kotlinx.serialization.ExperimentalSerializationApi
import kotlinx.serialization.SerialName
import kotlinx.serialization.Serializable

/**
 * 094 / T062 — DTO Produk (kontrak B5). Sumber: `ProductCatalogQuery` (baris daftar + ringkasan), `ProductResource` +
 * `ProductDetailService::extras` (detail), `StoreProductRequest`/`UpdateProductRequest`/`*VariantRequest` (simpan).
 */

@Serializable data class NamedRef(val id: Long, val name: String)

@Serializable data class StationRef(val id: Long, val name: String, val source: String? = null)

@Serializable data class AvailabilityDto(
    @SerialName("outlet_id") val outletId: Long? = null,
    @SerialName("outlet_name") val outletName: String? = null,
    val state: String = "available",
    val reason: String? = null,
)

@Serializable data class PriceRange(val min: String? = null, val max: String? = null)

@Serializable data class ProductRowDto(
    val id: Long,
    val code: String? = null,
    val name: String,
    @SerialName("short_name") val shortName: String? = null,
    val category: NamedRef? = null,
    val station: StationRef? = null,
    val status: String = "active",
    @SerialName("base_price") val basePrice: String? = null,
    @SerialName("price_range") val priceRange: PriceRange? = null,
    @SerialName("variants_summary") val variantsSummary: String = "",
    @SerialName("variant_count") val variantCount: Int = 0,
    val availability: List<AvailabilityDto> = emptyList(),
    val quality: List<String> = emptyList(),
)

@Serializable data class TabCounts(val menu: Int = 0, val retail: Int = 0)

@Serializable data class ProductPage(
    val data: List<ProductRowDto> = emptyList(),
    val meta: PageMeta = PageMeta(),
    val counts: TabCounts = TabCounts(),
)

@Serializable data class SummaryActive(val count: Int = 0, val total: Int = 0, val draft: Int = 0, val discontinued: Int = 0)
@Serializable data class SummaryCount(val count: Int = 0)
@Serializable data class SummaryQuality(val count: Int = 0, val kind: String? = null)

@Serializable data class ProductSummaryDto(
    val active: SummaryActive = SummaryActive(),
    @SerialName("sold_out_today") val soldOutToday: SummaryCount = SummaryCount(),
    val quality: SummaryQuality = SummaryQuality(),
)

@Serializable data class CarriedOutletDto(val id: Long, val code: String? = null, val name: String, val carried: Boolean = false)

@Serializable data class ProductVariantDto(
    val id: Long,
    val sku: String = "",
    @SerialName("variant_name") val variantName: String = "",
    @SerialName("image_url") val imageUrl: String? = null,
    /** Hilang bagi pengguna tanpa menu products/reports. */
    @SerialName("cost_price") val costPrice: String? = null,
    @SerialName("sell_price") val sellPrice: String = "0.00",
    @SerialName("outlet_price") val outletPrice: String? = null,
    @Serializable(with = FlexIntSerializer::class) @SerialName("outlet_stock") val outletStock: Int? = null,
    @Serializable(with = FlexIntSerializer::class) @SerialName("low_stock_alert") val lowStockAlert: Int? = null,
    @SerialName("is_active") val isActive: Boolean = true,
    @SerialName("bom_complete") val bomComplete: Boolean = false,
    @SerialName("track_stock") val trackStock: Boolean = true,
)

@Serializable data class ModifierLinkDto(
    @SerialName("group_id") val groupId: Long,
    val name: String = "",
    val source: String = "product",
    @SerialName("is_required") val isRequired: Boolean = false,
    @SerialName("min_select") val minSelect: Int = 0,
    @SerialName("max_select") val maxSelect: Int? = null,
    @SerialName("display_order") val displayOrder: Int = 0,
    val options: List<String> = emptyList(),
)

@Serializable data class RecipeSummaryDto(val id: Long, val name: String? = null, val yield: String? = null, @SerialName("line_count") val lineCount: Int = 0, @SerialName("recipe_count") val recipeCount: Int = 0)

@Serializable data class ProductCanDto(
    val edit: Boolean = false,
    @SerialName("mark_86") val mark86: Boolean = false,
    val discontinue: Boolean = false,
    @SerialName("edit_recipe") val editRecipe: Boolean = false,
)

@Serializable data class ProductDetailDto(
    val id: Long,
    @SerialName("home_outlet_id") val homeOutletId: Long? = null,
    @SerialName("home_outlet_name") val homeOutletName: String? = null,
    @SerialName("category_id") val categoryId: Long? = null,
    @SerialName("category_name") val categoryName: String? = null,
    @SerialName("code_prefix") val codePrefix: String? = null,
    val name: String,
    val description: String? = null,
    @SerialName("image_url") val imageUrl: String? = null,
    @SerialName("is_active") val isActive: Boolean = true,
    @SerialName("station_id") val stationId: Long? = null,
    @SerialName("stock_source") val stockSource: String = "variant",
    @SerialName("use_as_ingredient") val useAsIngredient: Boolean = false,
    @SerialName("ingredient_unit") val ingredientUnit: String? = null,
    /** Hanya bila pengguna melihat > 1 outlet. */
    val outlets: List<CarriedOutletDto>? = null,
    val variants: List<ProductVariantDto> = emptyList(),
    @SerialName("short_name") val shortName: String? = null,
    @SerialName("tax_category") val taxCategory: String? = null,
    @Serializable(with = FlexIntSerializer::class) @SerialName("prep_minutes") val prepMinutes: Int? = null,
    @SerialName("is_retail") val isRetail: Boolean = false,
    val status: String? = null,
    val station: StationRef? = null,
    val availability: List<AvailabilityDto> = emptyList(),
    @SerialName("modifier_links") val modifierLinks: List<ModifierLinkDto> = emptyList(),
    @SerialName("modifier_excluded") val modifierExcluded: List<ModifierLinkDto> = emptyList(),
    val recipe: RecipeSummaryDto? = null,
    val can: ProductCanDto = ProductCanDto(),
)

@Serializable data class PriceHistoryDto(
    val at: String? = null,
    val variant: String? = null,
    @SerialName("outlet_code") val outletCode: String? = null,
    @SerialName("old_price") val oldPrice: String? = null,
    @SerialName("new_price") val newPrice: String? = null,
    val source: String? = null,
    val by: String? = null,
    val note: String? = null,
)

@Serializable data class ReasonCodeDto(val id: Long, val code: String = "", val label: String = "", @SerialName("is_active") val isActive: Boolean = true)

@Serializable data class ReasonBody(@SerialName("reason_code_id") val reasonCodeId: Long, val note: String? = null)

/** Respons simpan produk boleh membawa peringatan tak-memblokir (`variant_stock_remaining`, `no_material_mappings`). */
@Serializable data class SaveWarning(val code: String = "", val message: String = "")

@Serializable data class ProductSavedDto(
    val id: Long,
    val variants: List<ProductVariantDto> = emptyList(),
    val warnings: List<SaveWarning> = emptyList(),
)

@OptIn(ExperimentalSerializationApi::class)
@Serializable data class NewVariantBody(
    @SerialName("variant_name") val variantName: String,
    @SerialName("cost_price") val costPrice: String,
    @SerialName("sell_price") val sellPrice: String,
    @EncodeDefault @SerialName("low_stock_alert") val lowStockAlert: Int? = null,
    @SerialName("track_stock") val trackStock: Boolean = true,
)

/** Badan `POST /products` dan `PUT /products/{id}`. `product_segment` & `variants` hanya saat buat; `outlet_ids` hanya bila multi. */
@OptIn(ExperimentalSerializationApi::class)
@Serializable data class ProductBody(
    @SerialName("outlet_ids") val outletIds: List<Long>? = null,
    @SerialName("home_outlet_id") val homeOutletId: Long,
    @SerialName("category_id") val categoryId: Long,
    @SerialName("product_segment") val productSegment: String? = null,
    val name: String,
    @EncodeDefault val description: String? = null,
    @EncodeDefault @SerialName("is_active") val isActive: Boolean = true,
    @EncodeDefault @SerialName("station_id") val stationId: Long? = null,
    @EncodeDefault @SerialName("short_name") val shortName: String? = null,
    @EncodeDefault @SerialName("tax_category") val taxCategory: String? = null,
    @EncodeDefault @Serializable(with = FlexIntSerializer::class) @SerialName("prep_minutes") val prepMinutes: Int? = null,
    @EncodeDefault @SerialName("is_retail") val isRetail: Boolean = false,
    @EncodeDefault @SerialName("stock_source") val stockSource: String = "variant",
    @EncodeDefault @SerialName("use_as_ingredient") val useAsIngredient: Boolean = false,
    @EncodeDefault @SerialName("ingredient_unit") val ingredientUnit: String? = null,
    val variants: List<NewVariantBody>? = null,
)

/** `PUT /variants/{id}` dan `POST /products/{id}/variants`. `cost_price` hanya bila pengguna boleh melihat biaya. */
@OptIn(ExperimentalSerializationApi::class)
@Serializable data class VariantBody(
    @SerialName("variant_name") val variantName: String,
    @SerialName("cost_price") val costPrice: String? = null,
    @SerialName("sell_price") val sellPrice: String,
    @EncodeDefault @SerialName("low_stock_alert") val lowStockAlert: Int? = null,
    @EncodeDefault @SerialName("is_active") val isActive: Boolean = true,
    @EncodeDefault @SerialName("track_stock") val trackStock: Boolean = true,
)

@Serializable data class BatchVariant(val id: Long, @SerialName("variant_name") val variantName: String, @SerialName("sell_price") val sellPrice: String, @SerialName("is_active") val isActive: Boolean)
@Serializable data class BatchVariantsBody(val variants: List<BatchVariant>)

@Serializable data class OutletPriceRow(val id: Long, val code: String? = null, val name: String, val price: String? = null, @SerialName("follows_base") val followsBase: Boolean = true)
@Serializable data class OutletPricesDto(@SerialName("base_price") val basePrice: String = "0.00", val outlets: List<OutletPriceRow> = emptyList())

@OptIn(ExperimentalSerializationApi::class)
@Serializable data class OutletPriceBody(@EncodeDefault val price: String? = null, val note: String? = null)

@Serializable data class OutletPriceResult(val changed: Boolean = false, val pending: Boolean = false, @SerialName("requires_approval") val requiresApproval: Boolean = false)
