package com.warkop.util

import kotlinx.coroutines.flow.Flow
import kotlinx.coroutines.flow.flow
import kotlinx.coroutines.delay

/**
 * Simple placeholder monitor that emits a dummy health status every few seconds.
 * In a real implementation this would check battery, network, etc.
 */
object DeviceHealthMonitor {
    /**
     * Emits "HEALTHY" constantly after a short delay.
     */
    fun healthStatus(): Flow<String> = flow {
        while (true) {
            emit("HEALTHY")
            delay(5000) // emit every 5 seconds
        }
    }
}
