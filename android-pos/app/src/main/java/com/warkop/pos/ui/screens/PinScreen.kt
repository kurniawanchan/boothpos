package com.warkop.pos.ui.screens

import androidx.compose.foundation.BorderStroke
import androidx.compose.foundation.background
import androidx.compose.foundation.border
import androidx.compose.foundation.clickable
import androidx.compose.foundation.layout.*
import androidx.compose.foundation.shape.CircleShape
import androidx.compose.foundation.shape.RoundedCornerShape
import androidx.compose.material.icons.Icons
import androidx.compose.material.icons.filled.Schedule
import androidx.compose.material.icons.filled.Wifi
import androidx.compose.material.icons.filled.WifiOff
import androidx.compose.material3.*
import androidx.compose.runtime.*
import androidx.compose.ui.Alignment
import androidx.compose.ui.Modifier
import androidx.compose.ui.draw.clip
import androidx.compose.ui.text.font.FontWeight
import androidx.compose.ui.text.style.TextAlign
import androidx.compose.ui.unit.dp
import androidx.compose.ui.unit.sp
import com.warkop.pos.model.PosCatalog
import com.warkop.pos.model.StaffUser
import com.warkop.pos.state.Conn
import com.warkop.pos.state.PosStore
import com.warkop.pos.ui.Keypad
import com.warkop.pos.ui.Size
import com.warkop.pos.ui.windowSize
import java.text.SimpleDateFormat
import java.util.Date
import java.util.Locale

/** S02 — pilih staf lalu PIN 6 digit; submit otomatis pada digit ke-6 (desain 01-login-pin, 29 phone). */
@Composable
fun PinScreen(store: PosStore) {
    var selected by remember { mutableStateOf<StaffUser?>(PosCatalog.users.first()) }
    var pin by remember { mutableStateOf("") }
    var error by remember { mutableStateOf<String?>(null) }
    val compact = windowSize() == Size.COMPACT

    fun submit() {
        val u = selected ?: run { error = "Pilih nama Anda terlebih dahulu."; return }
        error = when (val r = store.submitPin(u, pin)) {
            PosStore.PinResult.Ok -> null
            is PosStore.PinResult.Wrong -> "PIN salah. Sisa ${r.left} percobaan."
            PosStore.PinResult.Locked -> "Login dikunci 15 menit karena PIN salah 5 kali."
        }
        pin = ""
    }

    val entry: @Composable (Modifier) -> Unit = { m ->
        Column(m, horizontalAlignment = Alignment.CenterHorizontally) {
            Text(if (compact) "Siapa yang bertugas?" else "Siapa yang bertugas?", fontSize = if (compact) 22.sp else 30.sp, modifier = Modifier.align(Alignment.Start))
            Spacer(Modifier.height(16.dp))
            Row(Modifier.fillMaxWidth(), horizontalArrangement = Arrangement.SpaceEvenly) {
                PosCatalog.users.forEach { u -> StaffChip(u, u == selected, compact, Modifier.weight(1f)) { selected = u; pin = ""; error = null } }
            }
            Spacer(Modifier.height(24.dp))
            Text(selected?.let { "PIN ${it.name}" } ?: "Pilih nama, lalu masukkan PIN", fontSize = 18.sp)
            Spacer(Modifier.height(16.dp))
            Row(horizontalArrangement = Arrangement.spacedBy(14.dp)) {
                repeat(6) { i -> PinDot(i < pin.length) }
            }
            Box(Modifier.height(36.dp), contentAlignment = Alignment.Center) {
                error?.let { Text(it, color = MaterialTheme.colorScheme.error, fontSize = 14.sp, textAlign = TextAlign.Center) }
            }
            Keypad(
                onDigit = { d -> if (pin.length < 6) { pin += d; error = null; if (pin.length == 6) submit() } },
                onBackspace = { pin = pin.dropLast(1) },
                modifier = Modifier.widthIn(max = 340.dp), keyHeight = if (compact) 56.dp else 72.dp,
            )
        }
    }

    if (compact) {
        Column(Modifier.fillMaxSize().background(MaterialTheme.colorScheme.background).padding(20.dp)) { entry(Modifier.fillMaxWidth()) }
    } else {
        Row(Modifier.fillMaxSize().background(MaterialTheme.colorScheme.background).padding(16.dp)) {
            BrandPanel(store, Modifier.weight(0.38f).fillMaxHeight())
            entry(Modifier.weight(0.62f).fillMaxHeight().padding(start = 40.dp, end = 24.dp, top = 24.dp))
        }
    }
}

