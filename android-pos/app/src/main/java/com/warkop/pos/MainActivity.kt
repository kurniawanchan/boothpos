package com.warkop.pos

import android.os.Bundle
import androidx.activity.ComponentActivity
import androidx.activity.compose.setContent
import androidx.compose.foundation.background
import androidx.compose.foundation.layout.*
import androidx.compose.material3.*
import androidx.compose.runtime.*
import androidx.compose.ui.Alignment
import androidx.compose.ui.Modifier
import androidx.compose.ui.graphics.Color
import androidx.compose.ui.text.font.FontWeight
import androidx.compose.ui.unit.dp
import androidx.compose.ui.unit.sp
import com.warkop.ui.login.LoginScreen
import com.warkop.ui.pin.PinScreen
import com.warkop.ui.menu.MenuScreen
import com.warkop.ui.orders.OrderListScreen
import com.warkop.ui.shift.ShiftSummaryScreen
import com.warkop.ui.status.ConnectionStatusBar

enum class Screen {
    LOGIN,
    PIN,
    MENU,
    ORDERS,
    SHIFT
}

class MainActivity : ComponentActivity() {
    override fun onCreate(savedInstanceState: Bundle?) {
        super.onCreate(savedInstanceState)
        setContent {
            MaterialTheme {
                PosApp()
            }
        }
    }
}

@Composable
fun PosApp() {
    var currentScreen by remember { mutableStateOf(Screen.MENU) }

    Column(modifier = Modifier.fillMaxSize().background(Color(0xFFF7F8FA))) {
        ConnectionStatusBar(isConnected = true)

        // Top Navigation Bar
        Row(
            modifier = Modifier
                .fillMaxWidth()
                .background(Color(0xFF1E293B))
                .padding(horizontal = 16.dp, vertical = 12.dp),
            verticalAlignment = Alignment.CenterVertically,
            horizontalArrangement = Arrangement.SpaceBetween
        ) {
            Text(
                text = "☕ Warkop WJM POS Tablet",
                color = Color.White,
                fontSize = 20.sp,
                fontWeight = FontWeight.Bold
            )
            Row(horizontalArrangement = Arrangement.spacedBy(8.dp)) {
                Button(
                    onClick = { currentScreen = Screen.MENU },
                    colors = ButtonDefaults.buttonColors(
                        containerColor = if (currentScreen == Screen.MENU) Color(0xFF3B82F6) else Color(0xFF334155)
                    )
                ) {
                    Text("Menu / Kasir")
                }
                Button(
                    onClick = { currentScreen = Screen.ORDERS },
                    colors = ButtonDefaults.buttonColors(
                        containerColor = if (currentScreen == Screen.ORDERS) Color(0xFF3B82F6) else Color(0xFF334155)
                    )
                ) {
                    Text("Daftar Order")
                }
                Button(
                    onClick = { currentScreen = Screen.SHIFT },
                    colors = ButtonDefaults.buttonColors(
                        containerColor = if (currentScreen == Screen.SHIFT) Color(0xFF3B82F6) else Color(0xFF334155)
                    )
                ) {
                    Text("Shift")
                }
                Button(
                    onClick = { currentScreen = Screen.PIN },
                    colors = ButtonDefaults.buttonColors(
                        containerColor = if (currentScreen == Screen.PIN) Color(0xFFEF4444) else Color(0xFF334155)
                    )
                ) {
                    Text("Kunci / PIN")
                }
            }
        }

        // Screen Content
        Box(modifier = Modifier.fillMaxSize().padding(16.dp)) {
            when (currentScreen) {
                Screen.MENU -> MenuScreen(onItemSelected = { /* Item selected */ })
                Screen.ORDERS -> OrderListScreen()
                Screen.SHIFT -> ShiftSummaryScreen()
                Screen.PIN -> PinScreen(onPinEntered = { currentScreen = Screen.MENU })
                Screen.LOGIN -> LoginScreen(onLoginSuccess = { currentScreen = Screen.MENU })
            }
        }
    }
}
