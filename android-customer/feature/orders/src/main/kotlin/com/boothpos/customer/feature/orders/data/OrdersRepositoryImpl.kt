package com.boothpos.customer.feature.orders.data

import com.boothpos.customer.core.data.domain.model.Paged
import com.boothpos.customer.core.data.remote.toDomain
import com.boothpos.customer.core.network.error.ApiResult
import com.boothpos.customer.core.network.error.SafeApiCall
import com.boothpos.customer.feature.orders.domain.OrderDetail
import com.boothpos.customer.feature.orders.domain.OrderSummary
import com.boothpos.customer.feature.orders.domain.OrderTracking
import com.boothpos.customer.feature.orders.domain.OrdersRepository
import javax.inject.Inject

/**
 * Pesanan pelanggan dari API, tanpa cache di perangkat: pesanan memuat kontak dan alamat, dan
 * statusnya hanya bermakna bila terbaru. Pemetaan dilakukan di dalam `safeApiCall`, sehingga
 * jawaban yang tidak dapat dipahami menjadi `ApiError.Unknown`, bukan crash.
 */
class OrdersRepositoryImpl @Inject constructor(
    private val api: OrdersApi,
    private val safeApiCall: SafeApiCall,
) : OrdersRepository {

    override suspend fun orders(page: Int): ApiResult<Paged<OrderSummary>> = safeApiCall {
        val envelope = api.orders(page = page, perPage = PAGE_SIZE)
        Paged(items = envelope.data.map { it.toDomain() }, page = envelope.meta.toDomain())
    }

    override suspend fun order(orderNumber: String): ApiResult<OrderDetail> =
        safeApiCall { api.order(orderNumber).data.toDomain() }

    override suspend fun tracking(orderNumber: String): ApiResult<OrderTracking> =
        safeApiCall { api.tracking(orderNumber).data.toDomain() }

    companion object {
        /** Ukuran halaman yang diminta (bawaan server; batas server 50). */
        const val PAGE_SIZE = 20
    }
}
