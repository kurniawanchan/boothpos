package com.warkop.manager.ui.shell

import androidx.compose.foundation.layout.Arrangement
import androidx.compose.foundation.layout.Box
import androidx.compose.foundation.layout.Column
import androidx.compose.foundation.layout.Row
import androidx.compose.foundation.layout.fillMaxSize
import androidx.compose.foundation.layout.padding
import androidx.compose.material.icons.Icons
import androidx.compose.material.icons.outlined.AccountCircle
import androidx.compose.material.icons.outlined.Category
import androidx.compose.material.icons.outlined.Dashboard
import androidx.compose.material.icons.outlined.Inventory2
import androidx.compose.material.icons.outlined.LocalShipping
import androidx.compose.material.icons.outlined.MoreHoriz
import androidx.compose.material.icons.outlined.ReceiptLong
import androidx.compose.material.icons.outlined.Restaurant
import androidx.compose.material.icons.outlined.Science
import androidx.compose.material.icons.outlined.TableRestaurant
import androidx.compose.material.icons.outlined.Warehouse
import androidx.compose.material3.DropdownMenu
import androidx.compose.material3.DropdownMenuItem
import androidx.compose.material3.ExperimentalMaterial3Api
import androidx.compose.material3.Icon
import androidx.compose.material3.IconButton
import androidx.compose.material3.MaterialTheme
import androidx.compose.material3.Scaffold
import androidx.compose.material3.Text
import androidx.compose.material3.TopAppBar
import androidx.compose.material3.adaptive.currentWindowAdaptiveInfo
import androidx.compose.material3.adaptive.navigationsuite.NavigationSuiteScaffold
import androidx.compose.material3.adaptive.navigationsuite.NavigationSuiteScaffoldDefaults
import androidx.compose.material3.adaptive.navigationsuite.NavigationSuiteType
import androidx.compose.runtime.Composable
import androidx.compose.runtime.getValue
import androidx.compose.runtime.mutableStateOf
import androidx.compose.runtime.remember
import androidx.compose.runtime.saveable.rememberSaveable
import androidx.compose.runtime.setValue
import androidx.compose.ui.Alignment
import androidx.compose.ui.Modifier
import androidx.compose.ui.graphics.vector.ImageVector
import androidx.compose.ui.unit.dp
import androidx.window.core.layout.WindowWidthSizeClass
import com.warkop.manager.data.ManagerSession
import com.warkop.manager.domain.Dest
import com.warkop.manager.domain.NavSpec
import com.warkop.manager.domain.NavSpecs
import com.warkop.manager.ui.components.MessageBox
import com.warkop.manager.ui.components.ModeBadge
import com.warkop.manager.ui.components.OfflineBanner

fun Dest.icon(): ImageVector = when (this) {
    Dest.DASHBOARD -> Icons.Outlined.Dashboard
    Dest.KITCHEN -> Icons.Outlined.Restaurant
    Dest.DINING -> Icons.Outlined.TableRestaurant
    Dest.CATEGORIES -> Icons.Outlined.Category
    Dest.PRODUCTS -> Icons.Outlined.Inventory2
    Dest.STOCK -> Icons.Outlined.Warehouse
    Dest.VENDORS -> Icons.Outlined.LocalShipping
    Dest.MATERIALS -> Icons.Outlined.Science
    Dest.PURCHASE_ORDERS -> Icons.Outlined.ReceiptLong
}

/** Aksi akun di bilah atas: ganti outlet, ganti server, keluar (FR-008). */
data class AccountActions(val changeOutlet: () -> Unit, val changeServer: () -> Unit, val logout: () -> Unit)

/**
 * 094 / T040, FR-028 — kerangka adaptif. `NavigationSuiteScaffold` memilih sendiri: bilah bawah di layar sempit, rel di
 * layar sedang/lebar. Di layar sempit bilah bawah hanya muat ±5 tujuan, jadi tujuan ke-5 dst. masuk satu item "Lainnya".
 */
