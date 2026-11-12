package com.boothpos.customer.feature.checkout.presentation

import com.boothpos.customer.core.data.domain.model.Address
import com.boothpos.customer.core.data.domain.model.CartLine
import com.boothpos.customer.core.data.domain.model.FulfilmentMethod
import com.boothpos.customer.feature.checkout.domain.CheckoutRepository
import com.boothpos.customer.feature.checkout.domain.CheckoutRequest
import com.boothpos.customer.feature.checkout.domain.PendingOrderIntentStore
import com.boothpos.customer.feature.checkout.domain.PlaceOrderOutcome
import com.boothpos.customer.feature.checkout.domain.PlacedOrder
import com.boothpos.customer.feature.checkout.domain.Quote
import com.boothpos.customer.feature.checkout.domain.QuoteLine
import com.boothpos.customer.feature.checkout.domain.QuoteOutcome
import java.math.BigDecimal
import kotlinx.coroutines.CompletableDeferred

/** Data contoh yang jelas-jelas palsu; tidak ada data pribadi sungguhan di tes. */
internal object CheckoutTestData {
    const val ORDER_NUMBER = "CO-UJI0000001"

    val keychain = CartLine(
        variantId = 31,
        productSlug = "gantungan-kunci-uji",
        productName = "Gantungan Kunci Uji",
        variantName = "Kecil",
        imageUrl = null,
        unitPrice = BigDecimal("15000.00"),
        quantity = 2,
        maxQuantity = 3,
    )

    val sticker = CartLine(
        variantId = 32,
        productSlug = "stiker-uji",
        productName = "Stiker Uji",
        variantName = "A5",
        imageUrl = null,
        unitPrice = BigDecimal("25000.00"),
        quantity = 1,
        maxQuantity = 5,
    )

    fun address(id: Long = 5, label: String? = "Rumah", isDefault: Boolean = true) = Address(
        id = id,
        label = label,
        recipientName = "Pelanggan Uji",
        phone = "0800-0000-0000",
        addressLine = "Jl. Contoh No. $id",
        city = "Kota Uji",
        province = "Provinsi Uji",
        postalCode = "00000",
        notes = null,
        isDefault = isDefault,
    )

    /** Quote seperti yang dihitung server: 2 x Rp15.000 + 1 x Rp25.000 = Rp55.000. */
    fun quote(
        method: FulfilmentMethod = FulfilmentMethod.Pickup,
        keychainPrice: String = "15000.00",
        shippingPending: Boolean = method == FulfilmentMethod.Courier,
    ): Quote {
        val unit = BigDecimal(keychainPrice)
        val keychainTotal = unit.multiply(BigDecimal(2))
        val subtotal = keychainTotal.add(BigDecimal("25000.00"))
        return Quote(
            method = method,
            lines = listOf(
                QuoteLine(31, "gantungan-kunci-uji", "Gantungan Kunci Uji — Kecil", null, unit, 2, keychainTotal, 3),
                QuoteLine(32, "stiker-uji", "Stiker Uji — A5", null, BigDecimal("25000.00"), 1, BigDecimal("25000.00"), 5),
            ),
            subtotal = subtotal,
            shippingCost = BigDecimal("0.00"),
            shippingCostPending = shippingPending,
            total = subtotal,
        )
    }
}

/**
 * Server tiruan untuk checkout: menghitung quote dari harga "server" ([prices]) dan mencatat setiap
 * permintaan, supaya tes dapat memeriksa kunci idempotensi dan isi permintaan yang dikirim.
 */
internal class FakeCheckoutRepository : CheckoutRepository {
    data class Placement(val request: CheckoutRequest, val expectedTotal: BigDecimal, val key: String)

    /** Harga satuan menurut server per varian. */
    val prices = mutableMapOf(31L to BigDecimal("15000.00"), 32L to BigDecimal("25000.00"))

    val quoteRequests = mutableListOf<CheckoutRequest>()
    val placements = mutableListOf<Placement>()

    /** Jawaban berikutnya; bila kosong, quote dihitung dari [prices] dan pesanan berhasil dibuat. */
    val quoteOutcomes = ArrayDeque<QuoteOutcome>()
    val placeOutcomes = ArrayDeque<PlaceOrderOutcome>()

    /** Bila diisi, panggilan menunggu sampai gerbang diselesaikan (meniru permintaan yang masih berjalan). */
    var quoteGate: CompletableDeferred<Unit>? = null
    var placeGate: CompletableDeferred<Unit>? = null

    fun quoteFor(request: CheckoutRequest): Quote {
        val lines = request.items.map { item ->
            val unit = prices.getValue(item.variantId)
            QuoteLine(
                variantId = item.variantId,
                productSlug = "produk-uji-${item.variantId}",
                name = "Produk Uji ${item.variantId}",
                imageUrl = null,
                unitPrice = unit,
                quantity = item.quantity,
                lineTotal = unit.multiply(BigDecimal(item.quantity)),
                maxQuantity = 5,
            )
        }
        val subtotal = lines.fold(BigDecimal("0.00")) { sum, line -> sum.add(line.lineTotal) }
        return Quote(
            method = request.method,
            lines = lines,
            subtotal = subtotal,
            shippingCost = BigDecimal("0.00"),
            shippingCostPending = request.method == FulfilmentMethod.Courier,
            total = subtotal,
        )
    }

    override suspend fun quote(request: CheckoutRequest): QuoteOutcome {
        quoteRequests += request
        quoteGate?.await()
        return quoteOutcomes.removeFirstOrNull() ?: QuoteOutcome.Success(quoteFor(request))
    }

    override suspend fun placeOrder(
        request: CheckoutRequest,
        expectedTotal: BigDecimal,
        idempotencyKey: String,
    ): PlaceOrderOutcome {
        placements += Placement(request, expectedTotal, idempotencyKey)
        placeGate?.await()
        return placeOutcomes.removeFirstOrNull()
            ?: PlaceOrderOutcome.Placed(PlacedOrder(CheckoutTestData.ORDER_NUMBER))
    }
}

/** [PendingOrderIntentStore] di memori; [failing] meniru penyimpanan yang rusak/penuh. */
internal class FakePendingOrderIntentStore : PendingOrderIntentStore {
    data class Entry(val accountId: Long, val fingerprint: String, val key: String)

    var entry: Entry? = null
    var failing = false

    override suspend fun keyFor(accountId: Long, fingerprint: String): String? {
        if (failing) error("penyimpanan rusak")
        return entry?.takeIf { it.accountId == accountId && it.fingerprint == fingerprint }?.key
    }

    override suspend fun remember(accountId: Long, fingerprint: String, key: String) {
        if (failing) error("penyimpanan rusak")
        entry = Entry(accountId, fingerprint, key)
    }

    override suspend fun forget() {
        if (failing) error("penyimpanan rusak")
        entry = null
    }
}
