package com.warkop.manager.data.dto

import com.warkop.core.network.dto.AuthUserDto
import kotlinx.serialization.SerialName
import kotlinx.serialization.Serializable

/** 094 / kontrak A1 — `POST /auth/manager-sessions`. */
@Serializable data class ManagerLoginRequest(
    val username: String,
    val password: String,
    @SerialName("outlet_id") val outletId: Long,
)

@Serializable data class ManagerLoginResponse(val token: String, val user: AuthUserDto)

/** Amplop paginasi buatan tangan server: `{data, meta{current_page, per_page, total, last_page}}`. */
@Serializable data class PageMeta(
    @SerialName("current_page") val currentPage: Int = 1,
    @SerialName("per_page") val perPage: Int = 25,
    val total: Int = 0,
    @SerialName("last_page") val lastPage: Int = 1,
)

@Serializable data class Paged<T>(val data: List<T> = emptyList(), val meta: PageMeta = PageMeta())
