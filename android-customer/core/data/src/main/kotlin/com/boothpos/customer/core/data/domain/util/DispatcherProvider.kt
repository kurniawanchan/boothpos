package com.boothpos.customer.core.data.domain.util

import kotlinx.coroutines.CoroutineDispatcher

/** Dispatcher disuntikkan supaya tes deterministik (tidak ada Dispatchers.* langsung di kode). */
interface DispatcherProvider {
    val main: CoroutineDispatcher
    val io: CoroutineDispatcher
    val default: CoroutineDispatcher
}
