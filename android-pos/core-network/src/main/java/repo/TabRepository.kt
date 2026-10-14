package com.warkop.core.network.repo

import com.warkop.core.domain.model.MenuItem
import com.warkop.core.domain.model.Tab
import kotlinx.coroutines.Dispatchers
import kotlinx.coroutines.withContext

/**
 * Placeholder repository for managing tabs (orders).
 * Returns dummy data; replace with real API calls.
 */
object TabRepository {
    suspend fun createTab(): Tab = withContext(Dispatchers.IO) {
        Tab(id = "tab-${System.currentTimeMillis()}")
    }

    suspend fun addItemToTab(tab: Tab, item: MenuItem): Tab = withContext(Dispatchers.IO) {
        // Simple immutable update for placeholder purposes.
        val updatedItems = tab.items + item
        val newTotal = updatedItems.sumOf { it.priceCents }
        tab.copy(items = updatedItems, totalCents = newTotal)
    }
}
