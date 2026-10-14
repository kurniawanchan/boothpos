package com.warkop.ui.status

import androidx.compose.runtime.Composable
import androidx.compose.foundation.layout.Row
import androidx.compose.foundation.layout.fillMaxWidth
import androidx.compose.foundation.background
import androidx.compose.material3.Text
import androidx.compose.ui.Modifier
import androidx.compose.ui.graphics.Color

/**
 * Simple connection status bar placeholder.
 * Shows "Online" (green) or "Offline" (red). Replace with real network monitoring.
 */
@Composable
fun ConnectionStatusBar(isConnected: Boolean = true) {
    val background = if (isConnected) Color(0xFF4CAF50) else Color(0xFFF44336)
    Row(
        modifier = Modifier
            .fillMaxWidth()
            .background(background)
    ) {
        Text(
            text = if (isConnected) "Online" else "Offline",
            color = Color.White,
            modifier = Modifier
        )
    }
}
