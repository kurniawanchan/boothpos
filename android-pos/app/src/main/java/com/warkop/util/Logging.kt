package com.warkop.util

import timber.log.Timber

object Logging {
    fun init() {
        // Initialize Timber. In a real app, plant a DebugTree or custom tree.
        Timber.plant(Timber.DebugTree())
    }
}
