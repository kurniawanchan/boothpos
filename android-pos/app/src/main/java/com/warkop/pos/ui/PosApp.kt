package com.warkop.pos.ui

import androidx.compose.foundation.layout.*
import androidx.compose.foundation.shape.RoundedCornerShape
import androidx.compose.material3.*
import androidx.compose.runtime.*
import androidx.compose.ui.Alignment
import androidx.compose.ui.Modifier
import androidx.compose.material.icons.Icons
import androidx.compose.material.icons.filled.WarningAmber
import androidx.compose.ui.text.font.FontWeight
import androidx.compose.ui.unit.dp
import androidx.compose.ui.unit.sp
import com.warkop.pos.debug.DebugPresets
import com.warkop.pos.setup.TerminalSetupScreen
import com.warkop.pos.state.Dest
import com.warkop.pos.state.PosStore
import com.warkop.pos.theme.PosTheme
import com.warkop.pos.ui.screens.*
import kotlinx.coroutines.delay

@Composable
fun PosApp(store: PosStore, preset: String? = null) {
    LaunchedEffect(preset) { if (preset != null) DebugPresets.apply(store, preset) else store.bootstrap() }
    PosTheme(dark = store.darkOverride ?: autoDark()) {
        Surface(Modifier.fillMaxSize(), color = MaterialTheme.colorScheme.background) {
            Box(Modifier.fillMaxSize()) {
                Box(Modifier.fillMaxSize().safeDrawingPadding()) {
                when (store.dest) {
                    Dest.SETUP -> store.backendForSetup?.let {
                        // 073 — "Batal" hanya bila tablet sudah punya outlet + terminal: pilihan lama berlaku sampai yang baru dibuat.
                        TerminalSetupScreen(it, notice = store.setupNotice, header = { StoreMark(store, logoSize = 48.dp) }, onCancel = if (store.config != null) ({ store.go(Dest.PIN) }) else null) { cfg -> store.setConfig(cfg) }
                    }
                    Dest.PIN -> PinScreen(store)
                    Dest.OPEN_SHIFT -> OpenShiftScreen(store)
                    // 055 / T028–T031 — tarik-untuk-segarkan di layar yang memuat data server (counter punya pembungkus sendiri).
                    Dest.TABLES -> PosShell(store, "Meja") { RefreshableScreen(store.refreshing, store::refreshCurrent, Modifier.fillMaxSize()) { TablesScreen(store) } }
                    Dest.EDITOR -> EditorScreen(store)
                    Dest.TENDER -> TenderScreen(store)
                    Dest.DONE -> DoneScreen(store)
                    Dest.ORDERS -> RefreshableScreen(store.refreshing, store::refreshCurrent, Modifier.fillMaxSize()) { OrdersScreen(store) }
                    Dest.SHIFT -> RefreshableScreen(store.refreshing, store::refreshCurrent, Modifier.fillMaxSize()) { ShiftHubScreen(store) }
                    Dest.XREPORT -> RefreshableScreen(store.refreshing, store::refreshCurrent, Modifier.fillMaxSize()) { XReportScreen(store) }
                    Dest.CLOSE_SHIFT -> RefreshableScreen(store.refreshing, store::refreshCurrent, Modifier.fillMaxSize()) { CloseShiftScreen(store) }
                    Dest.CLOSE_RESULT -> CloseResultScreen(store)
                    Dest.SYNC -> RefreshableScreen(store.refreshing, store::refreshCurrent, Modifier.fillMaxSize()) { SyncScreen(store) }
                    Dest.MATERIALS -> RefreshableScreen(store.refreshing, store::refreshCurrent, Modifier.fillMaxSize()) { MaterialsScreen(store) }
                }
                }
                SheetHost(store)
                ModeChangedDialog(store)
                // 087 — peringatan bahan minus: tetap tampil sampai ditutup (tidak ikut hilang seperti toast).
                if (store.materialNotice.isNotEmpty()) MaterialNoticeBanner(store.materialNotice, store::dismissMaterialNotice, Modifier.align(Alignment.TopCenter).padding(top = 16.dp))
                store.toast?.let { msg ->
                    LaunchedEffect(msg) { delay(3200); store.clearToast() }
                    Surface(Modifier.align(Alignment.BottomCenter).padding(bottom = 96.dp).widthIn(max = 560.dp), shape = RoundedCornerShape(14.dp), color = MaterialTheme.colorScheme.inverseSurface, contentColor = MaterialTheme.colorScheme.inverseOnSurface) {
                        Text(msg, Modifier.padding(horizontal = 20.dp, vertical = 14.dp))
                    }
                }
            }
        }
    }
}

/**
 * 077 / FR-004 — mode data berganti di web saat sesi berjalan. Dialog tidak bisa ditutup: satu-satunya jalan keluar adalah
 * Keluar, supaya order tidak diteruskan terhadap data mode lain. Order terbuka tetap aman di server dan muncul lagi saat mode kembali.
 */
@Composable
private fun ModeChangedDialog(store: PosStore) {
    val new = store.modeChanged ?: return
    AlertDialog(
        onDismissRequest = {},
        title = { Text("Mode berubah ke ${new.name}") },
        text = { Text("Mode berubah ke ${new.name}. Keluar lalu masuk lagi untuk melanjutkan. Pesanan yang sedang terbuka tetap tersimpan.") },
        confirmButton = { TextButton({ store.leaveForNewMode() }) { Text("Keluar") } },
    )
}

/** 087 — kartu peringatan bahan minus; satu baris per bahan. Bukan galat: penjualan tetap tercatat. */
@Composable
private fun MaterialNoticeBanner(lines: List<String>, onClose: () -> Unit, modifier: Modifier = Modifier) {
    Surface(modifier.widthIn(max = 640.dp).padding(horizontal = 16.dp), shape = RoundedCornerShape(14.dp), color = MaterialTheme.colorScheme.errorContainer, contentColor = MaterialTheme.colorScheme.onErrorContainer, shadowElevation = 6.dp) {
        Row(Modifier.padding(horizontal = 18.dp, vertical = 14.dp), verticalAlignment = Alignment.Top) {
            Icon(Icons.Filled.WarningAmber, null, Modifier.size(22.dp))
            Spacer(Modifier.width(12.dp))
            Column(Modifier.weight(1f)) {
                Text("Stok bahan minus", fontWeight = FontWeight.SemiBold, fontSize = 16.sp)
                lines.forEach { Text(it, fontSize = 14.sp, modifier = Modifier.padding(top = 4.dp)) }
            }
            TextButton(onClose) { Text("Tutup") }
        }
    }
}
