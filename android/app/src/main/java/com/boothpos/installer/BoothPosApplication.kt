package com.boothpos.installer

import android.app.Application
import android.app.NotificationChannel
import android.app.NotificationManager
import android.os.Build

/**
 * 008-android-installer — application entry point. Deliberately holds no
 * BoothPOS business logic (research.md R1): its only job here is
 * registering the notification channel RuntimeForegroundService's
 * persistent "BoothPOS is running" notification needs (research.md R3).
 */
class BoothPosApplication : Application() {
    override fun onCreate() {
        super.onCreate()

        if (Build.VERSION.SDK_INT >= Build.VERSION_CODES.O) {
            val channel = NotificationChannel(
                RuntimeForegroundService.NOTIFICATION_CHANNEL_ID,
                getString(R.string.notification_channel_runtime),
                NotificationManager.IMPORTANCE_LOW,
            ).apply {
                description = getString(R.string.notification_channel_runtime_description)
            }
            getSystemService(NotificationManager::class.java).createNotificationChannel(channel)
        }
    }
}
