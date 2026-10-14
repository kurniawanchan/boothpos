package com.warkop.core.domain.model

/**
 * Placeholder Session model (already created earlier). This file is kept for completeness.
 */
 data class Session(
    val token: String,
    val userId: String,
    val outletId: String,
    val lastActiveAt: Long
)
