package com.boothpos.customer.core.network

import javax.inject.Inject
import javax.inject.Singleton
import kotlinx.coroutines.channels.BufferOverflow
import kotlinx.coroutines.flow.MutableSharedFlow
import kotlinx.coroutines.flow.SharedFlow
import kotlinx.coroutines.flow.asSharedFlow

/**
 * Jembatan satu arah dari lapisan HTTP ke lapisan sesi: server menolak (401) sebuah permintaan
 * yang membawa token. Lapisan sesi (:core:data) yang memutuskan untuk menghapus sesi.
 *
 * Token yang ditolak ikut dikirim (hanya di memori, tidak pernah di-log) supaya 401 milik token
 * LAMA yang datang terlambat tidak menghapus sesi baru yang baru saja dibuat.
 */
@Singleton
class UnauthorizedEvents @Inject constructor() {
    private val _rejectedTokens = MutableSharedFlow<String>(
        extraBufferCapacity = 4,
        onBufferOverflow = BufferOverflow.DROP_OLDEST,
    )
    val rejectedTokens: SharedFlow<String> = _rejectedTokens.asSharedFlow()

    fun notifyRejected(token: String) {
        _rejectedTokens.tryEmit(token)
    }
}
