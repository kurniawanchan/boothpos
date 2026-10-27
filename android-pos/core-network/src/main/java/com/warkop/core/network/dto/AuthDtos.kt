package com.warkop.core.network.dto

import kotlinx.serialization.SerialName
import kotlinx.serialization.Serializable

@Serializable data class AuthUserDto(
    val id: Long,
    val name: String,
    val username: String? = null,
    val role: String? = null,
    @SerialName("menu_keys") val menuKeys: List<String> = emptyList(),
    val permissions: List<String> = emptyList(),
    @SerialName("is_active") val isActive: Boolean = true,
    @SerialName("photo_url") val photoUrl: String? = null,
)

/**
 * 073 — penyiapan terminal TANPA login owner/admin: daftar outlet, daftar terminal outlet itu, dan pilih-atau-daftarkan terminal
 * berdasarkan nama (server mengembalikan terminal yang sudah ada bila namanya sama). Terminal dikenali lewat nama (064).
 */
@Serializable data class SetupOutletDto(val id: Long, val name: String)

@Serializable data class SetupOutletsResponse(val data: List<SetupOutletDto> = emptyList())

@Serializable data class SetupTerminalDto(val id: Long, val name: String)

@Serializable data class SetupTerminalsResponse(val data: List<SetupTerminalDto> = emptyList())

@Serializable data class RegisterTerminalRequest(val name: String)

@Serializable data class RegisterTerminalResponse(val id: Long, val name: String, val created: Boolean = false)

@Serializable data class RosterItemDto(
    val id: Long,
    val name: String,
    val role: String? = null,
    // 077 — foto untuk avatar layar PIN (null = inisial).
    @SerialName("photo_url") val photoUrl: String? = null,
    @SerialName("has_pin") val hasPin: Boolean = false,
    val locked: Boolean = false,
)

@Serializable data class RosterResponse(val data: List<RosterItemDto> = emptyList())

@Serializable data class PinLoginRequest(
    @SerialName("outlet_id") val outletId: Long,
    @SerialName("user_id") val userId: Long,
    val pin: String,
    /** 073 — terminal yang dipilih di layar penyiapan; server menolak (422) bila tidak aktif atau bukan milik outlet. */
    @SerialName("device_id") val deviceId: Long,
)

@Serializable data class PinLoginResponse(
    val token: String,
    @SerialName("expires_at") val expiresAt: String,
    val user: AuthUserDto,
)

@Serializable data class RefreshResponse(val token: String, @SerialName("expires_at") val expiresAt: String)
