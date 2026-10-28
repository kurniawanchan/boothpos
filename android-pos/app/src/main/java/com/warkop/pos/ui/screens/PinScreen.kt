package com.warkop.pos.ui.screens

import androidx.compose.foundation.BorderStroke
import androidx.compose.foundation.background
import androidx.compose.foundation.border
import androidx.compose.foundation.clickable
import androidx.compose.foundation.layout.*
import androidx.compose.foundation.lazy.items
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
import com.warkop.pos.ui.NO_PIN_REASON
import com.warkop.pos.ui.PinLayout
import com.warkop.pos.ui.StaffRow
import com.warkop.pos.ui.StoreMark
import com.warkop.pos.ui.pinLayoutFor
import com.warkop.pos.ui.selectedAfterRosterChange
import com.warkop.pos.ui.staffRows
import com.warkop.pos.ui.UserAvatar
import com.warkop.pos.model.Tone
import com.warkop.pos.ui.Size
import com.warkop.pos.ui.windowSize
import java.text.SimpleDateFormat
import java.util.Date
import java.util.Locale

/**
 * S02 — pilih staf lalu PIN 6 digit; submit otomatis pada digit ke-6 (desain 01-login-pin, 29 phone). Staf dan PIN dari server.
 *
 * 078 / US2 — TIGA kolom di layar lebar (≥ 960 dp): (1) panel toko seperti sebelum 077 — logo + nama, outlet · terminal, jam besar,
 * tanggal, status koneksi, lencana DEMO; (2) daftar staf dengan detail pengguna terpilih; (3) masukan PIN. Setiap ORANG tampil
 * tepat sekali: baris terpilih DIPERBESAR di tempatnya, tidak ada blok "pengguna terpilih" terpisah (penyebab nama ganda di 077).
 * Judul "Siapa yang bertugas?" tetap di atas dan hanya daftar yang menggulir, jadi tidak ada judul/avatar yang terpotong.
 * 720–959 dp: dua kolom (toko + staf | PIN); di bawahnya satu kolom bertumpuk dengan urutan sama. Galat PIN hanya di bagian PIN.
 */
@Composable
fun PinScreen(store: PosStore) {
    val widthDp = androidx.compose.ui.platform.LocalConfiguration.current.screenWidthDp.toFloat()
    val layout = pinLayoutFor(widthDp)
    val compact = windowSize() == Size.COMPACT
    // 073 / FR-011 — staf tanpa PIN tetap tampil (redup, tidak bisa dipilih) supaya jelas kenapa tidak bisa masuk, bukan hilang.
    val everyone = (store.roster as? Load.Ready)?.value.orEmpty()
    val staff = everyone.filter { it.hasPin }
    var selected by remember { mutableStateOf<StaffUser?>(null) }
    var pin by remember { mutableStateOf("") }
    var error by remember { mutableStateOf<String?>(null) }
    var busy by remember { mutableStateOf(false) }

    // Pilihan awal = staf pertama yang bisa masuk; dijaga (dengan data terbaru, mis. foto) bila daftar dimuat ulang.
    LaunchedEffect(staff, store.roster) { if (store.roster is Load.Ready) selected = selectedAfterRosterChange(selected, staff) }

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

    val rows = staffRows(everyone, selected?.id)
    val pick: (StaffUser) -> Unit = { u -> if (u.hasPin) { selected = u; pin = ""; error = null } }
    val retry = { store.loadRoster(); Unit }

    // KOLOM PIN: hanya yang dibutuhkan untuk mengetik PIN. Galat tampil di sini; baris terpilih tetap terlihat di kolom staf.
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

    val bg = Modifier.fillMaxSize().background(MaterialTheme.colorScheme.background)
    when (layout) {
        PinLayout.THREE_COLUMN -> Row(bg.padding(16.dp), horizontalArrangement = Arrangement.spacedBy(16.dp)) {
            BrandPanel(store, Modifier.weight(0.28f).fillMaxHeight(), bigClock = true, content = null)
            StaffColumn(store, rows, pick, retry, Modifier.weight(0.32f).fillMaxHeight().padding(top = 8.dp), lazy = true)
            // 054 / US4 — layar lebar tidak boleh memotong keypad: kolom PIN menggulir bila tinggi layar kurang.
            pinEntry(Modifier.weight(0.40f).fillMaxHeight().padding(horizontal = 16.dp, vertical = 16.dp).verticalScroll(rememberScrollState()))
        }
        PinLayout.TWO_COLUMN -> Row(bg.padding(16.dp)) {
            BrandPanel(store, Modifier.weight(0.46f).fillMaxHeight(), bigClock = false) { m ->
                StaffColumn(store, rows, pick, retry, m, lazy = true)
            }
            pinEntry(Modifier.weight(0.54f).fillMaxHeight().padding(start = 32.dp, end = 24.dp, top = 24.dp).verticalScroll(rememberScrollState()))
        }
        // 077 — layar sempit tidak punya panel merek: logo toko + lencana mode tetap di atas.
        PinLayout.STACKED -> Column(bg.padding(20.dp).verticalScroll(rememberScrollState())) {
            StoreMark(store, logoSize = 40.dp)
            Text(outletLine(store), Modifier.padding(top = 4.dp, bottom = 16.dp), fontSize = 13.sp, color = MaterialTheme.colorScheme.onSurfaceVariant)
            StaffColumn(store, rows, pick, retry, Modifier.fillMaxWidth(), lazy = false)
            Spacer(Modifier.height(24.dp))
            pinEntry(Modifier.fillMaxWidth())
        }
    }
}

