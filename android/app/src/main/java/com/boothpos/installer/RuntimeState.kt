package com.boothpos.installer

/**
 * T011 — data-model.md's "Runtime State": in-process only, never
 * persisted, recomputed fresh on every launch (research.md R6). Exposed
 * by RuntimeForegroundService to MainActivity via a bound-service
 * callback (see RuntimeForegroundService.StateListener).
 */
enum class ProcessStatus { NOT_STARTED, STARTING, READY, FAILED }

data class RuntimeState(
    val mariaDbStatus: ProcessStatus = ProcessStatus.NOT_STARTED,
    val phpStatus: ProcessStatus = ProcessStatus.NOT_STARTED,
    val localPort: Int? = null,
    val isFirstRun: Boolean = false,
    val errorMessage: String? = null,
) {
    val isReady: Boolean
        get() = mariaDbStatus == ProcessStatus.READY && phpStatus == ProcessStatus.READY && localPort != null

    val hasFailed: Boolean
        get() = mariaDbStatus == ProcessStatus.FAILED || phpStatus == ProcessStatus.FAILED
}
