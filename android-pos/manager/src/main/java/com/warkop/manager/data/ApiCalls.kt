package com.warkop.manager.data

import com.warkop.core.network.ApiErrorMapper
import com.warkop.core.network.apiCall
import retrofit2.Response

/**
 * Panggilan tanpa badan respons (hapus, aksi status). Retrofit dengan tipe `Response<…>` TIDAK melempar untuk 4xx/5xx,
 * jadi tanpa pemeriksaan ini 409 (mis. kategori masih punya produk aktif) lolos sebagai "berhasil". Satu tempat untuk
 * seluruh modul manajemen.
 */
suspend fun apiUnit(block: suspend () -> Response<*>) {
    val res = apiCall { block() }
    if (!res.isSuccessful) throw ApiErrorMapper.fromHttp(res.code(), runCatching { res.errorBody()?.string() }.getOrNull())
}
