package com.boothpos.customer.feature.checkout.domain

import com.boothpos.customer.core.data.domain.model.FulfilmentMethod
import com.boothpos.customer.core.network.error.ApiError
import java.math.BigDecimal

data class CheckoutItem(val variantId: Long, val quantity: Int)

/**
 * Isi permintaan yang sama untuk quote dan buat pesanan (kontrak §8). Sengaja TANPA harga atau
 * total: server menghitung semuanya sendiri dari varian + jumlah.
 */
data class CheckoutRequest(
    val method: FulfilmentMethod,
    /** Wajib untuk kurir, null untuk ambil di toko. */
    val addressId: Long?,
    val contactName: String,
    val contactPhone: String,
    val items: List<CheckoutItem>,
    val notes: String?,
) {
    // Nama, telepon, dan catatan tidak boleh muncul bila objek ini tercetak di log/laporan crash.
    override fun toString(): String = "CheckoutRequest(method=$method, items=${items.size})"
}

data class QuoteLine(
    val variantId: Long,
    val productSlug: String,
    val name: String,
    val imageUrl: String?,
    val unitPrice: BigDecimal,
    val quantity: Int,
    val lineTotal: BigDecimal,
    val maxQuantity: Int,
)

/** Rincian resmi dari server. Angka yang ditampilkan di ringkasan SELALU berasal dari sini. */
data class Quote(
    val method: FulfilmentMethod,
    val lines: List<QuoteLine>,
    val subtotal: BigDecimal,
    val shippingCost: BigDecimal,
    /** Kurir di v1: ongkos kirim baru ditetapkan toko saat mengonfirmasi pesanan. */
    val shippingCostPending: Boolean,
    val total: BigDecimal,
)

/** Pesanan yang berhasil dibuat (201) atau diputar ulang server untuk kunci yang sama (200). */
data class PlacedOrder(val orderNumber: String)

enum class CartIssueReason { Unavailable, InsufficientStock, QuantityLimit }

/** Satu baris keranjang yang ditolak server (`errors.items` pada 409 `cart_invalid`). */
data class CartIssue(val variantId: Long, val reason: CartIssueReason, val maxQuantity: Int)

sealed interface QuoteOutcome {
    data class Success(val quote: Quote) : QuoteOutcome

    data class CartInvalid(val issues: List<CartIssue>) : QuoteOutcome

    data class Failure(val error: ApiError) : QuoteOutcome
}

sealed interface PlaceOrderOutcome {
    data class Placed(val order: PlacedOrder) : PlaceOrderOutcome

    /** 409 `price_changed`. [quote] adalah rincian baru dari `errors.quote`; null bila tak terbaca. */
    data class PriceChanged(val quote: Quote?) : PlaceOrderOutcome

    data class CartInvalid(val issues: List<CartIssue>) : PlaceOrderOutcome

    /** 409 `idempotency_key_reused`: kunci ini sudah dipakai untuk isi pesanan yang berbeda. */
    data object IdempotencyKeyReused : PlaceOrderOutcome

    /**
     * Galat lain. Untuk [ApiError.Timeout], [ApiError.Network], [ApiError.Unknown], dan 5xx, hasil
     * di server TIDAK DIKETAHUI: pesanan mungkin sudah tercatat.
     */
    data class Failure(val error: ApiError) : PlaceOrderOutcome
}