@OptIn(ExperimentalMaterial3Api::class)
@Composable
fun AppShell(
    session: ManagerSession,
    demo: Boolean,
    online: Boolean,
    outletName: String,
    account: AccountActions,
    /** Isi tujuan; argumen kedua memindahkan ke tujuan lain (mis. kartu Dashboard → Bahan). */
    content: @Composable (Dest, (Dest) -> Unit) -> Unit,
) {
    val specs = remember(session.menuKeys) { NavSpecs.visible(session.menuKeys) }
    if (specs.isEmpty()) {
        Column(Modifier.fillMaxSize()) {
            MessageBox("Tidak ada menu yang bisa diakses", "Peran Anda belum memegang menu Dashboard, Restoran, Inventori, atau Pembelian. Minta pemilik mengatur peran di web.", actionLabel = "Keluar", onAction = account.logout)
        }
        return
    }
    var current by rememberSaveable { mutableStateOf(NavSpecs.start(session.menuKeys) ?: specs.first().dest) }
    if (specs.none { it.dest == current }) current = specs.first().dest

    val compact = currentWindowAdaptiveInfo().windowSizeClass.windowWidthSizeClass == WindowWidthSizeClass.COMPACT
    val (primary, overflow) = if (compact && specs.size > 5) specs.take(4) to specs.drop(4) else specs to emptyList()
    var moreOpen by remember { mutableStateOf(false) }
    val layoutType = NavigationSuiteScaffoldDefaults.calculateFromAdaptiveInfo(currentWindowAdaptiveInfo())

    NavigationSuiteScaffold(
        layoutType = layoutType,
        navigationSuiteItems = {
            primary.forEach { spec -> navItem(spec, current == spec.dest) { current = spec.dest } }
            if (overflow.isNotEmpty()) {
                item(
                    selected = overflow.any { it.dest == current },
                    onClick = { moreOpen = true },
                    icon = {
                        Box {
                            Icon(Icons.Outlined.MoreHoriz, null)
                            DropdownMenu(expanded = moreOpen, onDismissRequest = { moreOpen = false }) {
                                overflow.forEach { spec ->
                                    DropdownMenuItem(text = { Text(spec.label) }, leadingIcon = { Icon(spec.dest.icon(), null) }, onClick = { current = spec.dest; moreOpen = false })
                                }
                            }
                        }
                    },
                    label = { Text("Lainnya") },
                )
            }
        },
    ) {
        Scaffold(
            topBar = {
                TopAppBar(
                    title = {
                        Column {
                            Text(specs.first { it.dest == current }.label, style = MaterialTheme.typography.titleLarge)
                            Row(horizontalArrangement = Arrangement.spacedBy(8.dp), verticalAlignment = Alignment.CenterVertically) {
                                Text(outletName, style = MaterialTheme.typography.bodySmall, color = MaterialTheme.colorScheme.onSurfaceVariant)
                                ModeBadge(demo)
                            }
                        }
                    },
                    actions = { AccountMenu(session, account) },
                )
            },
        ) { padding ->
            Column(Modifier.padding(padding).fillMaxSize()) {
                if (!online) OfflineBanner()
                Box(Modifier.weight(1f)) { content(current) { dest -> if (specs.any { it.dest == dest }) current = dest } }
            }
        }
    }
}

private fun androidx.compose.material3.adaptive.navigationsuite.NavigationSuiteScope.navItem(spec: NavSpec, selected: Boolean, onClick: () -> Unit) =
    item(selected = selected, onClick = onClick, icon = { Icon(spec.dest.icon(), null) }, label = { Text(spec.label, maxLines = 1) })

@Composable
private fun AccountMenu(session: ManagerSession, account: AccountActions) {
    var open by remember { mutableStateOf(false) }
    Box {
        IconButton(onClick = { open = true }) { Icon(Icons.Outlined.AccountCircle, "Akun") }
        DropdownMenu(expanded = open, onDismissRequest = { open = false }) {
            DropdownMenuItem(text = { Text("${session.userName}${session.roleName?.let { " · $it" } ?: ""}") }, onClick = {}, enabled = false)
            DropdownMenuItem(text = { Text("Ganti outlet") }, onClick = { open = false; account.changeOutlet() })
            DropdownMenuItem(text = { Text("Ganti alamat server") }, onClick = { open = false; account.changeServer() })
            DropdownMenuItem(text = { Text("Keluar") }, onClick = { open = false; account.logout() })
        }
    }
}
