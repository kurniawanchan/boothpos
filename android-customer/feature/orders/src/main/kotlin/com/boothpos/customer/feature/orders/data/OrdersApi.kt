package com.boothpos.customer.feature.orders.data

import com.boothpos.customer.core.network.dto.DataEnvelope
import com.boothpos.customer.core.network.dto.PagedEnvelope
import kotlinx.serialization.SerialName
import kotlinx.serialization.Serializable
import retrofit2.http.GET
import retrofit2.http.Path
import retrofit2.http.Query

// Bentuk kawat kontrak §8 (hanya baca). Field yang tidak dikenal diabaikan (Json proyek:
// ignoreUnknownKeys), jadi server boleh menambah field tanpa merusak aplikasi lama. `status`,
// `fulfilment_method`, dan `event_type` sengaja String: nilai baru dari server dipetakan ke
// "tidak dikenal" di mapper, bukan menggagalkan penguraian.

@Serializable
data class OrderSummaryDto(
    @SerialName("order_number") val orderNumber: String,
    val status: String? = null,
    @SerialName("fulfilment_method") val fulfilmentMethod: String? = null,
    val total: String,
    @SerialName("item_count") val itemCount: Int = 0,
    @SerialName("first_item_name") val firstItemName: String? = null,
    @SerialName("created_at") val createdAt: String? = null,
    @SerialName("updated_at") val updatedAt: String? = null,
)

// Kontak dan alamat memuat data pribadi: class biasa (bukan data class), supaya isinya tidak
// pernah ikut tercetak lewat toString() di log atau laporan crash.

@Serializable
class OrderContactDto(
    val name: String? = null,
    val phone: String? = null,
    val email: String? = null,
)

@Serializable
class OrderAddressDto(
    @SerialName("recipient_name") val recipientName: String? = null,
    val phone: String? = null,
    @SerialName("address_line") val addressLine: String? = null,
    val city: String? = null,
    val province: String? = null,
    @SerialName("postal_code") val postalCode: String? = null,
    val notes: String? = null,
)

@Serializable
data class OrderItemDto(
    val name: String,
    val sku: String? = null,
    @SerialName("unit_price") val unitPrice: String,
    val qty: Int,
    @SerialName("line_total") val lineTotal: String,
)

@Serializable
data class ShipmentDto(
    @SerialName("courier_name") val courierName: String? = null,
    @SerialName("service_name") val serviceName: String? = null,
    @SerialName("tracking_number") val trackingNumber: String? = null,
    @SerialName("tracking_url") val trackingUrl: String? = null,
    @SerialName("dispatched_at") val dispatchedAt: String? = null,
    @SerialName("delivered_at") val deliveredAt: String? = null,
    @SerialName("shipping_cost") val shippingCost: String? = null,
)

@Serializable
data class OrderEventDto(
    val status: String? = null,
    @SerialName("event_type") val eventType: String? = null,
    val note: String? = null,
    @SerialName("occurred_at") val occurredAt: String? = null,
)

/** `OrderDetail` = `OrderSummary` + uang, kontak, alamat, barang, pengiriman, linimasa. */
@Serializable
class OrderDetailDto(
    @SerialName("order_number") val orderNumber: String,
    val status: String? = null,
    @SerialName("fulfilment_method") val fulfilmentMethod: String? = null,
    val total: String,
    @SerialName("item_count") val itemCount: Int = 0,
    @SerialName("first_item_name") val firstItemName: String? = null,
    @SerialName("created_at") val createdAt: String? = null,
    @SerialName("updated_at") val updatedAt: String? = null,
    val subtotal: String,
    @SerialName("shipping_cost") val shippingCost: String,
    @SerialName("shipping_cost_pending") val shippingCostPending: Boolean = false,
    val contact: OrderContactDto? = null,
    @SerialName("shipping_address") val shippingAddress: OrderAddressDto? = null,
    val notes: String? = null,
    @SerialName("cancel_reason") val cancelReason: String? = null,
    val instructions: String? = null,
    val items: List<OrderItemDto> = emptyList(),
    val shipment: ShipmentDto? = null,
    val timeline: List<OrderEventDto> = emptyList(),
)

@Serializable
data class OrderTrackingDto(
    @SerialName("order_number") val orderNumber: String,
    val status: String? = null,
    @SerialName("fulfilment_method") val fulfilmentMethod: String? = null,
    @SerialName("updated_by") val updatedBy: String? = null,
    val shipment: ShipmentDto? = null,
    val timeline: List<OrderEventDto> = emptyList(),
)

/** Endpoint pelanggan: AuthInterceptor menempelkan Bearer ke setiap jalur `…/customer/…`. */
interface OrdersApi {
    @GET("customer/orders")
    suspend fun orders(
        @Query("page") page: Int,
        @Query("per_page") perPage: Int,
    ): PagedEnvelope<OrderSummaryDto>

    @GET("customer/orders/{orderNumber}")
    suspend fun order(@Path("orderNumber") orderNumber: String): DataEnvelope<OrderDetailDto>

    @GET("customer/orders/{orderNumber}/tracking")
    suspend fun tracking(@Path("orderNumber") orderNumber: String): DataEnvelope<OrderTrackingDto>
}