/** "Outlet · Terminal" dari konfigurasi tablet; "Terminal" bila nama terminal belum diketahui. */
private fun outletLine(store: PosStore): String =
    listOfNotNull(store.config?.outletName?.takeIf { it.isNotBlank() }, store.config?.deviceName?.takeIf { it.isNotBlank() } ?: "Terminal").joinToString(" · ")

/**
 * 078 — kolom staf: judul TETAP + satu baris per orang. [lazy] true (kolom tinggi tetap) = daftar menggulir sendiri tanpa menggeser
 * judul; false (satu kolom bertumpuk di dalam gulir induk) = baris biasa. Keadaan memuat/galat/kosong ditangani di sini.
 */
@Composable
private fun StaffColumn(store: PosStore, rows: List<StaffRow>, onPick: (StaffUser) -> Unit, onRetry: () -> Unit, modifier: Modifier, lazy: Boolean) {
    Column(modifier) {
        Text("Siapa yang bertugas?", fontSize = 26.sp, modifier = Modifier.padding(bottom = 14.dp))
        when (val r = store.roster) {
            is Load.Loading, is Load.Idle -> Box(Modifier.fillMaxWidth().height(120.dp), contentAlignment = Alignment.Center) { CircularProgressIndicator() }
            is Load.Failed -> ErrorState(r.error.message, onRetry = onRetry, modifier = Modifier.fillMaxWidth())
            is Load.Ready -> {
                if (rows.none { it.selectable }) {
                    Text("Belum ada staf dengan PIN di outlet ini. Atur PIN dari akun masing-masing atau minta admin.", color = MaterialTheme.colorScheme.onSurfaceVariant)
                    Spacer(Modifier.height(12.dp))
                }
                if (lazy) {
                    androidx.compose.foundation.lazy.LazyColumn(Modifier.weight(1f, fill = false), verticalArrangement = Arrangement.spacedBy(8.dp)) {
                        items(rows, key = { it.user.id }) { row -> StaffRowItem(row, onPick) }
                    }
                } else {
                    Column(verticalArrangement = Arrangement.spacedBy(8.dp)) { rows.forEach { row -> key(row.user.id) { StaffRowItem(row, onPick) } } }
                }
            }
        }
    }
}

/**
 * 078 — satu staf. Terpilih = baris DIPERBESAR (foto 72 dp, nama 20 sp, cincin tebal); lainnya ringkas (48 dp). Tanpa PIN: redup,
 * tidak bisa diketuk, peran diganti [NO_PIN_REASON]. Wadah memakai warna permukaan supaya terbaca di panel toko maupun di latar.
 */
