package com.warkop.manager.ui.shell

import androidx.compose.material3.AlertDialog
import androidx.compose.material3.Text
import androidx.compose.material3.TextButton
import androidx.compose.runtime.Composable
import androidx.compose.runtime.collectAsState
import androidx.compose.runtime.getValue
import androidx.compose.ui.window.DialogProperties
import androidx.lifecycle.compose.LifecycleResumeEffect
import androidx.lifecycle.createSavedStateHandle
import com.warkop.core.domain.ApiError
import com.warkop.manager.data.DashboardRepository
import com.warkop.manager.data.ManagerSession
import com.warkop.manager.data.SessionRepository
import com.warkop.manager.domain.Dest
import com.warkop.manager.ui.categories.CategoriesScreen
import com.warkop.manager.ui.dashboard.DashboardScreen
import com.warkop.manager.ui.stock.StockScreen
import com.warkop.manager.ui.products.ProductsScreen
import com.warkop.manager.ui.dashboard.DashboardViewModel
import com.warkop.manager.ui.components.LoadingBox
import com.warkop.manager.ui.components.MessageBox
import com.warkop.manager.ui.container
import com.warkop.manager.ui.managerViewModel
import com.warkop.manager.ui.setup.LoginScreen
import com.warkop.manager.ui.setup.OutletPickerScreen
import com.warkop.manager.ui.setup.ServerSettingsScreen

/** 094 / T048 — layar akar: server → outlet → login → kerangka aplikasi. */
@Composable
fun ManagerRoot() {
    val vm = managerViewModel { RootViewModel(it) }
    val stage by vm.stage.collectAsState()
    val online by vm.online.collectAsState()
    val modeChanged by vm.modeChanged.collectAsState()
    val c = container()

    when (val s = stage) {
        Stage.Server -> ServerSettingsScreen(onSaved = vm::recompute, onBack = null)
        Stage.Outlet -> OutletPickerScreen(onChosen = vm::recompute, onServerSettings = vm::changeServer)
        is Stage.Login -> LoginScreen(notice = s.notice, onLoggedIn = vm::onLoggedIn, onChangeOutlet = vm::changeOutlet, onServerSettings = vm::changeServer)
        Stage.Restoring -> LoadingBox()
        is Stage.RestoreFailed -> MessageBox("Server tidak dapat dihubungi", s.message, error = true, actionLabel = "Coba lagi", onAction = vm::restore)
        is Stage.Ready -> {
            LifecycleResumeEffect(Unit) {
                vm.refreshOnResume()
                onPauseOrDispose { }
            }
            AppShell(
                session = s.session,
                demo = s.demo,
                online = online,
                outletName = c.session.selectedOutlet()?.name.orEmpty(),
                account = AccountActions(changeOutlet = vm::changeOutlet, changeServer = vm::changeServer, logout = vm::logout),
            ) { dest, open ->
                DestinationContent(dest, s.session, open) { e ->
                    SessionRepository.isSessionEnd(e).also { ended -> if (ended) vm.sessionEnded(e) }
                }
            }
        }
    }

    modeChanged?.let { serverDemo ->
        AlertDialog(
            onDismissRequest = {},
            properties = DialogProperties(dismissOnBackPress = false, dismissOnClickOutside = false),
            title = { Text("Mode data berubah") },
            text = { Text("Server kini memakai mode ${if (serverDemo) "DEMO" else "LIVE"}. Outlet berbeda per mode, jadi pilih outlet lagi sebelum melanjutkan.") },
            confirmButton = { TextButton(onClick = vm::acknowledgeModeChange) { Text("Pilih outlet") } },
        )
    }
}

/** Isi tiap tujuan. Modul diisi bertahap per user story; yang belum dibangun menampilkan keterangan sementara. */
@Composable
fun DestinationContent(dest: Dest, session: ManagerSession, open: (Dest) -> Unit, onSessionError: (ApiError) -> Boolean) {
    when (dest) {
        Dest.DASHBOARD -> DashboardScreen(
            vm = managerViewModel { c -> DashboardViewModel(DashboardRepository(c::api), createSavedStateHandle(), onSessionError) },
            menuKeys = session.menuKeys,
            open = open,
        )
        Dest.CATEGORIES -> CategoriesScreen(onSessionError)
        Dest.STOCK -> StockScreen(session, onSessionError)
        Dest.PRODUCTS -> ProductsScreen(session, onSessionError)
        Dest.KITCHEN -> com.warkop.manager.ui.kitchen.KitchenScreen(session, onSessionError)
        Dest.DINING -> com.warkop.manager.ui.dining.DiningScreen(onSessionError)
        Dest.VENDORS -> com.warkop.manager.ui.purchase.VendorsScreen(onSessionError)
        Dest.MATERIALS -> com.warkop.manager.ui.purchase.MaterialsScreen(onSessionError)
        Dest.PURCHASE_ORDERS -> com.warkop.manager.ui.purchase.PurchaseOrdersScreen(onSessionError)
        else -> MessageBox("Segera hadir", "Layar ini sedang dibangun (fitur 094).")
    }
}
