package com.warkop.core.network.repo

import com.warkop.core.domain.model.Session
import com.warkop.core.network.ApiService
import kotlinx.coroutines.Dispatchers
import kotlinx.coroutines.withContext

/**
 * Placeholder ShiftRepository.
 * In a real app this would call backend APIs to open a shift and return a Session.
 */
object ShiftRepository {
    suspend fun openShift(userId: String, outletId: String): Session = withContext(Dispatchers.IO) {
        // Dummy implementation – returns a Session with a fake token.
        Session(
            token = "shift-token-$userId",
            userId = userId,
            outletId = outletId,
            lastActiveAt = System.currentTimeMillis()
        )
    }
}
