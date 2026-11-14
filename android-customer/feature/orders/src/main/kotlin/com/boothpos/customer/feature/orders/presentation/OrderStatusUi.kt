package com.boothpos.customer.feature.orders.presentation

import androidx.annotation.DrawableRes
import androidx.annotation.StringRes
import androidx.compose.runtime.Composable
import androidx.compose.ui.graphics.vector.ImageVector
import androidx.compose.ui.res.vectorResource
import com.boothpos.customer.core.data.domain.model.FulfilmentMethod
import com.boothpos.customer.core.ui.component.StatusTone
import com.boothpos.customer.core.ui.icon.SkyIcons
import com.boothpos.customer.feature.orders.R
import com.boothpos.customer.feature.orders.domain.OrderEvent
import com.boothpos.customer.feature.orders.domain.OrderEventType
import com.boothpos.customer.feature.orders.domain.OrderStatus
import java.time.Instant
import java.time.ZoneId
import java.time.format.DateTimeFormatter
import java.util.Locale

// Kosakata status pesanan: SATU tempat untuk label, penjelasan, ikon, dan nada warna, dipakai
// daftar, kepala detail, dan linimasa. Status selalu dibawa ikon + teks; ikon tiap status berbeda
// BENTUK (jam, centang, kotak, toko, truk, rumah, lingkaran bercentang, silang), bukan hanya warna.

/**
 * Ikon garis desain (`SkyIcons` di :core:ui) atau vector drawable modul ini. Semua status memakai
 * ikon garis, supaya lencana, kepala detail, dan riwayat segaris dengan ikon lain di aplikasi.
 */
internal sealed interface OrderIcon {
    data class Vector(val image: ImageVector) : OrderIcon
    data class Drawable(@DrawableRes val id: Int) : OrderIcon
}

@Composable
internal fun OrderIcon.asImageVector(): ImageVector = when (this) {
    is OrderIcon.Vector -> image
    is OrderIcon.Drawable -> ImageVector.vectorResource(id)
}

internal data class OrderStatusVisual(
    /** Nama status, mis. "Sedang dikemas". */
    @StringRes val label: Int,
    /** Penjelasan sehari-hari tentang arti status itu bagi pelanggan. */
    @StringRes val explanation: Int,
    /** Kalimat peristiwa di linimasa, mis. "Pesanan mulai dikemas". */
    @StringRes val eventLabel: Int,
    val icon: OrderIcon,
    val tone: StatusTone,
)

internal fun OrderStatus.visual(): OrderStatusVisual = when (this) {
    OrderStatus.AwaitingConfirmation -> OrderStatusVisual(
        R.string.orders_status_awaiting_confirmation,
        R.string.orders_status_awaiting_confirmation_explanation,
        R.string.orders_event_awaiting_confirmation,
        OrderIcon.Vector(SkyIcons.Clock),
        StatusTone.Warning,
    )
    OrderStatus.Confirmed -> OrderStatusVisual(
        R.string.orders_status_confirmed,
        R.string.orders_status_confirmed_explanation,
        R.string.orders_event_confirmed,
        OrderIcon.Vector(SkyIcons.Check),
        StatusTone.Info,
    )
    OrderStatus.Packing -> OrderStatusVisual(
        R.string.orders_status_packing,
        R.string.orders_status_packing_explanation,
        R.string.orders_event_packing,
        OrderIcon.Vector(SkyIcons.Box),
        // Kuning lembut ("sedang berjalan"), seperti lencana "Sedang dikemas" di desain.
        StatusTone.Warning,
    )
    OrderStatus.ReadyForPickup -> OrderStatusVisual(
        R.string.orders_status_ready_for_pickup,
        R.string.orders_status_ready_for_pickup_explanation,
        R.string.orders_event_ready_for_pickup,
        OrderIcon.Vector(SkyIcons.Store),
        StatusTone.Info,
    )
    OrderStatus.Shipped -> OrderStatusVisual(
        R.string.orders_status_shipped,
        R.string.orders_status_shipped_explanation,
        R.string.orders_event_shipped,
        OrderIcon.Vector(SkyIcons.Truck),
        StatusTone.Info,
    )
    OrderStatus.Delivered -> OrderStatusVisual(
        R.string.orders_status_delivered,
        R.string.orders_status_delivered_explanation,
        R.string.orders_event_delivered,
        OrderIcon.Vector(SkyIcons.Home),
        StatusTone.Success,
    )
    OrderStatus.PickedUp -> OrderStatusVisual(
        R.string.orders_status_picked_up,
        R.string.orders_status_picked_up_explanation,
        R.string.orders_event_picked_up,
        OrderIcon.Vector(SkyIcons.CheckCircle),
        StatusTone.Success,
    )
    OrderStatus.Cancelled -> OrderStatusVisual(
        R.string.orders_status_cancelled,
        R.string.orders_status_cancelled_explanation,
        R.string.orders_event_cancelled,
        OrderIcon.Vector(SkyIcons.Close),
        // Desain menggambar "Dibatalkan" abu-abu, tetapi abu-abu adalah nada status yang belum
        // dikenal; pembatalan tetap memakai nada bahaya supaya keduanya tidak tertukar.
        StatusTone.Danger,
    )
    // Status baru dari server yang belum dikenal versi aplikasi ini: netral, tidak menebak.
    OrderStatus.Unknown -> OrderStatusVisual(
        R.string.orders_status_unknown,
        R.string.orders_status_unknown_explanation,
        R.string.orders_event_unknown_status,
        OrderIcon.Vector(SkyIcons.Info),
        StatusTone.Neutral,
    )
}

