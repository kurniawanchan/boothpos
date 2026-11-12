package com.boothpos.customer.feature.orders.domain

import com.boothpos.customer.core.data.domain.model.FulfilmentMethod
import java.math.BigDecimal
import java.time.Instant

/**
 * Status pesanan (kontrak §4/§8). Hanya server yang mengubahnya; aplikasi menampilkan saja.
 * Nilai yang belum dikenal versi aplikasi ini menjadi [Unknown] dan ditampilkan netral, tidak
 * pernah membuat layar gagal.
 */
enum class OrderStatus(val wireValue: String?) {
    AwaitingConfirmation("awaiting_confirmation"),
    Confirmed("confirmed"),
    Packing("packing"),
    ReadyForPickup("ready_for_pickup"),
    Shipped("shipped"),
    Delivered("delivered"),
    PickedUp("picked_up"),
    Cancelled("cancelled"),
    Unknown(null),
    ;

    companion object {
        fun fromWire(value: String?): OrderStatus =
            entries.firstOrNull { it.wireValue != null && it.wireValue == value } ?: Unknown
    }
}

/** Jenis peristiwa linimasa. Jenis yang belum dikenal ditampilkan sebagai "pesanan diperbarui". */
enum class OrderEventType(val wireValue: String?) {
    StatusChanged("status_changed"),
    TrackingUpdated("tracking_updated"),
    ShippingCostSet("shipping_cost_set"),
    Unknown(null),
    ;

    companion object {
        fun fromWire(value: String?): OrderEventType =
            entries.firstOrNull { it.wireValue != null && it.wireValue == value } ?: Unknown
    }
}

/** Satu baris daftar "Pesanan Saya". [itemCount] adalah jumlah BARIS barang, bukan jumlah unit. */
data class OrderSummary(
    val orderNumber: String,
    val status: OrderStatus,
    /** Null bila server mengirim metode yang belum dikenal versi aplikasi ini. */
    val fulfilmentMethod: FulfilmentMethod?,
    val total: BigDecimal,
    val itemCount: Int,
    val firstItemName: String?,
    val createdAt: Instant?,
    val updatedAt: Instant?,
)

/** Kontak pesanan (snapshot saat checkout). */
data class OrderContact(val name: String, val phone: String?, val email: String?) {
    // Data pribadi tidak boleh muncul bila model ini tercetak di log atau laporan crash.
    override fun toString(): String = "OrderContact(***)"
}

/** Alamat kirim pesanan (snapshot saat checkout); hanya ada untuk pesanan kurir. */
data class OrderAddress(
    val recipientName: String?,
    val phone: String?,
    val addressLine: String?,
    val city: String?,
    val province: String?,
    val postalCode: String?,
    val notes: String?,
) {
    override fun toString(): String = "OrderAddress(***)"
}

data class OrderItem(
    val name: String,
    val sku: String?,
    val unitPrice: BigDecimal,
    val quantity: Int,
    val lineTotal: BigDecimal,
)

/** Pengiriman kurir sebagaimana DICATAT TOKO (bukan data langsung dari kurir). */
data class Shipment(
    val courierName: String?,
    val serviceName: String?,
    val trackingNumber: String?,
    /**
     * Nilai mentah `tracking_url` dari server. JANGAN dibuka langsung: pakai [trackingLink], yang
     * hanya meloloskan tautan https yang wajar.
     */
    val trackingUrl: String?,
    val dispatchedAt: Instant?,
    val deliveredAt: Instant?,
    val shippingCost: BigDecimal?,
) {
    /** Tautan pelacakan yang aman dibuka di peramban luar, atau null (aksi "Lacak paket" disembunyikan). */
    val trackingLink: String? get() = TrackingLink.safeOrNull(trackingUrl)
}

/** Satu peristiwa linimasa yang boleh dilihat pelanggan. */
data class OrderEvent(
    val status: OrderStatus,
    val type: OrderEventType,
    val note: String?,
    val occurredAt: Instant?,
)

data class OrderDetail(
    val summary: OrderSummary,
    val subtotal: BigDecimal,
    val shippingCost: BigDecimal,
    /** Ongkos kirim belum ditetapkan toko (pesanan kurir sebelum dikonfirmasi). */
    val shippingCostPending: Boolean,
    val contact: OrderContact,
    val shippingAddress: OrderAddress?,
    val notes: String?,
    val cancelReason: String?,
    /** Petunjuk ambil/kirim dari toko untuk metode pesanan ini (teks polos dari server). */
    val instructions: String?,
    val items: List<OrderItem>,
    val shipment: Shipment?,
    /** Terbaru lebih dulu. */
    val timeline: List<OrderEvent>,
) {
    // Kontak, alamat, dan catatan pelanggan tidak boleh muncul bila model ini tercetak di log/laporan crash.
    override fun toString(): String = "OrderDetail(orderNumber=${summary.orderNumber}, items=${items.size})"
}

/** Jawaban `GET /customer/orders/{nomor}/tracking`. */
data class OrderTracking(
    val orderNumber: String,
    val status: OrderStatus,
    val fulfilmentMethod: FulfilmentMethod?,
    /** `updated_by == "store"`: isi pelacakan dicatat staf toko, bukan telemetri kurir. */
    val updatedByStore: Boolean,
    val shipment: Shipment?,
    /** Terbaru lebih dulu. */
    val timeline: List<OrderEvent>,
)
