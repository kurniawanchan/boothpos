package com.boothpos.customer.feature.checkout.data

import com.boothpos.customer.core.network.dto.DataEnvelope
import kotlinx.serialization.SerialName
import kotlinx.serialization.Serializable
import retrofit2.http.Body
import retrofit2.http.Header
import retrofit2.http.POST

// DTO permintaan sengaja kelas biasa (bukan data class): tidak ada toString yang memuat nama,
// telepon, atau catatan pelanggan bila objeknya tercetak di log/laporan crash.

@Serializable
class CheckoutContactDto(val name: String, val phone: String)

@Serializable
class CheckoutItemDto(@SerialName("variant_id") val variantId: Long, val qty: Int)

/**
 * Body `POST /customer/orders/quote` dan `POST /customer/orders` (kontrak §8). Satu-satunya angka
 * uang yang dikirim adalah [expectedTotal], dan hanya saat membuat pesanan; `Json` proyek ini
 * membuang properti null, jadi `address_id` (ambil di toko), `notes` kosong, dan `expected_total`
 * (quote) tidak ikut terkirim.
 */
@Serializable
class CheckoutRequestDto(
    @SerialName("fulfilment_method") val fulfilmentMethod: String,
    @SerialName("address_id") val addressId: Long? = null,
    val contact: CheckoutContactDto,
    val items: List<CheckoutItemDto>,
    val notes: String? = null,
    @SerialName("expected_total") val expectedTotal: String? = null,
)

@Serializable
data class QuoteLineDto(
    @SerialName("variant_id") val variantId: Long,
    @SerialName("product_slug") val productSlug: String,
    val name: String,
    @SerialName("image_url") val imageUrl: String? = null,
    @SerialName("unit_price") val unitPrice: String,
    val qty: Int,
    @SerialName("line_total") val lineTotal: String,
    @SerialName("max_qty") val maxQty: Int,
)

@Serializable
data class QuoteDto(
    @SerialName("fulfilment_method") val fulfilmentMethod: String? = null,
    val lines: List<QuoteLineDto>,
    val subtotal: String,
    @SerialName("shipping_cost") val shippingCost: String = "0.00",
    @SerialName("shipping_cost_pending") val shippingCostPending: Boolean = false,
    val total: String,
)

/** Hanya yang dibutuhkan setelah pesanan dibuat; rincian lengkapnya milik fitur pesanan. */
@Serializable
data class PlacedOrderDto(@SerialName("order_number") val orderNumber: String)

/** Satu entri `errors.items` pada 409 `cart_invalid`. */
@Serializable
data class CartIssueDto(
    @SerialName("variant_id") val variantId: Long,
    val reason: String? = null,
    @SerialName("max_qty") val maxQty: Int = 0,
)

interface CheckoutApi {
    @POST("customer/orders/quote")
    suspend fun quote(@Body body: CheckoutRequestDto): DataEnvelope<QuoteDto>

    @POST("customer/orders")
    suspend fun create(
        @Header(IDEMPOTENCY_KEY_HEADER) idempotencyKey: String,
        @Body body: CheckoutRequestDto,
    ): DataEnvelope<PlacedOrderDto>

    companion object {
        const val IDEMPOTENCY_KEY_HEADER = "Idempotency-Key"
    }
}
