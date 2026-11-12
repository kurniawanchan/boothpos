package com.boothpos.customer.core.data.domain.store

import com.boothpos.customer.core.data.domain.model.StoreConfig
import com.boothpos.customer.core.network.error.ApiResult
import kotlinx.coroutines.flow.Flow

interface StoreConfigRepository {
    /** Konfigurasi terakhir yang diketahui (tersimpan di perangkat); null sebelum pengambilan pertama. */
    val config: Flow<StoreConfig?>

    /** Mengambil `GET /storefront/config` dan menyimpannya. 423 berarti instalasi terkunci. */
    suspend fun refresh(): ApiResult<StoreConfig>
}
