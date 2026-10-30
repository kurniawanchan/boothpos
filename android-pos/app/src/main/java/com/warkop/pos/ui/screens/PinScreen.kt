package com.warkop.pos.ui.screens

import androidx.compose.foundation.BorderStroke
import androidx.compose.foundation.Image
import androidx.compose.foundation.background
import androidx.compose.foundation.border
import androidx.compose.foundation.layout.*
import androidx.compose.foundation.lazy.grid.GridCells
import androidx.compose.foundation.lazy.grid.LazyVerticalGrid
import androidx.compose.foundation.lazy.grid.items
import androidx.compose.foundation.rememberScrollState
import androidx.compose.foundation.shape.CircleShape
import androidx.compose.foundation.shape.RoundedCornerShape
import androidx.compose.foundation.verticalScroll
import androidx.compose.material.icons.Icons
import androidx.compose.material.icons.filled.TabletAndroid
import androidx.compose.material.icons.filled.Wifi
import androidx.compose.material.icons.filled.WifiOff
import androidx.compose.material3.*
import androidx.compose.runtime.*
import androidx.compose.ui.Alignment
import androidx.compose.ui.Modifier
import androidx.compose.ui.draw.alpha
import androidx.compose.ui.draw.clip
import androidx.compose.ui.graphics.Brush
import androidx.compose.ui.graphics.Color
import androidx.compose.ui.layout.ContentScale
import androidx.compose.ui.res.painterResource
import androidx.compose.ui.text.font.FontWeight
import androidx.compose.ui.text.style.TextAlign
import androidx.compose.ui.text.style.TextOverflow
import androidx.compose.ui.unit.dp
import androidx.compose.ui.unit.sp
import com.warkop.pos.R
import com.warkop.pos.model.StaffUser
import com.warkop.pos.model.SystemMode
import com.warkop.pos.model.Tone
import com.warkop.pos.state.Conn
import com.warkop.pos.state.Load
import com.warkop.pos.state.PosStore
import com.warkop.pos.ui.ErrorState
import com.warkop.pos.ui.Keypad
import com.warkop.pos.ui.NO_PIN_REASON
import com.warkop.pos.ui.PinLayout
import com.warkop.pos.ui.ProductPhoto
import com.warkop.pos.ui.Size
import com.warkop.pos.ui.StaffRow
import com.warkop.pos.ui.UserAvatar
import com.warkop.pos.ui.liveClockText
import com.warkop.pos.ui.pinLayoutFor
import com.warkop.pos.ui.selectedAfterRosterChange
import com.warkop.pos.ui.staffRows
import com.warkop.pos.ui.storeTitle
import com.warkop.pos.ui.windowSize
import java.text.SimpleDateFormat
import java.util.Date
import java.util.Locale

