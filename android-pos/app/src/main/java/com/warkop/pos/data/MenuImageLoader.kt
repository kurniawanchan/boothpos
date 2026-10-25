package com.warkop.pos.data

import android.graphics.Bitmap
import android.graphics.BitmapFactory
import androidx.compose.runtime.getValue
import androidx.compose.runtime.mutableIntStateOf
import androidx.compose.runtime.setValue
import androidx.compose.ui.graphics.ImageBitmap
import androidx.compose.ui.graphics.asImageBitmap
import kotlinx.coroutines.Dispatchers
import kotlinx.coroutines.withContext
import okhttp3.OkHttpClient
import okhttp3.Request
import java.io.IOException
import java.util.concurrent.TimeUnit

/**
 * 075 — cache gambar dengan batas BYTE (LRU). Berbeda dari [QrImageLoader] yang menyimpan semua tanpa batas: satu menu
 * outlet bisa memuat ratusan foto, dan bitmap ukuran penuh sebanyak itu menghabiskan memori tablet. [fetch] dan [decode]
 * disuntikkan supaya aturan cache (hit, gagal tidak disimpan, buang yang paling lama) bisa diuji tanpa Android.
 * Galat apa pun (jaringan, bukan gambar) menghasilkan null — tidak pernah melempar ke layar.
 */
class BoundedImageCache<V : Any>(
    private val maxBytes: Long,
    private val sizeOf: (V) -> Int,
    private val fetch: suspend (String) -> ByteArray?,
    private val decode: (ByteArray) -> V?,
) {
    private val map = object : LinkedHashMap<String, V>(16, 0.75f, true) {}
    private var bytes = 0L

    @Synchronized fun peek(url: String): V? = map[url]

    suspend fun load(url: String): V? {
        peek(url)?.let { return it }
        val value = try { fetch(url)?.let(decode) } catch (_: IOException) { null } catch (_: IllegalArgumentException) { null } ?: return null
        put(url, value)
        return value
    }

    @Synchronized private fun put(url: String, value: V) {
        map.remove(url)?.let { bytes -= sizeOf(it) }
        map[url] = value
        bytes += sizeOf(value)
        // Buang yang paling lama dipakai, tetapi selalu sisakan entri yang baru masuk.
        val it = map.entries.iterator()
        while (bytes > maxBytes && map.size > 1 && it.hasNext()) {
            val e = it.next()
            if (e.key == url) continue
            bytes -= sizeOf(e.value); it.remove()
        }
    }

    @Synchronized fun clear() { map.clear(); bytes = 0 }
    @Synchronized fun size(): Int = map.size
}

/**
 * 075 — foto produk/varian dari URL server (disk publik, jadi tanpa token seperti QR dan logo). Didecode dengan
 * pengecilan ke kira-kira [TARGET_PX] supaya kartu tidak menyimpan foto ukuran penuh.
 */
object MenuImageLoader {
    private const val TARGET_PX = 512
    private const val MAX_BYTES = 24L * 1024 * 1024

    private val http = OkHttpClient.Builder()
        .connectTimeout(10, TimeUnit.SECONDS)
        .readTimeout(15, TimeUnit.SECONDS)
        .build()

    private val cache = BoundedImageCache<ImageBitmap>(
        MAX_BYTES, { it.width * it.height * 4 },
        fetch = { url ->
            withContext(Dispatchers.IO) {
                http.newCall(Request.Builder().url(url).build()).execute().use { res ->
                    if (!res.isSuccessful) null else res.body?.bytes()
                }
            }
        },
        decode = { bytes -> decodeSampled(bytes)?.asImageBitmap() },
    )

    /** Pencarian sinkron: dipakai sebagai nilai awal supaya foto yang sudah dimuat tidak berkedip jadi inisial saat digulir. */
    fun peek(url: String): ImageBitmap? = cache.peek(url)

    suspend fun load(url: String): ImageBitmap? = cache.load(url)

    /** Tarik-untuk-segarkan: foto yang ditimpa dengan nama berkas sama tidak boleh tertinggal usang (FR-018). */
    fun clear() { cache.clear(); generation++ }

    /** Naik setiap [clear]; kunci produceState di ProductPhoto, jadi foto yang tampil dimuat ulang (foto lama tetap sampai yang baru tiba). */
    var generation by mutableIntStateOf(0)
        private set

    private fun decodeSampled(bytes: ByteArray): Bitmap? {
        val bounds = BitmapFactory.Options().apply { inJustDecodeBounds = true }
        BitmapFactory.decodeByteArray(bytes, 0, bytes.size, bounds)
        var sample = 1
        while (bounds.outWidth / (sample * 2) >= TARGET_PX && bounds.outHeight / (sample * 2) >= TARGET_PX) sample *= 2
        return BitmapFactory.decodeByteArray(bytes, 0, bytes.size, BitmapFactory.Options().apply { inSampleSize = sample })
    }
}