@Composable
private fun StaffRowItem(row: StaffRow, onPick: (StaffUser) -> Unit) {
    val u = row.user
    val ring = if (row.expanded) MaterialTheme.colorScheme.primary else MaterialTheme.colorScheme.outlineVariant
    Surface(
        onClick = { onPick(u) }, enabled = row.selectable, modifier = Modifier.fillMaxWidth().alpha(if (row.selectable) 1f else 0.5f),
        shape = RoundedCornerShape(20.dp), color = if (row.expanded) MaterialTheme.colorScheme.surfaceContainerLowest else androidx.compose.ui.graphics.Color.Transparent,
        border = BorderStroke(if (row.expanded) 2.dp else 1.dp, ring),
    ) {
        Row(Modifier.padding(if (row.expanded) 16.dp else 10.dp), verticalAlignment = Alignment.CenterVertically) {
            UserAvatar(u, if (row.expanded) 72.dp else 48.dp, tone = if (row.expanded) Tone.TINT else Tone.NEUTRAL, textSize = if (row.expanded) 24f else 17f)
            Spacer(Modifier.width(if (row.expanded) 16.dp else 12.dp))
            Column(Modifier.weight(1f)) {
                Text(u.name, fontSize = if (row.expanded) 20.sp else 16.sp, fontWeight = FontWeight.SemiBold, maxLines = 1, overflow = androidx.compose.ui.text.style.TextOverflow.Ellipsis)
                Text(if (row.selectable) u.role else NO_PIN_REASON, fontSize = 13.sp, maxLines = 1, overflow = androidx.compose.ui.text.style.TextOverflow.Ellipsis, color = MaterialTheme.colorScheme.onSurfaceVariant)
            }
        }
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

/**
 * Panel toko (kolom 1). [bigClock] true = jam 72 sp seperti sebelum 077 (tiga kolom, panel hanya berisi identitas); false = jam 40 sp
 * karena panel juga memuat [content] (daftar staf pada dua kolom). Logo toko dari server (inisial bila belum ada) + lencana DEMO.
 */
@Composable
private fun BrandPanel(store: PosStore, modifier: Modifier, bigClock: Boolean, content: (@Composable (Modifier) -> Unit)?) {
    val time = remember { SimpleDateFormat("HH:mm", Locale.US).format(Date()) }
    Surface(modifier, shape = RoundedCornerShape(28.dp), color = MaterialTheme.colorScheme.primaryContainer, contentColor = MaterialTheme.colorScheme.onPrimaryContainer) {
        Column(Modifier.padding(28.dp)) {
            Row(verticalAlignment = Alignment.CenterVertically) {
                Column(Modifier.weight(1f)) {
                    StoreMark(store, logoSize = 52.dp, maxTextWidth = 260.dp)
                    Text(outletLine(store), Modifier.padding(top = 6.dp), fontSize = 14.sp)
                }
                IconButton(onClick = { store.goToSetup() }) {
                    Icon(imageVector = Icons.Filled.Settings, contentDescription = "Ganti outlet / terminal", tint = MaterialTheme.colorScheme.onPrimaryContainer)
                }
            }
            if (content != null) {
                Spacer(Modifier.height(24.dp))
                content(Modifier.weight(1f).fillMaxWidth())
                Spacer(Modifier.height(16.dp))
            } else {
                Spacer(Modifier.weight(1f))
            }
            Text(time, fontSize = if (bigClock) 72.sp else 40.sp, fontWeight = FontWeight.Light, lineHeight = if (bigClock) 76.sp else 44.sp)
            Text(java.text.SimpleDateFormat("EEEE, d MMMM yyyy", java.util.Locale("id", "ID")).format(java.util.Date()), fontSize = if (bigClock) 18.sp else 15.sp, fontWeight = FontWeight.Medium)
            if (content == null) Spacer(Modifier.weight(1f))
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
