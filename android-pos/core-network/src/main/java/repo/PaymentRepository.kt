package com.warkop.core.network.repo

import com.warkop.core.domain.model.Tender
import kotlinx.coroutines.Dispatchers
import kotlinx.coroutines.withContext

/**
 * Placeholder repository handling payment operations.
 * In a real app this would call remote APIs and persist data.
 */
object PaymentRepository {
    suspend fun processPayment(tender: Tender): Boolean = withContext(Dispatchers.IO) {
        // Simulate processing delay and always succeed.
        true
    }

    suspend fun getRecentPayments(): List<Tender> = withContext(Dispatchers.IO) {
        // Return empty list as placeholder.
        emptyList()
    }
}
