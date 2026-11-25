package com.warkop.manager.ui.setup

import androidx.compose.foundation.clickable
import androidx.compose.foundation.layout.Arrangement
import androidx.compose.foundation.layout.Box
import androidx.compose.foundation.layout.Column
import androidx.compose.foundation.layout.Row
import androidx.compose.foundation.layout.Spacer
import androidx.compose.foundation.layout.fillMaxSize
import androidx.compose.foundation.layout.fillMaxWidth
import androidx.compose.foundation.layout.height
import androidx.compose.foundation.layout.imePadding
import androidx.compose.foundation.layout.padding
import androidx.compose.foundation.layout.safeDrawingPadding
import androidx.compose.foundation.layout.size
import androidx.compose.foundation.layout.widthIn
import androidx.compose.foundation.lazy.LazyColumn
import androidx.compose.foundation.lazy.items
import androidx.compose.foundation.rememberScrollState
import androidx.compose.foundation.selection.selectable
import androidx.compose.foundation.text.KeyboardActions
import androidx.compose.foundation.text.KeyboardOptions
import androidx.compose.foundation.verticalScroll
import androidx.compose.material.icons.Icons
import androidx.compose.material.icons.outlined.ChevronRight
import androidx.compose.material.icons.outlined.Storefront
import androidx.compose.material.icons.outlined.Visibility
import androidx.compose.material.icons.outlined.VisibilityOff
import androidx.compose.material.icons.outlined.WarningAmber
import androidx.compose.material3.Button
import androidx.compose.material3.Card
import androidx.compose.material3.CircularProgressIndicator
import androidx.compose.material3.HorizontalDivider
import androidx.compose.material3.Icon
import androidx.compose.material3.IconButton
import androidx.compose.material3.MaterialTheme
import androidx.compose.material3.OutlinedTextField
import androidx.compose.material3.RadioButton
import androidx.compose.material3.Text
import androidx.compose.material3.TextButton
import androidx.compose.runtime.Composable
import androidx.compose.runtime.collectAsState
import androidx.compose.runtime.getValue
import androidx.compose.runtime.mutableStateOf
import androidx.compose.runtime.saveable.rememberSaveable
import androidx.compose.runtime.setValue
import androidx.compose.ui.Alignment
import androidx.compose.ui.Modifier
import androidx.compose.ui.platform.LocalContext
import androidx.compose.ui.semantics.Role
import androidx.compose.ui.text.input.ImeAction
import androidx.compose.ui.text.input.KeyboardType
import androidx.compose.ui.text.input.PasswordVisualTransformation
import androidx.compose.ui.text.input.VisualTransformation
import androidx.compose.ui.unit.dp
import com.warkop.manager.R
import com.warkop.manager.data.ManagerSession
import com.warkop.manager.data.ServerKind
import com.warkop.manager.ui.components.FormMessage
import com.warkop.manager.ui.components.LoadContent
import com.warkop.manager.ui.components.MessageBox
import com.warkop.manager.ui.components.ModeBadge
import com.warkop.manager.ui.managerViewModel
import com.warkop.manager.ui.theme.ManagerColors

/** Kerangka satu kolom terpusat untuk layar penyiapan (ponsel dan tablet; tablet dibatasi lebar 480dp). */
@Composable
private fun SetupFrame(title: String, subtitle: String?, content: @Composable () -> Unit) {
    Box(Modifier.fillMaxSize().safeDrawingPadding().imePadding(), contentAlignment = Alignment.TopCenter) {
        Column(
            Modifier.widthIn(max = 480.dp).fillMaxWidth().verticalScroll(rememberScrollState()).padding(24.dp),
            verticalArrangement = Arrangement.spacedBy(16.dp),
        ) {
            Text(title, style = MaterialTheme.typography.headlineSmall)
            subtitle?.let { Text(it, style = MaterialTheme.typography.bodyMedium, color = MaterialTheme.colorScheme.onSurfaceVariant) }
            content()
        }
    }
}