/** Kalimat polos untuk satu peristiwa linimasa. */
@StringRes
internal fun OrderEvent.labelRes(): Int = when (type) {
    OrderEventType.StatusChanged -> status.visual().eventLabel
    OrderEventType.TrackingUpdated -> R.string.orders_event_tracking_updated
    OrderEventType.ShippingCostSet -> R.string.orders_event_shipping_cost_set
    OrderEventType.Unknown -> R.string.orders_event_unknown_type
}

internal fun OrderEvent.icon(): OrderIcon = when (type) {
    OrderEventType.StatusChanged -> status.visual().icon
    OrderEventType.TrackingUpdated -> OrderIcon.Vector(SkyIcons.Edit)
    OrderEventType.ShippingCostSet, OrderEventType.Unknown -> OrderIcon.Vector(SkyIcons.Info)
}

/** Banyaknya tahap pada batang progres di kepala detail pesanan. */
internal const val ORDER_STAGE_COUNT = 5

/**
 * Berapa dari [ORDER_STAGE_COUNT] tahap yang sudah dicapai status ini. Pesanan kurir dan pesanan
 * ambil-di-toko sama-sama lima tahap (menunggu konfirmasi, dikonfirmasi, dikemas, lalu dikirim
 * dan sampai, atau siap diambil dan diambil), jadi metode pemenuhan tidak perlu diketahui di sini.
 *
 * Null berarti batang progres tidak digambar: pesanan yang dibatalkan tidak sedang menuju tahap
 * mana pun, dan posisi status yang belum dikenal versi aplikasi ini tidak boleh ditebak.
 */
internal fun OrderStatus.reachedStages(): Int? = when (this) {
    OrderStatus.AwaitingConfirmation -> 1
    OrderStatus.Confirmed -> 2
    OrderStatus.Packing -> 3
    OrderStatus.ReadyForPickup, OrderStatus.Shipped -> 4
    OrderStatus.Delivered, OrderStatus.PickedUp -> ORDER_STAGE_COUNT
    OrderStatus.Cancelled, OrderStatus.Unknown -> null
}

/** Null untuk metode yang belum dikenal versi aplikasi ini (barisnya tidak ditampilkan). */
@StringRes
internal fun FulfilmentMethod?.labelRes(): Int? = when (this) {
    FulfilmentMethod.Pickup -> R.string.orders_method_pickup
    FulfilmentMethod.Courier -> R.string.orders_method_courier
    null -> null
}

/**
 * Tanggal dan jam untuk pesanan, gaya Indonesia ("12 Nov 2026, 14.05"), pada zona waktu
 * perangkat. Lokalnya tetap id-ID (seperti format Rupiah) karena seluruh teks aplikasi saat ini
 * berbahasa Indonesia; satu tempat ini yang diubah bila terjemahan lain ditambahkan.
 */
internal class OrderDateFormatter(private val zone: ZoneId) {
    private val formatter = DateTimeFormatter.ofPattern("d MMM yyyy, HH.mm", Locale.forLanguageTag("id-ID"))

    fun format(instant: Instant): String = formatter.format(instant.atZone(zone))

    companion object {
        fun system() = OrderDateFormatter(ZoneId.systemDefault())
    }
}

/** Tag tes untuk node yang teksnya tidak unik di layar. */
internal object OrdersTestTags {
    const val ORDER_LIST = "orders_list"
    const val ORDER_ROW = "orders_row"
    const val DETAIL_STATUS = "orders_detail_status"
    const val DETAIL_ORDER_NUMBER = "orders_detail_order_number"
    const val DETAIL_TOTAL = "orders_detail_total"
    const val DETAIL_SHIPPING_COST = "orders_detail_shipping_cost"
    const val TRACKING_SECTION = "orders_tracking_section"
    const val TIMELINE_EVENT = "orders_timeline_event"
}
