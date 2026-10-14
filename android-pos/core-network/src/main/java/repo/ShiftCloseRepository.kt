package com.warkop.core.network.repo

/**
 * Placeholder repository for closing a shift.
 * In a real implementation this would send shift summary data to a backend.
 */
object ShiftCloseRepository {
    suspend fun closeShift(shiftId: String): Boolean {
        // Simulate always successful closure.
        return true
    }
}
