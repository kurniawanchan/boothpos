package com.boothpos.customer.feature.orders.presentation

import android.content.Context
import androidx.test.core.app.ApplicationProvider
import androidx.test.ext.junit.runners.AndroidJUnit4
import com.boothpos.customer.core.data.domain.model.FulfilmentMethod
import com.boothpos.customer.core.ui.component.StatusTone
import com.boothpos.customer.feature.orders.domain.OrderEvent
import com.boothpos.customer.feature.orders.domain.OrderEventType
import com.boothpos.customer.feature.orders.domain.OrderStatus
import java.time.Instant
import java.time.ZoneId
import org.junit.Assert.assertEquals
import org.junit.Assert.assertNotEquals
import org.junit.Assert.assertNull
import org.junit.Assert.assertTrue
import org.junit.Test
import org.junit.runner.RunWith

/** Kosakata status: satu tempat untuk label, penjelasan, kalimat riwayat, ikon, dan nada. */
@RunWith(AndroidJUnit4::class)
class OrderStatusUiTest {

    private val context: Context = ApplicationProvider.getApplicationContext()

    private val contractStatuses = OrderStatus.entries - OrderStatus.Unknown

    @Test
    fun `kedelapan status kontrak dikenali dari nilai kawatnya dan nilai lain menjadi tidak dikenal`() {
        assertEquals(
            listOf(
                "awaiting_confirmation", "confirmed", "packing", "ready_for_pickup",
                "shipped", "delivered", "picked_up", "cancelled",
            ),
            contractStatuses.map { it.wireValue },
        )
        contractStatuses.forEach { status -> assertEquals(status, OrderStatus.fromWire(status.wireValue)) }
        listOf(null, "", "refunded", "SHIPPED", " shipped").forEach { value ->
            assertEquals("nilai: $value", OrderStatus.Unknown, OrderStatus.fromWire(value))
        }
    }

    @Test
    fun `setiap status punya label penjelasan dan kalimat riwayat yang berbeda`() {
        val visuals = OrderStatus.entries.map { it.visual() }
        val labels = visuals.map { context.getString(it.label) }
        val explanations = visuals.map { context.getString(it.explanation) }
        val eventLabels = visuals.map { context.getString(it.eventLabel) }

        listOf(labels, explanations, eventLabels).forEach { texts ->
            assertTrue(texts.all { it.isNotBlank() })
            assertEquals("Tidak ada dua status dengan teks yang sama", texts.size, texts.toSet().size)
        }
    }

    @Test
    fun `ikon tiap status berbeda bentuk bukan hanya warna`() {
        val icons = OrderStatus.entries.map { it.visual().icon }

        // Sembilan gambar berbeda: status tetap terbedakan tanpa warna (mis. layar abu-abu).
        assertEquals(OrderStatus.entries.size, icons.toSet().size)
        // Beberapa status memang berbagi nada warna; karena itu bentuk ikon yang membedakannya.
        val tones = OrderStatus.entries.map { it.visual().tone }
        assertTrue(tones.toSet().size < OrderStatus.entries.size)
    }

    @Test
    fun `status yang belum dikenal memakai nada netral`() {
        assertEquals(StatusTone.Neutral, OrderStatus.Unknown.visual().tone)
        assertEquals("Status diperbarui", context.getString(OrderStatus.Unknown.visual().label))
        assertNotEquals(StatusTone.Neutral, OrderStatus.Cancelled.visual().tone)
    }

    @Test
    fun `peristiwa pelacakan dan ongkos kirim punya kalimat sendiri apa pun statusnya`() {
        fun label(status: OrderStatus, type: OrderEventType) =
            context.getString(OrderEvent(status, type, note = null, occurredAt = null).labelRes())

        assertEquals("Toko memperbarui informasi pengiriman", label(OrderStatus.Shipped, OrderEventType.TrackingUpdated))
        assertEquals("Toko menetapkan ongkos kirim", label(OrderStatus.Confirmed, OrderEventType.ShippingCostSet))
        assertEquals("Pesanan diperbarui", label(OrderStatus.Shipped, OrderEventType.Unknown))
        assertEquals("Pesanan diserahkan ke kurir", label(OrderStatus.Shipped, OrderEventType.StatusChanged))
        assertEquals("Status pesanan diperbarui", label(OrderStatus.Unknown, OrderEventType.StatusChanged))
        // Ikon peristiwa pelacakan bukan ikon status "Dikirim": dua baris itu terbedakan sekilas.
        assertNotEquals(
            OrderEvent(OrderStatus.Shipped, OrderEventType.TrackingUpdated, null, null).icon(),
            OrderEvent(OrderStatus.Shipped, OrderEventType.StatusChanged, null, null).icon(),
        )
    }

    @Test
    fun `metode pemenuhan yang belum dikenal tidak diberi label karangan`() {
        assertEquals("Ambil di toko", context.getString(FulfilmentMethod.Pickup.labelRes()!!))
        assertEquals("Dikirim kurir", context.getString(FulfilmentMethod.Courier.labelRes()!!))
        assertNull((null as FulfilmentMethod?).labelRes())
    }

    @Test
    fun `tanggal diformat gaya Indonesia pada zona waktu yang diberikan`() {
        val instant = Instant.parse("2026-11-12T03:05:00Z")

        assertEquals("12 Nov 2026, 10.05", OrderDateFormatter(ZoneId.of("Asia/Jakarta")).format(instant))
        assertEquals("12 Nov 2026, 03.05", OrderDateFormatter(ZoneId.of("UTC")).format(instant))
        // Tanpa nol di depan tanggal; jam selalu 24 jam dua angka.
        assertEquals("1 Nov 2026, 00.00", OrderDateFormatter(ZoneId.of("Asia/Jakarta")).format(Instant.parse("2026-10-31T17:00:00Z")))
    }
}
