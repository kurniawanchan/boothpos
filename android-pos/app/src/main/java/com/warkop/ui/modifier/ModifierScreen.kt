package com.warkop.ui.modifier

import androidx.compose.foundation.layout.Column
import androidx.compose.foundation.layout.fillMaxSize
import androidx.compose.material3.Button
import androidx.compose.material3.Text
import androidx.compose.runtime.Composable
import androidx.compose.ui.Modifier

@Composable
fun ModifierScreen(onModifierApplied: (String) -> Unit) {
    Column(modifier = Modifier.fillMaxSize()) {
        Text(text = "Modifier Screen (stub)")
        // Placeholder button to apply a dummy modifier.
        Button(onClick = { onModifierApplied("sample-modifier") }) {
            Text(text = "Apply Modifier")
        }
    }
}
