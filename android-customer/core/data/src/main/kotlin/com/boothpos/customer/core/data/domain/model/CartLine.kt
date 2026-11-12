package com.boothpos.customer.core.data.domain.model

import java.math.BigDecimal

/**
 * Satu baris keranjang lokal. Harga dan batas jumlah adalah CUPLIKAN terakhir dari server untuk
 * ditampilkan; checkout selalu memvalidasi ulang semuanya di server (quote).
 */
data class CartLine(
    val variantId: Long,
    val productSlug: String,
    val productName: String,
    val variantName: String,
    val imageUrl: String?,
    val unitPrice: BigDecimal,
    val quantity: Int,
    val maxQuantity: Int,
) {
    /** Hanya untuk tampilan; total resmi dihitung server. */
    val lineTotal: BigDecimal get() = unitPrice.multiply(BigDecimal(quantity))

    /** Jumlah melebihi batas terakhir dari server (stok berkurang atau varian tidak lagi dijual). */
    val exceedsAvailable: Boolean get() = quantity > maxQuantity
}

/** Varian yang hendak dimasukkan ke keranjang, dengan cuplikan data tampilannya. */
data class CartItem(
    val variantId: Long,
    val productSlug: String,
    val productName: String,
    val variantName: String,
    val imageUrl: String?,
    val unitPrice: BigDecimal,
    val maxQuantity: Int,
)

/** Pembaruan cuplikan satu baris dari jawaban server (quote atau `cart_invalid`). */
data class CartLineRefresh(val variantId: Long, val unitPrice: BigDecimal?, val maxQuantity: Int)

fun List<CartLine>.subtotal(): BigDecimal = fold(BigDecimal.ZERO) { sum, line -> sum.add(line.lineTotal) }
