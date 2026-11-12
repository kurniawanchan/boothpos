package com.boothpos.customer.core.data.domain.util

import kotlinx.coroutines.flow.Flow

/** Status koneksi perangkat, untuk pita "offline" dan untuk menunda muat ulang yang pasti gagal. */
interface ConnectivityObserver {
    val isOnline: Flow<Boolean>
}
