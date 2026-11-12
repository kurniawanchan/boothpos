package com.boothpos.customer.feature.orders.presentation

import com.boothpos.customer.core.data.domain.model.FulfilmentMethod
import com.boothpos.customer.core.data.domain.model.PageInfo
import com.boothpos.customer.core.data.domain.model.Paged
import com.boothpos.customer.core.network.error.ApiError
import com.boothpos.customer.core.network.error.ApiResult
import com.boothpos.customer.feature.orders.domain.OrderAddress
import com.boothpos.customer.feature.orders.domain.OrderContact
import com.boothpos.customer.feature.orders.domain.OrderDetail
import com.boothpos.customer.feature.orders.domain.OrderEvent
import com.boothpos.customer.feature.orders.domain.OrderEventType
import com.boothpos.customer.feature.orders.domain.OrderItem
import com.boothpos.customer.feature.orders.domain.OrderStatus
import com.boothpos.customer.feature.orders.domain.OrderSummary
import com.boothpos.customer.feature.orders.domain.OrderTracking
import com.boothpos.customer.feature.orders.domain.OrdersRepository
import com.boothpos.customer.feature.orders.domain.Shipment
import java.math.BigDecimal
import java.time.Instant
import java.time.ZoneId

/** Data contoh pesanan yang jelas-jelas palsu; tidak ada data pribadi sungguhan. */
internal object OrdersTestData {

    const val ORDER_NUMBER = "CO-UJI0000001"
    const val TRACKING_NUMBER = "UJI0000000001"
    const val SAFE_TRACKING_URL = "https://kurir.example.test/lacak?resi=UJI0000000001"

    /** 12 Nov 2026, 10.00 WIB. */
    val createdAt: Instant = Instant.parse("2026-11-12T03:00:00Z")

    /** Zona tetap supaya teks tanggal di tes tidak bergantung pada mesin yang menjalankannya. */
    val dates = OrderDateFormatter(ZoneId.of("Asia/Jakarta"))

    fun number(index: Int) = "CO-UJI" + index.toString().padStart(7, '0')

    fun summary(
        index: Int = 1,
        status: OrderStatus = OrderStatus.AwaitingConfirmation,
        method: FulfilmentMethod? = FulfilmentMethod.Pickup,
        total: String = "55000.00",
        itemCount: Int = 2,
        firstItemName: String? = "Gantungan Kunci Uji — Kecil",
        createdAt: Instant? = this.createdAt,
        updatedAt: Instant? = createdAt,
    ) = OrderSummary(
        orderNumber = number(index),
        status = status,
        fulfilmentMethod = method,
        total = BigDecimal(total),
        itemCount = itemCount,
        firstItemName = firstItemName,
        createdAt = createdAt,
        updatedAt = updatedAt,
    )

    fun summaries(range: IntRange) = range.map { summary(it) }

    fun page(items: List<OrderSummary>, page: Int = 1, lastPage: Int = 1) =
        Paged(items, PageInfo(currentPage = page, perPage = 20, total = lastPage * 20, lastPage = lastPage))

    fun shipment(
        trackingNumber: String? = TRACKING_NUMBER,
        trackingUrl: String? = null,
        serviceName: String? = "REG",
        deliveredAt: Instant? = null,
    ) = Shipment(
        courierName = "JNE",
        serviceName = serviceName,
        trackingNumber = trackingNumber,
        trackingUrl = trackingUrl,
        dispatchedAt = createdAt.plusSeconds(3 * 3600),
        deliveredAt = deliveredAt,
        shippingCost = BigDecimal("12000.00"),
    )

    fun event(
        status: OrderStatus,
        hoursAfterCreation: Long,
        type: OrderEventType = OrderEventType.StatusChanged,
        note: String? = null,
    ) = OrderEvent(status = status, type = type, note = note, occurredAt = createdAt.plusSeconds(hoursAfterCreation * 3600))

    /** Linimasa pesanan kurir yang sudah dikirim, TERBARU LEBIH DULU (seperti dari server). */
    val shippedTimeline = listOf(
        event(OrderStatus.Shipped, 3, note = "Paket diserahkan ke kurir sore ini."),
        event(OrderStatus.Packing, 2),
        event(OrderStatus.Confirmed, 1, type = OrderEventType.ShippingCostSet),
        event(OrderStatus.Confirmed, 1),
        event(OrderStatus.AwaitingConfirmation, 0),
    )

