package com.boothpos.customer.feature.catalog.domain

import java.math.BigDecimal

// Model katalog publik (kontrak §6). Semua nilainya ditentukan server; aplikasi hanya menampilkan.

data class Category(val id: Long, val name: String, val imageUrl: String?)

/** Kartu produk di daftar. */
data class ProductSummary(
    val slug: String,
    val name: String,
    val imageUrl: String?,
    val categoryId: Long,
    val categoryName: String,
    val sellerName: String,
    val priceMin: BigDecimal,
    val priceMax: BigDecimal,
    val inStock: Boolean,
) {
    /** Variannya berbeda harga: kartu menulis "Mulai dari …". */
    val hasPriceRange: Boolean get() = priceMin.compareTo(priceMax) != 0
}

data class ProductVariant(
    val id: Long,
    val name: String,
    val imageUrl: String?,
    val price: BigDecimal,
    val inStock: Boolean,
    /** Batas jumlah per baris dari server: `min(stok, batas per baris)`. */
    val maxQty: Int,
) {
    val isPurchasable: Boolean get() = inStock && maxQty >= 1
}

data class ProductDetail(
    val summary: ProductSummary,
    val description: String?,
    val variants: List<ProductVariant>,
)

/** Nilai `sort` yang diizinkan server; tidak ada nilai lain yang pernah dikirim. */
enum class ProductSort(val wireValue: String) {
    Newest("newest"),
    Name("name"),
    PriceAsc("price_asc"),
    PriceDesc("price_desc"),
    ;

    companion object {
        fun fromWire(value: String?): ProductSort = entries.firstOrNull { it.wireValue == value } ?: Newest
    }
}

/** Pencarian + filter + urutan daftar produk. [search] sudah dipangkas. */
data class ProductQuery(
    val search: String = "",
    val categoryId: Long? = null,
    val sort: ProductSort = ProductSort.Newest,
) {
    /** Ada pencarian atau filter kategori: hasil kosong berarti "tidak ada yang cocok". */
    val isFiltered: Boolean get() = search.isNotEmpty() || categoryId != null

    /** Daftar bawaan toko; hanya daftar inilah yang disimpan untuk dipakai offline. */
    val isDefault: Boolean get() = !isFiltered && sort == ProductSort.Newest

    companion object {
        /** Batas `q` di server (kontrak §6). */
        const val MAX_SEARCH_LENGTH = 100
    }
}
