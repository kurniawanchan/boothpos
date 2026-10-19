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
import androidx.compose.material.icons.filled.Settings
import androidx.compose.material.icons.filled.Wifi
import androidx.compose.material.icons.filled.WifiOff
import androidx.compose.material3.*
import androidx.compose.runtime.*
import androidx.compose.ui.Alignment
import androidx.compose.ui.Modifier
import androidx.compose.ui.draw.clip
import androidx.compose.ui.platform.LocalDensity
import androidx.compose.ui.text.TextStyle
import androidx.compose.ui.text.font.FontWeight
import androidx.compose.ui.text.rememberTextMeasurer
import androidx.compose.ui.text.style.TextAlign
import androidx.compose.ui.unit.TextUnit
import androidx.compose.ui.unit.dp
import androidx.compose.ui.unit.sp
import com.warkop.pos.model.StaffUser
import com.warkop.pos.state.Conn
import com.warkop.pos.state.Load
import com.warkop.pos.state.PosStore
import com.warkop.pos.ui.ErrorState
import com.warkop.pos.ui.chooseFontSize
import androidx.compose.foundation.rememberScrollState
import androidx.compose.foundation.verticalScroll
import com.warkop.pos.ui.Keypad
import com.warkop.pos.ui.Size
import com.warkop.pos.ui.windowSize
import java.text.SimpleDateFormat
import java.util.Date
import java.util.Locale

/** S02 — pilih staf lalu PIN 6 digit; submit otomatis pada digit ke-6 (desain 01-login-pin, 29 phone). Staf dan PIN dari server. */
@Composable
fun PinScreen(store: PosStore) {
    val compact = windowSize() == Size.COMPACT
    val staff = (store.roster as? Load.Ready)?.value.orEmpty().filter { it.hasPin }
    var selected by remember { mutableStateOf<StaffUser?>(null) }
    var pin by remember { mutableStateOf("") }
    var error by remember { mutableStateOf<String?>(null) }
    var busy by remember { mutableStateOf(false) }

    // Pilihan awal = staf pertama yang bisa masuk; dijaga bila daftar berubah (mis. setelah dimuat ulang).
    LaunchedEffect(staff, store.roster) { if (store.roster is Load.Ready && (selected == null || staff.none { it.id == selected?.id })) selected = staff.firstOrNull() }

    // 054 / US2 — status koneksi di layar ini diperbarui hanya selama layar PIN tampil; timer berhenti saat keluar.
    DisposableEffect(Unit) {
        store.startPinProbe()
        onDispose { store.stopPinProbe() }
    }

    fun submit() {
        val u = selected ?: run { error = "Pilih nama Anda terlebih dahulu."; return }
        busy = true
        store.loginPin(u, pin) { msg -> error = msg; pin = ""; busy = false }
    }

    val entry: @Composable (Modifier) -> Unit = { m ->
        Column(m, horizontalAlignment = Alignment.CenterHorizontally) {
            Text("Siapa yang bertugas?", fontSize = if (compact) 22.sp else 30.sp, modifier = Modifier.align(Alignment.Start))
            Spacer(Modifier.height(16.dp))
            when (val r = store.roster) {
                is Load.Loading, is Load.Idle -> Box(Modifier.fillMaxWidth().height(120.dp), contentAlignment = Alignment.Center) { CircularProgressIndicator() }
                is Load.Failed -> ErrorState(r.error.message, onRetry = { store.loadRoster() }, modifier = Modifier.fillMaxWidth())
                is Load.Ready -> if (staff.isEmpty()) {
                    Text("Belum ada staf dengan PIN di outlet ini. Atur PIN dari akun masing-masing atau minta admin.", color = MaterialTheme.colorScheme.onSurfaceVariant)
                } else Row(Modifier.fillMaxWidth(), horizontalArrangement = Arrangement.SpaceEvenly) {
                    staff.forEach { u -> StaffChip(u, u.id == selected?.id, compact, Modifier.weight(1f)) { selected = u; pin = ""; error = null } }
                }
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
                onDigit = { d -> if (!busy && staff.isNotEmpty() && pin.length < 6) { pin += d; error = null; if (pin.length == 6) submit() } },
                onBackspace = { if (!busy) pin = pin.dropLast(1) },
                modifier = Modifier.widthIn(max = 340.dp), keyHeight = if (compact) 56.dp else 72.dp,
            )
        }
    }

    if (compact) {
        Column(Modifier.fillMaxSize().background(MaterialTheme.colorScheme.background).padding(20.dp).verticalScroll(rememberScrollState())) { entry(Modifier.fillMaxWidth()) }
    } else {
        Row(Modifier.fillMaxSize().background(MaterialTheme.colorScheme.background).padding(16.dp)) {
            BrandPanel(store, Modifier.weight(0.38f).fillMaxHeight())
            // 054 / US4 — layar lebar (tablet landscape) dulu tidak bisa digulir: keypad terdorong keluar saat banner tampil.
            entry(Modifier.weight(0.62f).fillMaxHeight().padding(start = 40.dp, end = 24.dp, top = 24.dp).verticalScroll(rememberScrollState()))
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
                    Box(contentAlignment = Alignment.Center) { Text("W", color = MaterialTheme.colorScheme.onPrimary, fontSize = 28.sp, fontWeight = FontWeight.ExtraBold) } }
                Spacer(Modifier.width(14.dp))
                Column(Modifier.weight(1f)) { Text(store.config?.outletName ?: store.serverUrl()?.let { "Outlet" } ?: "Warkop WJM", fontSize = 22.sp, fontWeight = FontWeight.SemiBold); Text("Terminal ${store.config?.deviceCode ?: ""}", fontSize = 14.sp) }
                IconButton(onClick = { store.goToSetup() }) {
                    Icon(imageVector = Icons.Filled.Settings, contentDescription = "Atur terminal", tint = MaterialTheme.colorScheme.onPrimaryContainer)
                }
            }
            Spacer(Modifier.weight(1f))
            Text(time, fontSize = 112.sp, fontWeight = FontWeight.Light, lineHeight = 112.sp)
            Text(java.text.SimpleDateFormat("EEEE, d MMMM yyyy", java.util.Locale("id", "ID")).format(java.util.Date()), fontSize = 18.sp, fontWeight = FontWeight.Medium)
            Spacer(Modifier.weight(1f))
            OutletStatusChips(store.conn, store.config?.let { "${it.outletName} · ${it.deviceCode}" } ?: "Terminal")
        }
    }
}

