package com.warkop.core.network.dto

import kotlinx.serialization.SerialName
import kotlinx.serialization.Serializable

@Serializable data class LoginRequest(val username: String, val password: String)

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

@Serializable data class LoginResponse(val token: String, val user: AuthUserDto)

@Serializable data class OutletDto(val id: Long, val name: String, val code: String? = null)

@Serializable data class ScopeResponse(
    val outlets: List<OutletDto> = emptyList(),
    val selected: Long? = null,
    @SerialName("can_see_all") val canSeeAll: Boolean = false,
    val multi: Boolean = false,
)

@Serializable data class PairDeviceRequest(val name: String, val code: String? = null)

@Serializable data class DeviceDto(
    val id: Long,
    val code: String,
    val name: String,
    @SerialName("outlet_id") val outletId: Long,
    val revoked: Boolean = false,
)

@Serializable data class RosterItemDto(
    val id: Long,
    val name: String,
    val role: String? = null,
    @SerialName("has_pin") val hasPin: Boolean = false,
    val locked: Boolean = false,
)

@Serializable data class RosterResponse(val data: List<RosterItemDto> = emptyList())

@Serializable data class PinLoginRequest(
    @SerialName("outlet_id") val outletId: Long,
    @SerialName("user_id") val userId: Long,
    val pin: String,
)

@Serializable data class PinLoginResponse(
    val token: String,
    @SerialName("expires_at") val expiresAt: String,
    val user: AuthUserDto,
)

@Serializable data class RefreshResponse(val token: String, @SerialName("expires_at") val expiresAt: String)
