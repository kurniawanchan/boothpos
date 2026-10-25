package com.warkop.pos.ui

import androidx.compose.foundation.layout.*
import androidx.compose.foundation.shape.RoundedCornerShape
import androidx.compose.material3.*
import androidx.compose.runtime.*
import androidx.compose.ui.Alignment
import androidx.compose.ui.Modifier
import androidx.compose.ui.unit.dp
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
                        TerminalSetupScreen(it, notice = store.setupNotice, onCancel = if (store.config != null) ({ store.go(Dest.PIN) }) else null) { cfg -> store.setConfig(cfg) }
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
                }
                }
                SheetHost(store)
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
