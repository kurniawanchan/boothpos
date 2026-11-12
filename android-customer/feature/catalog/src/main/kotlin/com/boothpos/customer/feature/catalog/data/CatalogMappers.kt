package com.boothpos.customer.feature.catalog.data

import com.boothpos.customer.core.data.domain.money.Money
import com.boothpos.customer.core.data.local.CachedCategoryEntity
import com.boothpos.customer.core.data.local.CachedProductEntity
import com.boothpos.customer.core.data.local.CachedProductWithDetail
import com.boothpos.customer.core.data.local.CachedVariantEntity
import com.boothpos.customer.feature.catalog.domain.Category
import com.boothpos.customer.feature.catalog.domain.ProductDetail
import com.boothpos.customer.feature.catalog.domain.ProductSummary
import com.boothpos.customer.feature.catalog.domain.ProductVariant
import java.math.BigDecimal

/**
 * Harga yang bukan desimal polos berarti jawaban server tidak dapat dipahami. Lebih baik seluruh
 * panggilan gagal (menjadi `ApiError.Unknown` di SafeApiCall) daripada menampilkan harga karangan.
 * Pesannya sengaja tidak memuat nilai dari jawaban.
 */
private fun money(raw: String): BigDecimal =
    Money.parse(raw) ?: throw IllegalArgumentException("Harga dari server tidak dapat dibaca")

// --- kawat -> domain ---

internal fun CategoryDto.toDomain() = Category(id = id, name = name, imageUrl = imageUrl)

internal fun ProductCardDto.toDomain() = ProductSummary(
    slug = slug,
    name = name,
    imageUrl = imageUrl,
    categoryId = category.id,
    categoryName = category.name,
    sellerName = sellerName,
    priceMin = money(priceMin),
    priceMax = money(priceMax),
    inStock = inStock,
)

internal fun ProductDetailDto.toDomain() = ProductDetail(
    summary = ProductSummary(
        slug = slug,
        name = name,
        imageUrl = imageUrl,
        categoryId = category.id,
        categoryName = category.name,
        sellerName = sellerName,
        priceMin = money(priceMin),
        priceMax = money(priceMax),
        inStock = inStock,
    ),
    description = description?.takeIf { it.isNotBlank() },
    variants = variants.map { variant ->
        ProductVariant(
            id = variant.id,
            name = variant.name,
            imageUrl = variant.imageUrl,
            price = money(variant.price),
            inStock = variant.inStock,
            // Batas negatif tidak bermakna; 0 berarti tidak dapat dibeli.
            maxQty = variant.maxQty.coerceAtLeast(0),
        )
    },
)

// --- domain -> cache ---

internal fun Category.toEntity(position: Int) =
    CachedCategoryEntity(id = id, name = name, imageUrl = imageUrl, position = position)

internal fun ProductSummary.toEntity(listPosition: Int, cachedAt: Long) = CachedProductEntity(
    slug = slug,
    name = name,
    imageUrl = imageUrl,
    categoryId = categoryId,
    categoryName = categoryName,
    sellerName = sellerName,
    priceMin = Money.toWire(priceMin),
    priceMax = Money.toWire(priceMax),
    inStock = inStock,
    listPosition = listPosition,
    cachedAt = cachedAt,
)

internal fun ProductVariant.toEntity(productSlug: String, position: Int) = CachedVariantEntity(
    id = id,
    productSlug = productSlug,
    name = name,
    imageUrl = imageUrl,
    price = Money.toWire(price),
    inStock = inStock,
    maxQty = maxQty,
    position = position,
)

// --- cache -> domain (baris yang tidak terbaca dilewati, bukan menggagalkan layar offline) ---

internal fun CachedCategoryEntity.toDomain() = Category(id = id, name = name, imageUrl = imageUrl)

internal fun CachedProductEntity.toDomain(): ProductSummary? {
    return ProductSummary(
        slug = slug,
        name = name,
        imageUrl = imageUrl,
        categoryId = categoryId,
        categoryName = categoryName,
        sellerName = sellerName,
        priceMin = Money.parse(priceMin) ?: return null,
        priceMax = Money.parse(priceMax) ?: return null,
        inStock = inStock,
    )
}

/** Null bila hanya kartunya yang tersimpan: tanpa varian, layar detail tidak dapat dipakai. */
internal fun CachedProductWithDetail.toDomain(): ProductDetail? {
    val cachedDetail = detail ?: return null
    return ProductDetail(
        summary = product.toDomain() ?: return null,
        description = cachedDetail.description,
        variants = variants.sortedBy { it.position }.mapNotNull { variant ->
            ProductVariant(
                id = variant.id,
                name = variant.name,
                imageUrl = variant.imageUrl,
                price = Money.parse(variant.price) ?: return@mapNotNull null,
                inStock = variant.inStock,
                maxQty = variant.maxQty,
            )
        },
    )
}
