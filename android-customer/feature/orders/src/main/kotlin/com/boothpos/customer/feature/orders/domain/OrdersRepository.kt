package com.boothpos.customer.feature.orders.domain

import com.boothpos.customer.core.data.domain.model.Paged
import com.boothpos.customer.core.network.error.ApiResult

/**
 * Pesanan milik akun yang sedang masuk (kontrak §8, hanya baca). Server yang menentukan
 * kepemilikan: nomor pesanan milik akun lain dijawab 404 `not_found`, sama seperti nomor yang
 * tidak ada. Tidak ada yang disimpan di perangkat (pesanan memuat kontak dan alamat).
 */
interface OrdersRepository {
    /** Satu halaman daftar pesanan, terbaru lebih dulu (`page` mulai dari 1). */
    suspend fun orders(page: Int): ApiResult<Paged<OrderSummary>>

    suspend fun order(orderNumber: String): ApiResult<OrderDetail>

    /** Status, pengiriman, dan linimasa terbaru, plus tanda "diperbarui oleh toko". */
    suspend fun tracking(orderNumber: String): ApiResult<OrderTracking>
}
