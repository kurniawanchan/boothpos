package com.warkop.ui.shift

import androidx.compose.foundation.layout.Column
import androidx.compose.foundation.layout.fillMaxSize
import androidx.compose.material3.Button
import androidx.compose.material3.Text
import androidx.compose.runtime.Composable
import androidx.compose.ui.Modifier

@Composable
fun ShiftOpenScreen(onShiftOpened: () -> Unit) {
    Column(modifier = Modifier.fillMaxSize()) {
        Text(text = "Shift Open Screen (stub)")
        Button(onClick = { onShiftOpened() }) {
            Text(text = "Open Shift")
        }
    }
}
