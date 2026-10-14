package com.warkop.ui.menu

import androidx.compose.foundation.layout.Column
import androidx.compose.foundation.layout.fillMaxSize
import androidx.compose.material3.Button
import androidx.compose.material3.Text
import androidx.compose.runtime.Composable
import androidx.compose.ui.Modifier

@Composable
fun MenuScreen(onItemSelected: (String) -> Unit) {
    Column(modifier = Modifier.fillMaxSize()) {
        Text(text = "Menu Screen (stub)")
        // Performance note: In a production app with dozens/hundreds of menu items,
        // use LazyColumn or LazyVerticalGrid with keys to ensure smooth scrolling and avoid unnecessary recompositions.
        Button(onClick = { onItemSelected("sample-item-id") }) {
            Text(text = "Select Sample Item")
        }
    }
}
