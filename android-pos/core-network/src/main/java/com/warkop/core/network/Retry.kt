package com.warkop.core.network

import com.warkop.core.domain.ApiError
import kotlinx.coroutines.delay

/**
 * Coba ulang HANYA untuk gangguan jaringan (ApiError.Network), dengan jeda naik. Galat server (409/422/…) tidak
 * diulang: itu jawaban yang sah dan harus ditampilkan. Aman dipakai untuk tulis karena kuncinya sama tiap percobaan.
 */
suspend fun <T> retryNetwork(attempts: Int = 2, delayMs: Long = 700, block: suspend () -> T): T {
    var tries = 0
    while (true) {
        try {
            return block()
        } catch (e: ApiError.Network) {
            if (++tries > attempts) throw e
            delay(delayMs * tries)
        }
    }
}
