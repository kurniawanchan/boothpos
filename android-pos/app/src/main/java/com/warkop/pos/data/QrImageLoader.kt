package com.warkop.pos.data

import android.graphics.BitmapFactory
import androidx.compose.ui.graphics.ImageBitmap
import androidx.compose.ui.graphics.asImageBitmap
import kotlinx.coroutines.Dispatchers
import kotlinx.coroutines.withContext
import okhttp3.OkHttpClient
import okhttp3.Request
import java.io.IOException
import java.util.concurrent.ConcurrentHashMap
import java.util.concurrent.TimeUnit

/**
 * 056 / T016 — gambar QR statis dari URL server. Gambar publik (disk publik di server), jadi klien ini tidak membawa
 * token atau interceptor log; hanya batas waktu. Hasil disimpan di memori per URL: satu unduhan per URL per proses.
 * Galat apa pun (jaringan, status non-2xx, bytes bukan gambar) menghasilkan null, bukan pengecualian.
 */
object QrImageLoader {
    private val cache = ConcurrentHashMap<String, ImageBitmap>()
    private val http = OkHttpClient.Builder()
        .connectTimeout(10, TimeUnit.SECONDS)
        .readTimeout(15, TimeUnit.SECONDS)
        .build()

    /** 056 amandemen — gambar lama dibuang dari cache setelah QR diganti, supaya layar tidak menampilkan yang usang. */
    fun evict(url: String) { cache.remove(url) }

    suspend fun load(url: String): ImageBitmap? {
        cache[url]?.let { return it }
        return withContext(Dispatchers.IO) {
            try {
                http.newCall(Request.Builder().url(url).build()).execute().use { res ->
                    if (!res.isSuccessful) return@withContext null
                    val bytes = res.body?.bytes() ?: return@withContext null
                    BitmapFactory.decodeByteArray(bytes, 0, bytes.size)?.asImageBitmap()?.also { cache[url] = it }
                }
            } catch (_: IOException) {
                null
            } catch (_: IllegalArgumentException) {
                null
            }
        }
    }
}
