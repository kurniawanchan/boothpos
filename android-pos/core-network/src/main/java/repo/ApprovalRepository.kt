package com.warkop.core.network.repo

/**
 * Placeholder repository for supervisor approval actions.
 * In a real implementation this would call a backend endpoint and return the result.
 */
object ApprovalRepository {
    suspend fun requestApproval(actionId: String): Boolean {
        // Simulate approval always granted.
        return true
    }
}