@Composable
fun ServerSettingsScreen(onSaved: () -> Unit, onBack: (() -> Unit)?) {
    val ctx = LocalContext.current
    val vm = managerViewModel { ServerSettingsViewModel(it, ctx.getString(R.string.default_local_url) to ctx.getString(R.string.default_online_url)) }
    val s by vm.state.collectAsState()
    SetupFrame("Alamat server", "Hubungkan aplikasi ke server Warkop WJM toko. Isi alamat lokal (jaringan toko) dan, bila ada, alamat online.") {
        ServerKind.entries.forEach { kind ->
            val selected = s.active == kind
            Card(Modifier.fillMaxWidth().selectable(selected, role = Role.RadioButton) { vm.setActive(kind) }) {
                Column(Modifier.padding(16.dp), verticalArrangement = Arrangement.spacedBy(8.dp)) {
                    Row(verticalAlignment = Alignment.CenterVertically) {
                        RadioButton(selected, onClick = null)
                        Spacer(Modifier.size(8.dp))
                        Text(if (kind == ServerKind.LOCAL) "Lokal" else "Online", style = MaterialTheme.typography.titleSmall)
                    }
                    OutlinedTextField(
                        value = if (kind == ServerKind.LOCAL) s.local else s.online,
                        onValueChange = { if (kind == ServerKind.LOCAL) vm.setLocal(it) else vm.setOnline(it) },
                        label = { Text(if (kind == ServerKind.LOCAL) "Alamat lokal" else "Alamat online") },
                        placeholder = { Text(if (kind == ServerKind.LOCAL) "http://192.168.1.10:8010" else "https://pos.toko.com") },
                        singleLine = true,
                        keyboardOptions = KeyboardOptions(keyboardType = KeyboardType.Uri),
                        modifier = Modifier.fillMaxWidth(),
                    )
                    if (kind == ServerKind.ONLINE && s.online.trim().startsWith("http://", ignoreCase = true)) {
                        WarningRow("Alamat online tanpa HTTPS: kata sandi bisa terlihat di jaringan. Gunakan https:// bila server mendukung.")
                    }
                }
            }
        }
        s.result?.let { Text(it, color = if (s.resultOk) ManagerColors.success.fg else MaterialTheme.colorScheme.error) }
        Button(onClick = { vm.testAndSave(onSaved) }, enabled = !s.testing, modifier = Modifier.fillMaxWidth()) {
            if (s.testing) CircularProgressIndicator(Modifier.size(18.dp), strokeWidth = 2.dp) else Text("Uji sambungan & simpan")
        }
        TextButton(onClick = vm::fetchFromServer, enabled = !s.testing, modifier = Modifier.fillMaxWidth()) { Text("Ambil alamat pilihan admin dari server lokal") }
        onBack?.let { TextButton(onClick = it, modifier = Modifier.fillMaxWidth()) { Text("Kembali") } }
        HorizontalDivider()
        Text("Versi ${ctx.getString(R.string.app_build)}", style = MaterialTheme.typography.bodySmall, color = MaterialTheme.colorScheme.onSurfaceVariant)
    }
}

@Composable
private fun WarningRow(text: String) {
    Row(horizontalArrangement = Arrangement.spacedBy(8.dp), verticalAlignment = Alignment.Top) {
        Icon(Icons.Outlined.WarningAmber, null, tint = ManagerColors.warning.fg)
        Text(text, style = MaterialTheme.typography.bodySmall, color = ManagerColors.warning.fg)
    }
}

@Composable
fun OutletPickerScreen(onChosen: () -> Unit, onServerSettings: () -> Unit) {
    val vm = managerViewModel { OutletPickerViewModel(it) }
    val state by vm.state.collectAsState()
    Column(Modifier.fillMaxSize().safeDrawingPadding()) {
        Column(Modifier.padding(start = 24.dp, end = 24.dp, top = 24.dp), verticalArrangement = Arrangement.spacedBy(4.dp)) {
            Text("Pilih outlet", style = MaterialTheme.typography.headlineSmall)
            Text("Data yang Anda kelola mengikuti outlet ini. Bisa diganti nanti dari menu akun.", style = MaterialTheme.typography.bodyMedium, color = MaterialTheme.colorScheme.onSurfaceVariant)
        }
        Box(Modifier.weight(1f)) {
            LoadContent(state, onRetry = vm::load) { rows ->
                if (rows.isEmpty()) {
                    MessageBox("Belum ada outlet aktif", "Aktifkan outlet di web: Pengaturan → Outlet.", actionLabel = "Muat ulang", onAction = vm::load)
                } else {
                    LazyColumn(Modifier.fillMaxSize(), contentPadding = androidx.compose.foundation.layout.PaddingValues(16.dp), verticalArrangement = Arrangement.spacedBy(8.dp)) {
                        items(rows, key = { it.id }) { row ->
                            Card(Modifier.fillMaxWidth().widthIn(max = 640.dp).clickable { vm.choose(row, onChosen) }) {
                                Row(Modifier.padding(16.dp), verticalAlignment = Alignment.CenterVertically, horizontalArrangement = Arrangement.spacedBy(12.dp)) {
                                    Icon(Icons.Outlined.Storefront, null, tint = MaterialTheme.colorScheme.primary)
                                    Text(row.name, style = MaterialTheme.typography.titleMedium, modifier = Modifier.weight(1f))
                                    if (row.id == vm.selectedId) Text("Terpilih", color = MaterialTheme.colorScheme.primary, style = MaterialTheme.typography.labelMedium)
                                    Icon(Icons.Outlined.ChevronRight, null)
                                }
                            }
                        }
                    }
                }
            }
        }
        TextButton(onClick = onServerSettings, modifier = Modifier.fillMaxWidth().padding(8.dp)) { Text("Ganti alamat server") }
    }
}

