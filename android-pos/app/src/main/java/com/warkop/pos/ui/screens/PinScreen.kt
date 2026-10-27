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
import androidx.compose.ui.draw.alpha
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
import com.warkop.pos.ui.PinLayout
import com.warkop.pos.ui.StoreMark
import com.warkop.pos.ui.pinLayoutFor
import com.warkop.pos.ui.UserAvatar
import com.warkop.pos.model.Tone
import com.warkop.pos.ui.Size
import com.warkop.pos.ui.windowSize
import java.text.SimpleDateFormat
import java.util.Date
import java.util.Locale

/**
 * S02 — pilih staf lalu PIN 6 digit; submit otomatis pada digit ke-6 (desain 01-login-pin, 29 phone). Staf dan PIN dari server.
 * 077 / US3 — dua kolom di layar lebar: KIRI informasi pengguna (logo toko, outlet · terminal, pilih staf, pengguna terpilih),
 * KANAN masukan PIN (titik, galat, keypad). Layar sempit menumpuknya dalam urutan yang sama.
 */
@OptIn(androidx.compose.foundation.layout.ExperimentalLayoutApi::class)
@Composable
fun PinScreen(store: PosStore) {
    val widthDp = androidx.compose.ui.platform.LocalConfiguration.current.screenWidthDp.toFloat()
    val twoColumn = pinLayoutFor(widthDp) == PinLayout.TWO_COLUMN
    val compact = windowSize() == Size.COMPACT
    // 073 / FR-011 — staf tanpa PIN tetap tampil (redup, tidak bisa dipilih) supaya jelas kenapa tidak bisa masuk, bukan hilang.
    val everyone = (store.roster as? Load.Ready)?.value.orEmpty()
    val staff = everyone.filter { it.hasPin }
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

    // KOLOM INFORMASI PENGGUNA: siapa yang bertugas dan siapa yang sedang dipilih.
    val userInfo: @Composable (Modifier) -> Unit = { m ->
        Column(m, horizontalAlignment = Alignment.Start) {
            Text("Siapa yang bertugas?", fontSize = if (compact) 22.sp else 28.sp)
            Spacer(Modifier.height(14.dp))
            when (val r = store.roster) {
                is Load.Loading, is Load.Idle -> Box(Modifier.fillMaxWidth().height(120.dp), contentAlignment = Alignment.Center) { CircularProgressIndicator() }
                is Load.Failed -> ErrorState(r.error.message, onRetry = { store.loadRoster() }, modifier = Modifier.fillMaxWidth())
                is Load.Ready -> {
                    if (staff.isEmpty()) {
                        Text("Belum ada staf dengan PIN di outlet ini. Atur PIN dari akun masing-masing atau minta admin.", color = MaterialTheme.colorScheme.onSurfaceVariant)
                        Spacer(Modifier.height(12.dp))
                    }
                    FlowRow(Modifier.fillMaxWidth(), horizontalArrangement = Arrangement.spacedBy(8.dp), verticalArrangement = Arrangement.spacedBy(4.dp)) {
                        everyone.forEach { u -> StaffChip(u, u.id == selected?.id, compact, Modifier.widthIn(min = 84.dp, max = 112.dp)) { if (u.hasPin) { selected = u; pin = ""; error = null } } }
                    }
                }
            }
            selected?.let { u ->
                Spacer(Modifier.height(20.dp))
                SelectedUser(u)
            }
        }
    }

    // KOLOM MASUKAN PIN: hanya yang dibutuhkan untuk mengetik PIN; galat tampil di sini, pengguna terpilih tetap terlihat di kolom lain.
    val pinEntry: @Composable (Modifier) -> Unit = { m ->
        Column(m, horizontalAlignment = Alignment.CenterHorizontally) {
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
            // 073 — jalan keluar bila tablet salah outlet/terminal.
            TextButton({ store.goToSetup() }, Modifier.padding(top = 8.dp)) { Text("Ganti outlet / terminal") }
        }
    }

    if (!twoColumn) {
        Column(Modifier.fillMaxSize().background(MaterialTheme.colorScheme.background).padding(20.dp).verticalScroll(rememberScrollState())) {
            // 077 — layar sempit tidak punya panel merek: logo toko + lencana mode tetap di atas.
            StoreMark(store, logoSize = 40.dp)
            Text(listOfNotNull(store.config?.outletName?.takeIf { it.isNotBlank() }, store.config?.deviceName?.takeIf { it.isNotBlank() } ?: "Terminal").joinToString(" · "), Modifier.padding(top = 4.dp, bottom = 16.dp), fontSize = 13.sp, color = MaterialTheme.colorScheme.onSurfaceVariant)
            userInfo(Modifier.fillMaxWidth())
            Spacer(Modifier.height(24.dp))
            pinEntry(Modifier.fillMaxWidth())
        }
    } else {
        Row(Modifier.fillMaxSize().background(MaterialTheme.colorScheme.background).padding(16.dp)) {
            BrandPanel(store, Modifier.weight(0.46f).fillMaxHeight(), userInfo)
            // 054 / US4 — layar lebar (tablet landscape) dulu tidak bisa digulir: keypad terdorong keluar saat banner tampil.
            pinEntry(Modifier.weight(0.54f).fillMaxHeight().padding(start = 32.dp, end = 24.dp, top = 24.dp).verticalScroll(rememberScrollState()))
        }
    }
}

