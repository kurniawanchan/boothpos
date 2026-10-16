package com.warkop.core.network.dto

import kotlinx.serialization.SerialName
import kotlinx.serialization.Serializable
import kotlinx.serialization.json.JsonObject

// 048 / S3 — persetujuan (US4). Klien mengirim `subject` (parameter aksi apa adanya); server yang menghitung hash-nya.
// Klien TIDAK pernah menghitung hash. Token hanya dipakai satu kali untuk aksi yang disetujui.

/** Penyetuju yang memenuhi syarat untuk satu aksi (tanpa peminta). */
@Serializable data class ApproverDto(
    val id: Long,
    val name: String = "",
    val role: String = "",
)

/** POST /overrides. Kunci `pin` tidak pernah dicatat (RedactedLogInterceptor hanya mencatat metode+path+status). */
@Serializable data class OverrideRequest(
    val action: String,
    val subject: JsonObject,
    @SerialName("approver_user_id") val approverUserId: Long,
    val pin: String,
)

@Serializable data class OverrideResponse(
    @SerialName("override_token") val overrideToken: String,
    @SerialName("expires_at") val expiresAt: String? = null,
)
