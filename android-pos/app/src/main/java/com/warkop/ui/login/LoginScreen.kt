package com.warkop.ui.login

import androidx.compose.foundation.layout.Column
import androidx.compose.foundation.layout.fillMaxSize
import androidx.compose.material3.Button
import androidx.compose.material3.Text
import androidx.compose.runtime.Composable
import androidx.compose.runtime.mutableStateOf
import androidx.compose.runtime.remember
import androidx.compose.ui.Modifier

@Composable
fun LoginScreen(onLoginSuccess: () -> Unit) {
    val username = remember { mutableStateOf("") }
    val password = remember { mutableStateOf("") }
    Column(modifier = Modifier.fillMaxSize()) {
        // Simplified UI placeholders
        Text(text = "Login Screen (stub)")
        // Normally you would add TextField for username/password
        Button(onClick = { onLoginSuccess() }) {
            Text(text = "Login")
        }
    }
}
