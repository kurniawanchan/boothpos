package com.warkop.pos.data

import com.warkop.core.network.ApiClient
import com.warkop.core.network.dto.ServerAddressDto
import kotlinx.coroutines.Dispatchers
import kotlinx.coroutines.withContext
import okhttp3.OkHttpClient
import okhttp3.Request
import java.io.IOException
import java.util.concurrent.TimeUnit

/** Hasil mencari alamat server untuk tablet baru (065 / US6). */
sealed interface DiscoveryResult {
    /** Server menjawab dan admin sudah mengisi alamat pilihannya. */
    data class Found(val url: String) : DiscoveryResult

    /** Server menjawab, tetapi pilihan admin (lokal/online) belum punya alamat. */
    data class NotConfigured(val selected: String) : DiscoveryResult

    /** Tidak satu pun alamat bawaan menjawab. */
    data object Unreachable : DiscoveryResult

    /** Aplikasi ini dibangun tanpa alamat bawaan sama sekali. */
    data object NoDefaults : DiscoveryResult
}

/**
 * 065 / US6 (research D8) — tablet baru tidak punya alamat server (kolom ketik sudah dihapus), jadi ia bertanya ke
 * alamat bawaan yang ditanam saat build (lokal dulu, lalu online) alamat mana yang dipilih admin di web "Server
 * address". Server MENJAWAB satu alamat (yang terpilih); alamat itulah yang disimpan tablet dan dipakai semua
 * permintaan. Tidak ada alamat karangan: bila tak satu pun bawaan menjawab → [DiscoveryResult.Unreachable].
 *
 * [probe] mengembalikan jawaban server untuk satu alamat bawaan, atau null bila tidak terjangkau; dipisah supaya
 * aturan urutan dan hasil bisa diuji tanpa jaringan.
 */
class ServerDiscovery(
    private val defaults: List<String>,
    private val probe: suspend (baseUrl: String) -> ServerAddressDto?,
) {
    suspend fun discover(): DiscoveryResult {
        val candidates = defaults.map { it.trim().trimEnd('/') }.filter { it.isNotBlank() }.distinct()
        if (candidates.isEmpty()) return DiscoveryResult.NoDefaults
        for (base in candidates) {
            val answer = probe(base) ?: continue
            // Server menjawab: pilihan admin menentukan alamat. Kosong berarti admin belum mengisinya — berhenti di sini,
            // karena bertanya ke alamat bawaan lain tidak akan mengubah jawaban (satu server, satu database).
            val url = answer.url?.trim()?.trimEnd('/')?.takeIf { it.isNotBlank() }
                ?: return DiscoveryResult.NotConfigured(answer.selected)
            // Alamat pilihan admin yang BEDA dari yang baru saja menjawab (mis. admin memilih online, tablet bertanya lewat
            // lokal) hanya disimpan bila ia sendiri terbukti terjangkau — jangan memindahkan tablet ke alamat mati.
            if (url != base && probe(url) == null) return DiscoveryResult.Unreachable
            return DiscoveryResult.Found(url)
        }
        return DiscoveryResult.Unreachable
    }

    companion object {
        /** Alamat bawaan dari string resource yang diisi saat build (pos.defaultLocalUrl / pos.defaultOnlineUrl): lokal dulu, lalu online. */
        fun fromContext(context: android.content.Context): ServerDiscovery =
            ServerDiscovery(
                listOf(context.getString(com.warkop.pos.R.string.default_local_url), context.getString(com.warkop.pos.R.string.default_online_url)),
                ServerProbe::ask,
            )
    }
}

/** GET {base}/api/v1/server-address tanpa token/header perangkat, batas waktu 3 dtk. Galat apa pun → null. */
object ServerProbe {
    private val http = OkHttpClient.Builder()
        .connectTimeout(3, TimeUnit.SECONDS)
        .readTimeout(3, TimeUnit.SECONDS)
        .callTimeout(5, TimeUnit.SECONDS)
        .build()

    suspend fun ask(baseUrl: String): ServerAddressDto? = withContext(Dispatchers.IO) {
        try {
            val url = (if (baseUrl.startsWith("http")) baseUrl else "http://$baseUrl").trimEnd('/') + "/api/v1/server-address"
            http.newCall(Request.Builder().url(url).header("Accept", "application/json").build()).execute().use { res ->
                if (!res.isSuccessful) return@withContext null
                val body = res.body?.string() ?: return@withContext null
                ApiClient.json.decodeFromString(ServerAddressDto.serializer(), body)
            }
        } catch (_: IOException) {
            null
        } catch (_: IllegalArgumentException) {
            null
        } catch (_: kotlinx.serialization.SerializationException) {
            null
        }
    }
}