    fun detail(
        status: OrderStatus = OrderStatus.AwaitingConfirmation,
        method: FulfilmentMethod? = FulfilmentMethod.Pickup,
        shipment: Shipment? = null,
        timeline: List<OrderEvent> = listOf(event(status, 0)),
        shippingCost: String = "0.00",
        shippingCostPending: Boolean = false,
        total: String = "55000.00",
        cancelReason: String? = null,
        instructions: String? = "Ambil di meja depan, tunjukkan nomor pesanan.",
        address: OrderAddress? = null,
        notes: String? = null,
    ) = OrderDetail(
        summary = summary(status = status, method = method, total = total),
        subtotal = BigDecimal("55000.00"),
        shippingCost = BigDecimal(shippingCost),
        shippingCostPending = shippingCostPending,
        contact = OrderContact(name = "Pelanggan Uji", phone = "0800-0000-0000", email = "pelanggan.uji@example.test"),
        shippingAddress = address,
        notes = notes,
        cancelReason = cancelReason,
        instructions = instructions,
        items = listOf(
            OrderItem("Gantungan Kunci Uji — Kecil", "UJI0001", BigDecimal("15000.00"), 2, BigDecimal("30000.00")),
            OrderItem("Stiker Uji — A5", "UJI0002", BigDecimal("25000.00"), 1, BigDecimal("25000.00")),
        ),
        shipment = shipment,
        timeline = timeline,
    )

    val address = OrderAddress(
        recipientName = "Penerima Uji",
        phone = "0800-0000-0001",
        addressLine = "Jl. Contoh No. 1",
        city = "Kota Uji",
        province = "Provinsi Uji",
        postalCode = "00000",
        notes = "Pagar hijau",
    )

    /** Pesanan kurir yang sudah dikirim, dengan pengiriman dan linimasa lengkap. */
    fun shippedDetail(shipment: Shipment = shipment()) = detail(
        status = OrderStatus.Shipped,
        method = FulfilmentMethod.Courier,
        shipment = shipment,
        timeline = shippedTimeline,
        shippingCost = "12000.00",
        total = "67000.00",
        instructions = "Pesanan dikirim pada hari kerja.",
        address = address,
    )

    fun tracking(
        detail: OrderDetail,
        updatedByStore: Boolean = true,
        status: OrderStatus = detail.summary.status,
        shipment: Shipment? = detail.shipment,
        timeline: List<OrderEvent> = detail.timeline,
    ) = OrderTracking(
        orderNumber = detail.summary.orderNumber,
        status = status,
        fulfilmentMethod = detail.summary.fulfilmentMethod,
        updatedByStore = updatedByStore,
        shipment = shipment,
        timeline = timeline,
    )
}

/**
 * Fake repositori pesanan. Handler-nya fungsi suspend, sehingga tes dapat meniru jawaban lambat
 * dengan `delay` (waktu virtual) dan memeriksa bahwa jawaban usang dibuang.
 */
internal class FakeOrdersRepository : OrdersRepository {

    val listCalls = mutableListOf<Int>()
    var listHandler: suspend (page: Int) -> ApiResult<Paged<OrderSummary>> =
        { page -> ApiResult.Success(OrdersTestData.page(emptyList(), page)) }

    val detailCalls = mutableListOf<String>()
    var detailHandler: suspend (String) -> ApiResult<OrderDetail> = { ApiResult.Failure(ApiError.Unknown) }

    val trackingCalls = mutableListOf<String>()
    var trackingHandler: suspend (String) -> ApiResult<OrderTracking> = { ApiResult.Failure(ApiError.Unknown) }

    override suspend fun orders(page: Int): ApiResult<Paged<OrderSummary>> {
        listCalls += page
        return listHandler(page)
    }

    override suspend fun order(orderNumber: String): ApiResult<OrderDetail> {
        detailCalls += orderNumber
        return detailHandler(orderNumber)
    }

    override suspend fun tracking(orderNumber: String): ApiResult<OrderTracking> {
        trackingCalls += orderNumber
        return trackingHandler(orderNumber)
    }

    /** Detail dan pelacakan yang saling cocok untuk [detail]. */
    fun serve(detail: OrderDetail, updatedByStore: Boolean = true) {
        detailHandler = { ApiResult.Success(detail) }
        trackingHandler = { ApiResult.Success(OrdersTestData.tracking(detail, updatedByStore)) }
    }
}