/**
 * S02 — pilih staf lalu PIN 6 digit; submit otomatis pada digit ke-6 (desain 01-login-pin, 29 phone). Staf dan PIN dari server.
 *
 * 085 / US2 — DUA kolom di layar ≥ 720 dp (tiga kolom 078 dihapus). Kolom kiri bertumpuk atas-bawah: (1) Info Outlet di atas foto
 * latar — logo, nama outlet, mode sistem, nama terminal, tanggal, jam berdetak, status online; (2) kartu pengguna seragam (avatar
 * di atas, nama + jabatan di bawah). Kolom kanan: PIN yang lebih besar dan tombol "Ganti outlet / terminal" (roda gigi dihapus).
 * Layar sempit menumpuk urutan yang sama. Setiap ORANG tampil tepat sekali; terpilih = cincin tebal, ukuran kartu tidak berubah.
 * Galat PIN hanya di bagian PIN. Alur masuk (roster tanpa token, PIN, kunci 5 menit) tidak berubah.
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

    // KOLOM PIN: hanya yang dibutuhkan untuk mengetik PIN. Galat tampil di sini; kartu terpilih tetap terlihat di kolom kiri.
    // 085 — lebih besar dari 078 (keypad 340 → 440 dp, tombol 72 → 88 dp, titik 16 → 22 dp) supaya mudah diketuk dan menonjol.
    val pinEntry: @Composable (Modifier) -> Unit = { m ->
        Column(m, horizontalAlignment = Alignment.CenterHorizontally) {
            Text(selected?.let { "PIN ${it.name}" } ?: "Pilih nama, lalu masukkan PIN", fontSize = 22.sp, fontWeight = FontWeight.Medium, textAlign = TextAlign.Center)
            Spacer(Modifier.height(20.dp))
            Row(horizontalArrangement = Arrangement.spacedBy(16.dp)) {
                repeat(6) { i -> PinDot(i < pin.length) }
            }
            Box(Modifier.height(40.dp), contentAlignment = Alignment.Center) {
                error?.let { Text(it, color = MaterialTheme.colorScheme.error, fontSize = 15.sp, textAlign = TextAlign.Center) }
            }
            Keypad(
                onDigit = { d -> if (!busy && staff.isNotEmpty() && pin.length < 6) { pin += d; error = null; if (pin.length == 6) submit() } },
                onBackspace = { if (!busy) pin = pin.dropLast(1) },
                modifier = Modifier.widthIn(max = 440.dp), keyHeight = if (compact) 64.dp else 88.dp,
            )
            // 073/085 — jalan keluar bila tablet salah outlet/terminal; tombol yang jelas, bukan teks atau ikon roda gigi.
            OutlinedButton(
                onClick = { store.goToSetup() },
                modifier = Modifier.padding(top = 20.dp).fillMaxWidth().widthIn(max = 440.dp).height(52.dp),
                shape = RoundedCornerShape(14.dp),
            ) {
                Icon(Icons.Filled.TabletAndroid, null, Modifier.size(20.dp))
                Spacer(Modifier.width(8.dp))
                Text("Ganti outlet / terminal", fontSize = 16.sp, fontWeight = FontWeight.Medium)
            }
        }
    }

    val bg = Modifier.fillMaxSize().background(MaterialTheme.colorScheme.background)
    when (layout) {
        PinLayout.TWO_COLUMN -> Row(bg.padding(16.dp), horizontalArrangement = Arrangement.spacedBy(24.dp)) {
            Column(Modifier.weight(0.56f).fillMaxHeight(), verticalArrangement = Arrangement.spacedBy(16.dp)) {
                OutletInfoPanel(store, Modifier.fillMaxWidth().weight(0.46f), clockSize = 56)
                StaffCardGrid(store, rows, pick, retry, Modifier.fillMaxWidth().weight(0.54f), lazy = true, columns = 0)
            }
            // 054 / US4 — layar lebar tidak boleh memotong keypad: kolom PIN menggulir bila tinggi layar kurang.
            pinEntry(Modifier.weight(0.44f).fillMaxHeight().padding(vertical = 8.dp).verticalScroll(rememberScrollState()))
        }
        // Layar sempit: urutan sama (Info Outlet, kartu, PIN) dalam satu gulir; kartu dua per baris (tanpa grid lazy di dalam gulir).
        PinLayout.STACKED -> Column(bg.padding(16.dp).verticalScroll(rememberScrollState())) {
            OutletInfoPanel(store, Modifier.fillMaxWidth().height(220.dp), clockSize = 40)
            Spacer(Modifier.height(16.dp))
            StaffCardGrid(store, rows, pick, retry, Modifier.fillMaxWidth(), lazy = false, columns = 2)
            Spacer(Modifier.height(24.dp))
            pinEntry(Modifier.fillMaxWidth())
        }
    }
}

/** Tinggi tetap kartu pengguna: semua kartu sama (FR-008) berapa pun panjang nama atau jabatan. */
private val STAFF_CARD_HEIGHT = 128.dp

/** Lebar minimum kartu pada grid lazy; `GridCells.Adaptive` membagi lebar kolom kiri sama rata. */
private val STAFF_CARD_MIN_WIDTH = 130.dp

/**
 * 085 — Info Outlet: foto latar (aset bawaan, bukan per outlet) + scrim gelap bergradien supaya teks putih terbaca di bagian
 * foto mana pun. Isi: logo + nama outlet, mode sistem, nama terminal, tanggal, jam berdetak ([liveClockText]) dan status online.
 * Mode tampil SELALU di sini (LIVE maupun DEMO), tidak seperti lencana header yang hanya menandai DEMO.
 */