@Composable
private fun InfoChip(icon: androidx.compose.ui.graphics.vector.ImageVector, text: String, fontSize: TextUnit = 14.sp) {
    Surface(shape = RoundedCornerShape(12.dp), color = MaterialTheme.colorScheme.surfaceContainerLowest, contentColor = MaterialTheme.colorScheme.onSurface) {
        Row(Modifier.padding(horizontal = 14.dp, vertical = 10.dp), verticalAlignment = Alignment.CenterVertically) {
            // 054 / FR-001 — satu baris, tanpa elipsis: nama outlet dipastikan muat oleh OutletStatusChips.
            Icon(icon, null, Modifier.size(18.dp)); Spacer(Modifier.width(6.dp)); Text(text, fontWeight = FontWeight.Medium, fontSize = fontSize, maxLines = 1, softWrap = false)
        }
    }
}

/** Lebar dalam chip di luar teks: padding 14+14, ikon 18, jarak 6 (sama dengan [InfoChip]). */
private val CHIP_INSET = 52.dp
private val CHIP_GAP = 10.dp
/** Ukuran huruf nama outlet, terbesar dulu. Di bawah 12sp terlalu kecil dibaca di kasir. */
private val OUTLET_FONT_SIZES = listOf(14f, 13f, 12f)

/**
 * 054 / US1 — chip status koneksi dan chip outlet · terminal. Nama outlet dibuat muat: pertama mengecil sampai
 * [OUTLET_FONT_SIZES]; bila tetap tidak muat di samping chip koneksi, chip outlet pindah ke baris penuh di atasnya.
 * Keputusan tata letak hanya dari lebar panel (tidak dari ukuran chip), jadi tidak ada loop umpan balik.
 */
@Composable
private fun OutletStatusChips(conn: Conn, outletText: String) {
    BoxWithConstraints(Modifier.fillMaxWidth()) {
        val measurer = rememberTextMeasurer()
        val density = LocalDensity.current
        val panelPx = with(density) { maxWidth.roundToPx() }
        val gapPx = with(density) { CHIP_GAP.roundToPx() }
        val insetPx = with(density) { CHIP_INSET.roundToPx() }
        fun textWidth(text: String, size: Float): Int =
            measurer.measure(text, TextStyle(fontSize = size.sp, fontWeight = FontWeight.Medium)).size.width
        fun outletSize(available: Int): Float? = chooseFontSize({ textWidth(outletText, it) }, available - insetPx, OUTLET_FONT_SIZES)

        val connWidth = textWidth(conn.label, 14f) + insetPx
        val connIcon = if (conn == Conn.OFFLINE || conn == Conn.OFFLINE_LIMITED) Icons.Filled.WifiOff else Icons.Filled.Wifi
        val sideBySide = outletSize(panelPx - connWidth - gapPx)
        if (sideBySide != null) {
            Row(horizontalArrangement = Arrangement.spacedBy(CHIP_GAP)) {
                InfoChip(connIcon, conn.label)
                InfoChip(Icons.Filled.Schedule, outletText, fontSize = sideBySide.sp)
            }
        } else {
            Column(verticalArrangement = Arrangement.spacedBy(CHIP_GAP)) {
                InfoChip(Icons.Filled.Schedule, outletText, fontSize = (outletSize(panelPx) ?: OUTLET_FONT_SIZES.last()).sp)
                InfoChip(connIcon, conn.label)
            }
        }
    }
}
