package com.boothpos.customer.feature.checkout.domain

import java.math.BigDecimal

interface CheckoutRepository {
    /** `POST /customer/orders/quote`: harga, stok, dan total terkini dari server; tanpa efek samping. */
    suspend fun quote(request: CheckoutRequest): QuoteOutcome

    /**
     * `POST /customer/orders`. TIDAK pernah mengulang sendiri: pengulangan adalah keputusan
     * pemanggil dan harus membawa [idempotencyKey] yang sama, supaya server memutar ulang pesanan
     * yang sudah tercatat alih-alih membuat yang kedua.
     *
     * @param expectedTotal total dari quote yang dilihat pelanggan; bila berbeda dari hitungan
     * server, server menolak dengan `price_changed`.
     */
    suspend fun placeOrder(
        request: CheckoutRequest,
        expectedTotal: BigDecimal,
        idempotencyKey: String,
    ): PlaceOrderOutcome
}