@Composable
fun LoginScreen(notice: String? = null, onLoggedIn: (ManagerSession) -> Unit, onChangeOutlet: () -> Unit, onServerSettings: () -> Unit) {
    val vm = managerViewModel { LoginViewModel(it) }
    val s by vm.state.collectAsState()
    var showPassword by rememberSaveable { mutableStateOf(false) }
    val outlet = vm.outlet
    SetupFrame("Masuk", "Aplikasi manajemen Warkop WJM. Gunakan akun yang sama dengan web.") {
        if (outlet != null) {
            Card(Modifier.fillMaxWidth()) {
                Row(Modifier.padding(16.dp), verticalAlignment = Alignment.CenterVertically, horizontalArrangement = Arrangement.spacedBy(12.dp)) {
                    Icon(Icons.Outlined.Storefront, null, tint = MaterialTheme.colorScheme.primary)
                    Column(Modifier.weight(1f)) {
                        Text("Outlet", style = MaterialTheme.typography.labelMedium, color = MaterialTheme.colorScheme.onSurfaceVariant)
                        Text(outlet.name, style = MaterialTheme.typography.titleMedium)
                    }
                    ModeBadge(outlet.demo)
                    TextButton(onClick = onChangeOutlet) { Text("Ganti") }
                }
            }
        }
        notice?.let { WarningRow(it) }
        if (vm.insecureOnline) WarningRow("Server online ini tanpa HTTPS: kata sandi dikirim tanpa enkripsi.")
        OutlinedTextField(
            value = s.username, onValueChange = vm::setUsername, label = { Text("Username") }, singleLine = true,
            isError = s.status.error("username") != null, supportingText = s.status.error("username")?.let { { Text(it) } },
            keyboardOptions = KeyboardOptions(imeAction = ImeAction.Next), modifier = Modifier.fillMaxWidth(),
        )
        OutlinedTextField(
            value = s.password, onValueChange = vm::setPassword, label = { Text("Kata sandi") }, singleLine = true,
            isError = s.status.error("password") != null, supportingText = s.status.error("password")?.let { { Text(it) } },
            visualTransformation = if (showPassword) VisualTransformation.None else PasswordVisualTransformation(),
            trailingIcon = {
                IconButton(onClick = { showPassword = !showPassword }) {
                    Icon(if (showPassword) Icons.Outlined.VisibilityOff else Icons.Outlined.Visibility, if (showPassword) "Sembunyikan kata sandi" else "Tampilkan kata sandi")
                }
            },
            keyboardOptions = KeyboardOptions(keyboardType = KeyboardType.Password, imeAction = ImeAction.Done),
            keyboardActions = KeyboardActions(onDone = { vm.submit(onLoggedIn) }),
            modifier = Modifier.fillMaxWidth(),
        )
        FormMessage(s.status.message)
        Button(onClick = { vm.submit(onLoggedIn) }, enabled = !s.status.saving, modifier = Modifier.fillMaxWidth().height(48.dp)) {
            if (s.status.saving) CircularProgressIndicator(Modifier.size(18.dp), strokeWidth = 2.dp) else Text("Masuk")
        }
        TextButton(onClick = onServerSettings, modifier = Modifier.fillMaxWidth()) { Text("Ganti alamat server") }
    }
}
