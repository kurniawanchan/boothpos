package com.warkop.core.domain.model

/**
 * Placeholder model for a menu item.
 */
 data class MenuItem(
    val id: String,
    val name: String,
    val priceCents: Int,
    val modifiers: List<String> = emptyList()
 )
