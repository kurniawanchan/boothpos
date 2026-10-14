package com.warkop.ui.tab

import androidx.lifecycle.ViewModel
import androidx.lifecycle.viewModelScope
import com.warkop.core.domain.model.MenuItem
import com.warkop.core.domain.model.Tab
import com.warkop.core.network.repo.TabRepository
import kotlinx.coroutines.launch

/**
 * Simple ViewModel placeholder for adding items to a Tab and performing
 * supervisor‑approved actions.
 */
class TabViewModel : ViewModel() {
    private var currentTab: Tab = Tab(id = "new-tab")

    /** Add an item to the current tab. */
    fun addItem(item: MenuItem) {
        viewModelScope.launch {
            currentTab = TabRepository.addItemToTab(currentTab, item)
        }
    }

    /** Perform a sensitive action that requires supervisor approval. */
    fun performSensitiveAction(actionId: String, onResult: (Boolean) -> Unit) {
        viewModelScope.launch {
            val approved = com.warkop.core.network.repo.ApprovalRepository.requestApproval(actionId)
            onResult(approved)
        }
    }

    fun getCurrentTab(): Tab = currentTab
}
