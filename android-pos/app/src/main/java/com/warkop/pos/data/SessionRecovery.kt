package com.warkop.pos.data

import com.warkop.core.domain.ApiError

/** 057 / US7 — 401 saat token sesi PIN dipakai berarti sesi itu sudah tidak berlaku (tablet offline lama). */
object SessionRecovery {
    fun dropsSession(e: ApiError, hasSession: Boolean): Boolean = hasSession && e is ApiError.Unauthorized
}
