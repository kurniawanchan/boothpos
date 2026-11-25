package com.warkop.manager.data

import android.content.ContentResolver
import android.net.Uri
import com.warkop.core.domain.ApiError
import okhttp3.MediaType.Companion.toMediaType
import okhttp3.MultipartBody
import okhttp3.RequestBody.Companion.toRequestBody

/**
 * 094 / D11 — foto dari Photo Picker/kamera menjadi bagian multipart `image`. Batas SAMA dengan server
 * (`ImageUploadService::MAX_KILOBYTES` = 5 MB, `mimes:jpeg,png`); dicek di sini hanya supaya gagal cepat tanpa
 * mengunggah 5 MB lewat data seluler — penegaknya tetap server.
 */
object ImageUpload {
    const val MAX_BYTES = 5 * 1024 * 1024
    private val ALLOWED = setOf("image/jpeg", "image/png")

    fun part(resolver: ContentResolver, uri: Uri, field: String = "image"): MultipartBody.Part {
        val mime = resolver.getType(uri)?.lowercase() ?: "image/jpeg" // kamera sistem menulis JPEG ke berkas kita (tanpa tipe)
        if (mime !in ALLOWED) throw ApiError.Validation("Foto harus JPG atau PNG.", mapOf(field to listOf("Foto harus JPG atau PNG.")))
        val bytes = resolver.openInputStream(uri)?.use { it.readBytes() }
            ?: throw ApiError.Validation("Foto tidak bisa dibaca.", mapOf(field to listOf("Foto tidak bisa dibaca.")))
        if (bytes.size > MAX_BYTES) throw ApiError.Validation("Ukuran foto maksimal 5 MB.", mapOf(field to listOf("Ukuran foto maksimal 5 MB.")))
        val ext = if (mime == "image/png") "png" else "jpg"
        return MultipartBody.Part.createFormData(field, "photo.$ext", bytes.toRequestBody(mime.toMediaType()))
    }
}
