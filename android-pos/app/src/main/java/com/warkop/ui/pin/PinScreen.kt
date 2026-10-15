package com.warkop.ui.pin

import androidx.compose.foundation.layout.Column
import androidx.compose.foundation.layout.fillMaxSize
import androidx.compose.material3.Button
import androidx.compose.material3.Text
import androidx.compose.runtime.Composable
import androidx.compose.runtime.mutableStateOf
import androidx.compose.runtime.remember
import androidx.compose.ui.Modifier

@Composable
fun PinScreen(onPinEntered: (String) -> Unit) {
    val pin = remember { mutableStateOf("") }
    Column(modifier = Modifier.fillMaxSize()) {
        Text(text = "PIN Entry Screen (stub)")
        // In real UI, add TextField for PIN entry
        Button(onClick = { onPinEntered(pin.value) }) {
            Text(text = "Submit PIN")
        }
    }
}
