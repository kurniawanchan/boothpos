package com.warkop.core.domain.model

// Placeholder Session model
 data class Session(
    val token: String,
    val userId: String,
    val outletId: String,
    val lastActiveAt: Long
)
