package com.boothpos.customer.core.ui.image

import android.content.Context
import coil3.ImageLoader
import coil3.disk.DiskCache
import coil3.memory.MemoryCache
import coil3.network.okhttp.OkHttpNetworkFetcherFactory
import java.util.concurrent.TimeUnit
import okhttp3.OkHttpClient
import okio.Path.Companion.toOkioPath

/**
 * ImageLoader dengan cache yang DIBATASI: katalog bisa berisi banyak gambar dan perangkat sasaran
 * tidak selalu lega memori/penyimpanannya.
 *
 * Klien HTTP gambar sengaja TERPISAH dari klien API: permintaan gambar tidak pernah membawa
 * token pelanggan maupun header API, ke host mana pun URL gambar itu menunjuk.
 */
object BoundedImageLoader {
    const val MEMORY_CACHE_PERCENT = 0.15
    const val DISK_CACHE_MAX_BYTES = 50L * 1024 * 1024
    private const val DISK_CACHE_DIR = "image_cache"

    fun create(context: Context): ImageLoader {
        val appContext = context.applicationContext
        return ImageLoader.Builder(appContext)
            .memoryCache {
                MemoryCache.Builder().maxSizePercent(appContext, MEMORY_CACHE_PERCENT).build()
            }
            .diskCache {
                DiskCache.Builder()
                    .directory(appContext.cacheDir.resolve(DISK_CACHE_DIR).toOkioPath())
                    .maxSizeBytes(DISK_CACHE_MAX_BYTES)
                    .build()
            }
            .components {
                // Lambda: OkHttpClient baru dibuat saat gambar pertama diminta, di luar main thread.
                add(OkHttpNetworkFetcherFactory(callFactory = { imageHttpClient() }))
            }
            .build()
    }

    private fun imageHttpClient(): OkHttpClient = OkHttpClient.Builder()
        .connectTimeout(10, TimeUnit.SECONDS)
        .readTimeout(30, TimeUnit.SECONDS)
        .followSslRedirects(false)
        .build()
}
