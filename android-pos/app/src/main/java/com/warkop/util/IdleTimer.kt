package com.warkop.util

import kotlinx.coroutines.Job
import kotlinx.coroutines.delay
import kotlinx.coroutines.launch
import kotlinx.coroutines.CoroutineScope
import kotlinx.coroutines.Dispatchers

/**
 * Simple idle timer utility that triggers a callback after a period of inactivity.
 * In a real app you would reset the timer on user interaction events.
 */
object IdleTimer {
    private var job: Job? = null
    private const val DEFAULT_TIMEOUT_MS = 5 * 60 * 1000L // 5 minutes

    fun start(timeoutMs: Long = DEFAULT_TIMEOUT_MS, onIdle: () -> Unit) {
        job?.cancel()
        job = CoroutineScope(Dispatchers.Main).launch {
            delay(timeoutMs)
            onIdle()
        }
    }

    fun reset(timeoutMs: Long = DEFAULT_TIMEOUT_MS, onIdle: () -> Unit) {
        start(timeoutMs, onIdle)
    }

    fun cancel() {
        job?.cancel()
        job = null
    }
}
