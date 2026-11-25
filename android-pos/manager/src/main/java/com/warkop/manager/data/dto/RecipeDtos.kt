package com.warkop.manager.data.dto

import kotlinx.serialization.EncodeDefault
import kotlinx.serialization.ExperimentalSerializationApi
import kotlinx.serialization.SerialName
import kotlinx.serialization.Serializable

/** 094 / T069 — DTO resep (`RecipeService::present`/baris daftar, `RecipeController`). Kunci API porsi tetap `yield`. */

@Serializable data class RecipeVariantRef(val id: Long, val sku: String? = null, val name: String = "")

@Serializable data class RecipeRowDto(
    val id: Long,
    val name: String = "",
    val yield: String = "1",
    @SerialName("effective_from") val effectiveFrom: String? = null,
    val note: String? = null,
    val archived: Boolean = false,
    @SerialName("created_by") val createdBy: String? = null,
    val variants: List<RecipeVariantRef> = emptyList(),
    @SerialName("block_count") val blockCount: Int = 0,
    @SerialName("is_active_now") val isActiveNow: Boolean = false,
    /** Hanya bagi pemegang biaya (menu products/reports + `menu.recipe.edit`). */
    @SerialName("portion_cost") val portionCost: String? = null,
    val incomplete: Boolean? = null,
)

@Serializable data class RecipeLineDto(
    @SerialName("material_id") val materialId: Long? = null,
    @SerialName("variant_id") val variantId: Long? = null,
    val name: String = "",
    val unit: String? = null,
    val kind: String? = null,
    val quantity: String = "0",
    @SerialName("shrinkage_pct") val shrinkagePct: String? = null,
)

@Serializable data class RecipeBlockDto(
    @SerialName("variant_id") val variantId: Long,
    @SerialName("modifier_option_id") val modifierOptionId: Long? = null,
    val lines: List<RecipeLineDto> = emptyList(),
)

@Serializable data class RecipeDetailDto(
    val id: Long,
    val name: String = "",
    val archived: Boolean = false,
    val yield: String = "1",
    @SerialName("effective_from") val effectiveFrom: String? = null,
    val note: String? = null,
    val blocks: List<RecipeBlockDto> = emptyList(),
)

@Serializable data class OptionRef(val id: Long, val name: String)
@Serializable data class ModifierOptionGroupDto(val id: Long, val name: String, val options: List<OptionRef> = emptyList())

/** `GET /recipe-ingredients` — varian produk yang boleh menjadi bahan. */
@Serializable data class IngredientProductDto(
    @SerialName("variant_id") val variantId: Long,
    @SerialName("product_name") val productName: String = "",
    @SerialName("variant_name") val variantName: String = "",
    val sku: String? = null,
    val unit: String? = null,
)

/** Bahan untuk pemilih resep (`GET /materials`), dikelompokkan menurut `kind`. */
@Serializable data class MaterialPickDto(val id: Long, val code: String = "", val name: String, val unit: String? = null, val kind: String? = null)

@OptIn(ExperimentalSerializationApi::class)
@Serializable data class RecipeLineBody(
    @SerialName("material_id") val materialId: Long? = null,
    @SerialName("variant_id") val variantId: Long? = null,
    val quantity: Double,
    @EncodeDefault @SerialName("shrinkage_pct") val shrinkagePct: Double = 0.0,
)

@OptIn(ExperimentalSerializationApi::class)
@Serializable data class RecipeBlockBody(
    @SerialName("variant_id") val variantId: Long,
    @EncodeDefault @SerialName("modifier_option_id") val modifierOptionId: Long? = null,
    val lines: List<RecipeLineBody>,
)

@OptIn(ExperimentalSerializationApi::class)
@Serializable data class RecipeBody(
    val name: String,
    val yield: Double,
    @SerialName("effective_from") val effectiveFrom: String,
    val blocks: List<RecipeBlockBody>,
    @EncodeDefault val note: String? = null,
)

/** `GET /modifier-groups` — hanya kolom yang dibutuhkan pemilih tautan. */
@Serializable data class ModifierGroupBriefDto(
    val id: Long,
    val name: String,
    @SerialName("selection_type") val selectionType: String = "single",
    @SerialName("is_required") val isRequired: Boolean = false,
    @SerialName("min_select") val minSelect: Int = 0,
    @SerialName("max_select") val maxSelect: Int? = null,
    @SerialName("is_active") val isActive: Boolean = true,
)

@Serializable data class LinksSaved(val applied: Boolean = true)