/** 077 / US3 — pengguna terpilih: avatar besar, nama, peran. Tetap tampil saat PIN salah/terkunci (galat ada di kolom PIN). */
@Composable
private fun SelectedUser(u: StaffUser) {
    Row(verticalAlignment = Alignment.CenterVertically) {
        UserAvatar(u, 72.dp, tone = Tone.TINT)
        Spacer(Modifier.width(16.dp))
        Column {
            Text(u.name, fontSize = 22.sp, fontWeight = FontWeight.SemiBold, maxLines = 1, overflow = androidx.compose.ui.text.style.TextOverflow.Ellipsis)
            Text(u.role, fontSize = 14.sp, color = MaterialTheme.colorScheme.onPrimaryContainer.copy(alpha = 0.75f), maxLines = 1)
        }
    }
}

@Composable
private fun StaffChip(u: StaffUser, selected: Boolean, compact: Boolean, modifier: Modifier, onClick: () -> Unit) {
    // 073 — tanpa PIN: redup, tidak bisa diklik, dan baris peran diganti alasannya.
    val usable = u.hasPin
    Column(modifier.alpha(if (usable) 1f else 0.5f).clip(RoundedCornerShape(16.dp)).clickable(enabled = usable, onClick = onClick).padding(vertical = 8.dp), horizontalAlignment = Alignment.CenterHorizontally) {
        // 077 / US7 — foto pengguna dari server; inisial bila tidak ada. Cincin menandai pilihan.
        UserAvatar(
            u, if (compact) 52.dp else 64.dp, Modifier.border(if (selected) 3.dp else 1.dp, if (selected) MaterialTheme.colorScheme.primary else MaterialTheme.colorScheme.outlineVariant, CircleShape),
            tone = if (selected) Tone.TINT else Tone.NEUTRAL, textSize = if (compact) 17f else 20f,
        )
        Spacer(Modifier.height(6.dp))
        Text(u.name, fontWeight = FontWeight.SemiBold, maxLines = 1)
        Text(if (usable) u.role else "Belum ada PIN", fontSize = if (compact) 11.sp else 12.sp, maxLines = 1, softWrap = false, overflow = androidx.compose.ui.text.style.TextOverflow.Ellipsis, color = MaterialTheme.colorScheme.onSurfaceVariant)
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
private fun BrandPanel(store: PosStore, modifier: Modifier, content: @Composable (Modifier) -> Unit) {
    val time = remember { SimpleDateFormat("HH:mm", Locale.US).format(Date()) }
    Surface(modifier, shape = RoundedCornerShape(28.dp), color = MaterialTheme.colorScheme.primaryContainer, contentColor = MaterialTheme.colorScheme.onPrimaryContainer) {
        Column(Modifier.padding(28.dp)) {
            Row(verticalAlignment = Alignment.CenterVertically) {
                // 077 — logo toko dari server (inisial bila belum ada) menggantikan huruf "W" tetap; outlet dan terminal di bawahnya.
                Column(Modifier.weight(1f)) {
                    StoreMark(store, logoSize = 52.dp, maxTextWidth = 260.dp)
                    Text(listOfNotNull(store.config?.outletName?.takeIf { it.isNotBlank() }, store.config?.deviceName?.takeIf { it.isNotBlank() } ?: "Terminal").joinToString(" · "), Modifier.padding(top = 6.dp), fontSize = 14.sp)
                }
                IconButton(onClick = { store.goToSetup() }) {
                    Icon(imageVector = Icons.Filled.Settings, contentDescription = "Ganti outlet / terminal", tint = MaterialTheme.colorScheme.onPrimaryContainer)
                }
            }
            Spacer(Modifier.height(24.dp))
            content(Modifier.weight(1f).fillMaxWidth().verticalScroll(rememberScrollState()))
            Spacer(Modifier.height(16.dp))
            Text(time, fontSize = 40.sp, fontWeight = FontWeight.Light, lineHeight = 44.sp)
            Text(java.text.SimpleDateFormat("EEEE, d MMMM yyyy", java.util.Locale("id", "ID")).format(java.util.Date()), fontSize = 15.sp, fontWeight = FontWeight.Medium)
            Spacer(Modifier.height(12.dp))
            OutletStatusChips(store.conn, store.config?.let { c -> listOf(c.outletName, c.deviceName).filter { it.isNotBlank() }.joinToString(" · ") } ?: "Terminal")
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