@Composable
private fun OutletInfoPanel(store: PosStore, modifier: Modifier, clockSize: Int) {
    val time = liveClockText() // 084 — berdetak; dulu dihitung sekali dan membeku
    val name = storeTitle(store.branding?.storeName)
    val outletName = store.config?.outletName?.takeIf { it.isNotBlank() }
    val terminal = store.config?.deviceName?.takeIf { it.isNotBlank() } ?: "Terminal"
    val onPhoto = Color.White
    Box(modifier.clip(RoundedCornerShape(28.dp))) {
        // Dekoratif: informasi yang sama sudah tertulis di teks panel, jadi tanpa deskripsi untuk pembaca layar.
        Image(painterResource(R.drawable.outlet_background), contentDescription = null, Modifier.matchParentSize(), contentScale = ContentScale.Crop)
        Box(Modifier.matchParentSize().background(Brush.verticalGradient(listOf(Color.Black.copy(alpha = 0.40f), Color.Black.copy(alpha = 0.78f)))))
        Column(Modifier.fillMaxSize().padding(24.dp), verticalArrangement = Arrangement.SpaceBetween) {
            Row(verticalAlignment = Alignment.CenterVertically) {
                ProductPhoto(store.branding?.logoUrl, name, Tone.INK, Modifier.size(56.dp).clip(RoundedCornerShape(14.dp)), initialsSize = 24.sp)
                Spacer(Modifier.width(14.dp))
                Column(Modifier.weight(1f)) {
                    Text(name, color = onPhoto, fontSize = 22.sp, fontWeight = FontWeight.SemiBold, maxLines = 1, overflow = TextOverflow.Ellipsis)
                    if (outletName != null && outletName != name) {
                        Text(outletName, color = onPhoto.copy(alpha = 0.85f), fontSize = 15.sp, maxLines = 1, overflow = TextOverflow.Ellipsis)
                    }
                }
            }
            Column {
                Text(time, color = onPhoto, fontSize = clockSize.sp, fontWeight = FontWeight.Light, lineHeight = (clockSize + 4).sp)
                Text(
                    SimpleDateFormat("EEEE, d MMMM yyyy", Locale("id", "ID")).format(Date()),
                    color = onPhoto, fontSize = 16.sp, fontWeight = FontWeight.Medium,
                )
            }
            Row(horizontalArrangement = Arrangement.spacedBy(10.dp), verticalAlignment = Alignment.CenterVertically) {
                InfoChip(null, if (store.mode == SystemMode.DEMO) "Mode DEMO" else "Mode LIVE")
                InfoChip(null, terminal)
                InfoChip(if (store.conn == Conn.OFFLINE || store.conn == Conn.OFFLINE_LIMITED) Icons.Filled.WifiOff else Icons.Filled.Wifi, store.conn.label)
            }
        }
    }
}

@Composable
private fun InfoChip(icon: androidx.compose.ui.graphics.vector.ImageVector?, text: String) {
    // Permukaan terang di atas foto gelap: kontras terjaga apa pun foto dan temanya.
    Surface(shape = RoundedCornerShape(12.dp), color = MaterialTheme.colorScheme.surfaceContainerLowest, contentColor = MaterialTheme.colorScheme.onSurface) {
        Row(Modifier.padding(horizontal = 12.dp, vertical = 8.dp), verticalAlignment = Alignment.CenterVertically) {
            if (icon != null) { Icon(icon, null, Modifier.size(18.dp)); Spacer(Modifier.width(6.dp)) }
            Text(text, fontWeight = FontWeight.Medium, fontSize = 14.sp, maxLines = 1, overflow = TextOverflow.Ellipsis)
        }
    }
}

/**
 * 085 — kartu pengguna seragam. [lazy] true (kolom tinggi tetap) = grid yang menggulir sendiri tanpa menggeser judul, jumlah kolom
 * menyesuaikan lebar ([STAFF_CARD_MIN_WIDTH]); false (satu kolom bertumpuk di dalam gulir induk) = baris biasa sebanyak [columns]
 * kartu, sisa sel diisi ruang kosong supaya kartu terakhir tidak melebar. Keadaan memuat/galat/kosong ditangani di sini.
 */