@Composable
private fun StaffChip(u: StaffUser, selected: Boolean, compact: Boolean, modifier: Modifier, onClick: () -> Unit) {
    Column(modifier.clip(RoundedCornerShape(16.dp)).clickable(onClick = onClick).padding(vertical = 8.dp), horizontalAlignment = Alignment.CenterHorizontally) {
        Surface(
            Modifier.size(if (compact) 52.dp else 64.dp), shape = CircleShape,
            color = if (selected) MaterialTheme.colorScheme.primaryContainer else MaterialTheme.colorScheme.surfaceContainerLow,
            border = BorderStroke(1.dp, MaterialTheme.colorScheme.outlineVariant),
        ) { Box(contentAlignment = Alignment.Center) { Text(u.initials, fontSize = if (compact) 17.sp else 20.sp, fontWeight = FontWeight.Bold) } }
        Spacer(Modifier.height(6.dp))
        Text(u.name, fontWeight = FontWeight.SemiBold, maxLines = 1)
        Text(u.role, fontSize = if (compact) 11.sp else 12.sp, maxLines = 1, softWrap = false, overflow = androidx.compose.ui.text.style.TextOverflow.Ellipsis, color = MaterialTheme.colorScheme.onSurfaceVariant)
    }
}

@Composable
private fun PinDot(filled: Boolean) {
    Box(
        Modifier.size(16.dp).clip(CircleShape)
            .background(if (filled) MaterialTheme.colorScheme.onSurface else androidx.compose.ui.graphics.Color.Transparent)
            .then(if (filled) Modifier else Modifier.border(1.5.dp, MaterialTheme.colorScheme.outline, CircleShape)),
    )
}

@Composable
private fun BrandPanel(store: PosStore, modifier: Modifier) {
    val time = remember { SimpleDateFormat("HH:mm", Locale.US).format(Date()) }
    Surface(modifier, shape = RoundedCornerShape(28.dp), color = MaterialTheme.colorScheme.primaryContainer, contentColor = MaterialTheme.colorScheme.onPrimaryContainer) {
        Column(Modifier.padding(28.dp)) {
            Row(verticalAlignment = Alignment.CenterVertically) {
                Surface(Modifier.size(52.dp), shape = RoundedCornerShape(14.dp), color = MaterialTheme.colorScheme.primary) {
                    Box(contentAlignment = Alignment.Center) { Text("W", color = MaterialTheme.colorScheme.onPrimary, fontSize = 28.sp, fontWeight = FontWeight.ExtraBold) }
                }
                Spacer(Modifier.width(14.dp))
                Column { Text("Warkop WJM 3", fontSize = 22.sp, fontWeight = FontWeight.SemiBold); Text("Terminal T01 · Kab. Bogor", fontSize = 14.sp) }
            }
            Spacer(Modifier.weight(1f))
            Text(time, fontSize = 112.sp, fontWeight = FontWeight.Light, lineHeight = 112.sp)
            Text("Sabtu, 31 Oktober 2026", fontSize = 18.sp, fontWeight = FontWeight.Medium)
            Spacer(Modifier.weight(1f))
            Row(horizontalArrangement = Arrangement.spacedBy(10.dp)) {
                InfoChip(if (store.conn == Conn.ONLINE) Icons.Filled.Wifi else Icons.Filled.WifiOff, store.conn.label)
                InfoChip(Icons.Filled.Schedule, "Shift 1 · 07:00–15:00")
            }
        }
    }
}

@Composable
private fun InfoChip(icon: androidx.compose.ui.graphics.vector.ImageVector, text: String) {
    Surface(shape = RoundedCornerShape(12.dp), color = MaterialTheme.colorScheme.surfaceContainerLowest, contentColor = MaterialTheme.colorScheme.onSurface) {
        Row(Modifier.padding(horizontal = 14.dp, vertical = 10.dp), verticalAlignment = Alignment.CenterVertically) {
            Icon(icon, null, Modifier.size(18.dp)); Spacer(Modifier.width(6.dp)); Text(text, fontWeight = FontWeight.Medium, fontSize = 14.sp)
        }
    }
}
