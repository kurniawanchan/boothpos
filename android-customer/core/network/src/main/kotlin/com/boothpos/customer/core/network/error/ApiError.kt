package com.boothpos.customer.core.network.error

import kotlinx.serialization.json.JsonObject

/**
 * Satu-satunya model galat untuk panggilan API. Dibentuk SEKALI di lapisan data; UI memilih pesan
 * dan tindakan berdasarkan [Http.code], bukan dari teks pengecualian.
 *
 * Sengaja tidak membawa Throwable: pesan pengecualian bisa memuat URL atau potongan body, dan
 * tidak boleh sampai ke layar, log, atau laporan crash.
 */
sealed interface ApiError {

    data class Http(
        val status: Int,
        /** Kode mesin dari server; bila body bukan problem+json, diturunkan dari status HTTP. */
        val code: String,
        /** Pesan aman berbahasa Indonesia dari server (`detail`). Null bila server tidak mengirimnya. */
        val detail: String? = null,
        /**
         * Galat per field (422): nama field -> pesan. Kunci terjemahan mentah dari server
         * (`validation.*`) sudah dibuang; daftar kosong berarti "kolom ditolak, tanpa pesan yang
         * layak tampil" (layar memakai teks lokalnya sendiri).
         */
        val fieldErrors: Map<String, List<String>> = emptyMap(),
        /** `errors` mentah untuk bentuk khusus fitur (`errors.items` pada `cart_invalid`, `errors.quote` pada `price_changed`). */
        val errors: JsonObject? = null,
        val requestId: String? = null,
        val retryAfterSeconds: Long? = null,
    ) : ApiError

    /** Tidak ada koneksi / server tidak terjangkau. */
    data object Network : ApiError

    /** Batas waktu terlampaui: untuk permintaan yang mengubah data, hasilnya TIDAK DIKETAHUI. */
    data object Timeout : ApiError

    /** Jawaban tidak dapat dipahami (mis. bentuk JSON tak terduga). */
    data object Unknown : ApiError

    /**
     * Server menjawab baik, tetapi sesi TIDAK dapat disimpan di perangkat (disk penuh, berkas atau
     * kunci Keystore bermasalah). Bukan galat jaringan dan bukan salah pelanggan: mencoba lagi
     * wajar. Satu-satunya pembuatnya adalah `AuthRepositoryImpl`.
     */
    data object SessionStorage : ApiError
}

/** Fitur dimatikan (503 `customer_commerce_disabled`) atau instalasi terkunci lisensi (423). */
val ApiError.isStoreUnavailable: Boolean
    get() = this is ApiError.Http &&
        (code == ApiErrorCodes.CUSTOMER_COMMERCE_DISABLED || status == 423)

val ApiError.isUnauthenticated: Boolean
    get() = this is ApiError.Http && code == ApiErrorCodes.UNAUTHENTICATED

val ApiError.isOffline: Boolean
    get() = this is ApiError.Network || this is ApiError.Timeout