@Composable
private fun StaffCardGrid(store: PosStore, rows: List<StaffRow>, onPick: (StaffUser) -> Unit, onRetry: () -> Unit, modifier: Modifier, lazy: Boolean, columns: Int) {
    Column(modifier) {
        Text("Siapa yang bertugas?", fontSize = 22.sp, fontWeight = FontWeight.Medium, modifier = Modifier.padding(bottom = 10.dp))
        when (val r = store.roster) {
            is Load.Loading, is Load.Idle -> Box(Modifier.fillMaxWidth().height(120.dp), contentAlignment = Alignment.Center) { CircularProgressIndicator() }
            is Load.Failed -> ErrorState(r.error.message, onRetry = onRetry, modifier = Modifier.fillMaxWidth())
            is Load.Ready -> {
                if (rows.none { it.selectable }) {
                    Text("Belum ada staf dengan PIN di outlet ini. Atur PIN dari akun masing-masing atau minta admin.", color = MaterialTheme.colorScheme.onSurfaceVariant)
                    Spacer(Modifier.height(12.dp))
                }
                if (lazy) {
                    LazyVerticalGrid(
                        columns = GridCells.Adaptive(STAFF_CARD_MIN_WIDTH), modifier = Modifier.weight(1f, fill = false),
                        horizontalArrangement = Arrangement.spacedBy(10.dp), verticalArrangement = Arrangement.spacedBy(10.dp),
                    ) {
                        items(rows, key = { it.user.id }) { row -> StaffCard(row, onPick, Modifier.fillMaxWidth()) }
                    }
                } else {
                    Column(verticalArrangement = Arrangement.spacedBy(10.dp)) {
                        rows.chunked(columns).forEach { chunk ->
                            Row(horizontalArrangement = Arrangement.spacedBy(10.dp)) {
                                chunk.forEach { row -> key(row.user.id) { StaffCard(row, onPick, Modifier.weight(1f)) } }
                                repeat(columns - chunk.size) { Spacer(Modifier.weight(1f)) }
                            }
                        }
                    }
                }
            }
        }
    }
}

/**
 * 085 — satu kartu pengguna: baris atas avatar, baris bawah nama + jabatan (masing-masing satu baris, berelipsis). Tinggi tetap
 * [STAFF_CARD_HEIGHT] dan avatar tetap, jadi tidak ada kartu yang berbeda ukuran. Terpilih = cincin tebal; tanpa PIN = redup, tidak
 * bisa diketuk, jabatan diganti [NO_PIN_REASON].
 */
@Composable
private fun StaffCard(row: StaffRow, onPick: (StaffUser) -> Unit, modifier: Modifier) {
    val u = row.user
    val ring = if (row.expanded) MaterialTheme.colorScheme.primary else MaterialTheme.colorScheme.outlineVariant
    Surface(
        onClick = { onPick(u) }, enabled = row.selectable, modifier = modifier.height(STAFF_CARD_HEIGHT).alpha(if (row.selectable) 1f else 0.5f),
        shape = RoundedCornerShape(20.dp), color = if (row.expanded) MaterialTheme.colorScheme.surfaceContainerLowest else MaterialTheme.colorScheme.surface,
        border = BorderStroke(if (row.expanded) 3.dp else 1.dp, ring),
    ) {
        Column(Modifier.fillMaxSize().padding(horizontal = 8.dp, vertical = 10.dp), horizontalAlignment = Alignment.CenterHorizontally, verticalArrangement = Arrangement.SpaceBetween) {
            UserAvatar(u, 56.dp, tone = if (row.expanded) Tone.TINT else Tone.NEUTRAL, textSize = 20f)
            Column(horizontalAlignment = Alignment.CenterHorizontally) {
                Text(u.name, fontSize = 15.sp, fontWeight = FontWeight.SemiBold, maxLines = 1, overflow = TextOverflow.Ellipsis, textAlign = TextAlign.Center)
                Text(if (row.selectable) u.role else NO_PIN_REASON, fontSize = 12.sp, maxLines = 1, overflow = TextOverflow.Ellipsis, textAlign = TextAlign.Center, color = MaterialTheme.colorScheme.onSurfaceVariant)
            }
        }
    }
}

@Composable
private fun PinDot(filled: Boolean) {
    Box(
        Modifier.size(22.dp).clip(CircleShape)
            .background(if (filled) MaterialTheme.colorScheme.onSurface else Color.Transparent)
            .then(if (filled) Modifier else Modifier.border(2.dp, MaterialTheme.colorScheme.outline, CircleShape)),
    )
}
