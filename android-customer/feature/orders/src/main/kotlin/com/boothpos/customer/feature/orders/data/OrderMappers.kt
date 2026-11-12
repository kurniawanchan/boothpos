package com.boothpos.customer.feature.orders.data

import com.boothpos.customer.core.data.domain.model.FulfilmentMethod
import com.boothpos.customer.core.data.domain.money.Money
import com.boothpos.customer.core.data.domain.time.IsoTime
import com.boothpos.customer.feature.orders.domain.OrderAddress
import com.boothpos.customer.feature.orders.domain.OrderContact
import com.boothpos.customer.feature.orders.domain.OrderDetail
import com.boothpos.customer.feature.orders.domain.OrderEvent
import com.boothpos.customer.feature.orders.domain.OrderEventType
import com.boothpos.customer.feature.orders.domain.OrderItem
import com.boothpos.customer.feature.orders.domain.OrderStatus
import com.boothpos.customer.feature.orders.domain.OrderSummary
import com.boothpos.customer.feature.orders.domain.OrderTracking
import com.boothpos.customer.feature.orders.domain.Shipment
import java.math.BigDecimal

/** Nilai `updated_by` yang berarti isi pelacakan dicatat staf toko (kontrak §8). */
internal const val UPDATED_BY_STORE = "store"

/**
 * Uang yang bukan desimal polos berarti jawaban server tidak dapat dipahami. Lebih baik seluruh
 * panggilan gagal (menjadi `ApiError.Unknown` di SafeApiCall) daripada menampilkan angka karangan.
 * Pesannya sengaja tidak memuat nilai dari jawaban.
 */
private fun money(raw: String): BigDecimal =
    Money.parse(raw) ?: throw IllegalArgumentException("Nilai uang dari server tidak dapat dibaca")

private fun String?.clean(): String? = this?.trim()?.takeIf { it.isNotEmpty() }

internal fun OrderSummaryDto.toDomain() = OrderSummary(
    orderNumber = orderNumber,
    status = OrderStatus.fromWire(status),
    fulfilmentMethod = FulfilmentMethod.fromWire(fulfilmentMethod),
    total = money(total),
    itemCount = itemCount.coerceAtLeast(0),
    firstItemName = firstItemName.clean(),
    // Cap waktu yang tidak terbaca hanya menghilangkan tanggal di layar, bukan menggagalkan daftar.
    createdAt = IsoTime.parse(createdAt),
    updatedAt = IsoTime.parse(updatedAt),
)

internal fun ShipmentDto.toDomain() = Shipment(
    courierName = courierName.clean(),
    serviceName = serviceName.clean(),
    trackingNumber = trackingNumber.clean(),
    trackingUrl = trackingUrl,
    dispatchedAt = IsoTime.parse(dispatchedAt),
    deliveredAt = IsoTime.parse(deliveredAt),
    shippingCost = Money.parse(shippingCost),
)

internal fun OrderEventDto.toDomain() = OrderEvent(
    status = OrderStatus.fromWire(status),
    type = OrderEventType.fromWire(eventType),
    note = note.clean(),
    occurredAt = IsoTime.parse(occurredAt),
)

/**
 * Linimasa selalu ditampilkan terbaru lebih dulu. Server sudah mengurutkannya; pengurutan ulang
 * di sini (stabil: peristiwa pada detik yang sama tetap dalam urutan server) hanya pengaman, dan
 * dilewati bila ada waktu yang tidak terbaca.
 */
internal fun List<OrderEventDto>.toTimeline(): List<OrderEvent> {
    val events = map { it.toDomain() }
    return if (events.all { it.occurredAt != null }) events.sortedByDescending { it.occurredAt } else events
}

internal fun OrderDetailDto.toDomain() = OrderDetail(
    summary = OrderSummary(
        orderNumber = orderNumber,
        status = OrderStatus.fromWire(status),
        fulfilmentMethod = FulfilmentMethod.fromWire(fulfilmentMethod),
        total = money(total),
        itemCount = itemCount.coerceAtLeast(0),
        firstItemName = firstItemName.clean(),
        createdAt = IsoTime.parse(createdAt),
        updatedAt = IsoTime.parse(updatedAt),
    ),
    subtotal = money(subtotal),
    shippingCost = money(shippingCost),
    shippingCostPending = shippingCostPending,
    contact = OrderContact(
        name = contact?.name.clean().orEmpty(),
        phone = contact?.phone.clean(),
        email = contact?.email.clean(),
    ),
    shippingAddress = shippingAddress?.let { address ->
        OrderAddress(
            recipientName = address.recipientName.clean(),
            phone = address.phone.clean(),
            addressLine = address.addressLine.clean(),
            city = address.city.clean(),
            province = address.province.clean(),
            postalCode = address.postalCode.clean(),
            notes = address.notes.clean(),
        )
    },
    notes = notes.clean(),
    cancelReason = cancelReason.clean(),
    instructions = instructions.clean(),
    items = items.map { item ->
        OrderItem(
            name = item.name,
            sku = item.sku.clean(),
            unitPrice = money(item.unitPrice),
            quantity = item.qty.coerceAtLeast(0),
            lineTotal = money(item.lineTotal),
        )
    },
    shipment = shipment?.toDomain(),
    timeline = timeline.toTimeline(),
)

internal fun OrderTrackingDto.toDomain() = OrderTracking(
    orderNumber = orderNumber,
    status = OrderStatus.fromWire(status),
    fulfilmentMethod = FulfilmentMethod.fromWire(fulfilmentMethod),
    updatedByStore = updatedBy == UPDATED_BY_STORE,
    shipment = shipment?.toDomain(),
    timeline = timeline.toTimeline(),
)
