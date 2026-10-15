package com.warkop.core.domain.model

/**
 * Placeholder model representing a tab (order) in the POS.
 */
 data class Tab(
    val id: String,
    val items: List<com.warkop.core.domain.model.MenuItem> = emptyList(),
    val totalCents: Int = 0,
    val isClosed: Boolean = false
 )
