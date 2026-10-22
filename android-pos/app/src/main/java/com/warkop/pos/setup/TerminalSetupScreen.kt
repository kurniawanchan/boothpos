package com.warkop.pos.setup

import androidx.compose.foundation.background
import androidx.compose.foundation.layout.*
import androidx.compose.foundation.rememberScrollState
import androidx.compose.foundation.shape.RoundedCornerShape
import androidx.compose.foundation.text.KeyboardOptions
import androidx.compose.foundation.verticalScroll
import androidx.compose.material.icons.Icons
import androidx.compose.material.icons.filled.Check
import androidx.compose.material.icons.filled.Visibility
import androidx.compose.material.icons.filled.VisibilityOff
import androidx.compose.material3.*
import androidx.compose.runtime.*
import androidx.compose.ui.Alignment
import androidx.compose.ui.Modifier
import androidx.compose.ui.text.font.FontWeight
import androidx.compose.ui.text.input.KeyboardType
import androidx.compose.ui.text.input.PasswordVisualTransformation
import androidx.compose.ui.text.input.VisualTransformation
import androidx.compose.ui.unit.dp
import androidx.compose.ui.unit.sp
import com.warkop.core.domain.ApiError
import com.warkop.pos.data.OutletChoice
import com.warkop.pos.data.PosBackend
import com.warkop.pos.data.DiscoveryResult
import com.warkop.pos.data.ServerDiscovery
import com.warkop.pos.data.TerminalConfig
import com.warkop.pos.ui.PrimaryPill
import kotlinx.coroutines.launch

private enum class Step { SERVER, OUTLET }

/**
 * Penyiapan terminal sekali pakai (research D5): cari alamat server (otomatis, dari pilihan admin di web — 065, tanpa kolom
 * ketik) → login owner/admin → pilih outlet → daftarkan terminal. Token admin hanya dipakai untuk mengambil roster dan memulai sesi PIN; PIN tidak pernah diketik di sini.
 */
@Composable
fun TerminalSetupScreen(
    backend: PosBackend,
    discoveryOverride: ServerDiscovery? = null,
    onDone: (TerminalConfig) -> Unit,
) {
    val context = androidx.compose.ui.platform.LocalContext.current
    val discovery = remember { discoveryOverride ?: ServerDiscovery.fromContext(context) }
    val scope = rememberCoroutineScope()
    var step by remember { mutableStateOf(Step.SERVER) }
    // 065 / US6 — tidak ada kolom "Alamat server": alamat dari pilihan admin di web, dicari lewat alamat bawaan aplikasi.
    // Bila tablet SUDAH menyimpan alamat (penyiapan ulang tanpa hapus data) alamat itu dipakai; server baru ditanya lagi
    // hanya setelah data dihapus (keputusan pemilik).
    var found by remember { mutableStateOf<DiscoveryResult?>(backend.serverUrl()?.takeIf { it.isNotBlank() }?.let { DiscoveryResult.Found(it) }) }
    var attempt by remember { mutableStateOf(0) }
    LaunchedEffect(attempt) {
        if (found == null) found = discovery.discover()
    }
    val url = (found as? DiscoveryResult.Found)?.url.orEmpty()
    var username by remember { mutableStateOf("") }
    var password by remember { mutableStateOf("") }
    var showPassword by remember { mutableStateOf(false) }
    var outlets by remember { mutableStateOf<List<OutletChoice>>(emptyList()) }
    var chosen by remember { mutableStateOf<OutletChoice?>(null) }
    // 064 — kolom dikosongkan: nama kini identitas terminal dan harus unik per outlet, jadi nama bawaan yang sama untuk semua
    // tablet akan selalu bentrok pada tablet kedua.
    var terminalName by remember { mutableStateOf("") }
    var busy by remember { mutableStateOf(false) }
    var error by remember { mutableStateOf<String?>(null) }

    fun run(block: suspend () -> Unit) {
        busy = true; error = null
        scope.launch {
            try { block() } catch (e: ApiError) { error = e.message } finally { busy = false }
        }
    }

    Box(Modifier.fillMaxSize().background(MaterialTheme.colorScheme.background), contentAlignment = Alignment.Center) {
        Surface(Modifier.widthIn(max = 560.dp).fillMaxWidth().padding(20.dp), shape = RoundedCornerShape(28.dp), color = MaterialTheme.colorScheme.surfaceContainer) {
            Column(Modifier.padding(28.dp).verticalScroll(rememberScrollState())) {
                Text("Siapkan terminal", fontSize = 28.sp)
                Text(
                    if (step == Step.SERVER) "Masuk sekali sebagai pemilik atau admin untuk menghubungkan terminal ini ke server outlet."
                    else "Pilih outlet tempat terminal ini bertugas.",
                    Modifier.padding(top = 6.dp, bottom = 18.dp), color = MaterialTheme.colorScheme.onSurfaceVariant,
                )
                if (step == Step.SERVER) {
                    when (val f = found) {
                        null -> Row(Modifier.fillMaxWidth().padding(vertical = 8.dp), verticalAlignment = Alignment.CenterVertically) {
                            CircularProgressIndicator(Modifier.size(22.dp), strokeWidth = 2.dp); Spacer(Modifier.width(12.dp)); Text("Mencari server…")
                        }
                        is DiscoveryResult.NotConfigured -> DiscoveryProblem("Alamat server belum diatur. Minta admin mengisinya di web (Pengaturan › Server address), lalu coba lagi.") { found = null; attempt++ }
                        DiscoveryResult.Unreachable -> DiscoveryProblem("Server tidak terjangkau. Pastikan tablet satu jaringan dengan server outlet atau punya internet, lalu coba lagi.") { found = null; attempt++ }
                        DiscoveryResult.NoDefaults -> DiscoveryProblem("Aplikasi ini dibuat tanpa alamat server bawaan. Hubungi admin untuk versi aplikasi yang benar.", retry = null)
                        is DiscoveryResult.Found -> Unit
                    }
                    OutlinedTextField(username, { username = it }, Modifier.fillMaxWidth().padding(top = 8.dp), label = { Text("Username") }, singleLine = true, enabled = !busy && found is DiscoveryResult.Found)
                    OutlinedTextField(password, { password = it }, Modifier.fillMaxWidth().padding(top = 8.dp), label = { Text("Kata sandi") }, singleLine = true, enabled = !busy && found is DiscoveryResult.Found,
                        visualTransformation = if (showPassword) VisualTransformation.None else PasswordVisualTransformation(),
                        trailingIcon = { IconButton({ showPassword = !showPassword }) { Icon(if (showPassword) Icons.Filled.VisibilityOff else Icons.Filled.Visibility, "Tampilkan kata sandi") } })
                } else {
                    outlets.forEach { o ->
                        Row(Modifier.fillMaxWidth().padding(vertical = 2.dp), verticalAlignment = Alignment.CenterVertically) {
                            RadioButton(chosen?.id == o.id, { chosen = o }); Text(o.name, fontSize = 17.sp)
                        }
                    }
                    OutlinedTextField(terminalName, { terminalName = it }, Modifier.fillMaxWidth().padding(top = 12.dp), label = { Text("Nama terminal (mis. Kasir Depan)") }, singleLine = true, enabled = !busy)
                }
                error?.let { Text(it, Modifier.padding(top = 12.dp), color = MaterialTheme.colorScheme.error, fontSize = 14.sp) }
                Spacer(Modifier.height(18.dp))
                if (busy) CircularProgressIndicator(Modifier.align(Alignment.CenterHorizontally))
                else if (step == Step.SERVER) PrimaryPill("Lanjut", {
                    run {
                        backend.adminLogin(url, username, password)
                        password = ""
                        outlets = backend.listOutlets()
                        chosen = outlets.firstOrNull()
                        step = Step.OUTLET
                    }
                }, Modifier.fillMaxWidth(), enabled = url.isNotBlank() && username.isNotBlank() && password.isNotEmpty())
                else PrimaryPill("Daftarkan terminal", {
                    val o = chosen ?: return@PrimaryPill
                    run { onDone(backend.pairTerminal(o, terminalName.trim())) }
                }, Modifier.fillMaxWidth(), enabled = chosen != null && terminalName.isNotBlank(), icon = Icons.Filled.Check)
            }
        }
    }
}

/** 065 — pesan jelas saat alamat server tidak bisa ditemukan; tanpa kolom ketik sebagai jalan keluar (keputusan pemilik). */
@Composable
private fun DiscoveryProblem(message: String, retry: (() -> Unit)?) {
    Column(Modifier.fillMaxWidth().padding(vertical = 4.dp)) {
        Text(message, color = MaterialTheme.colorScheme.error, fontSize = 15.sp)
        retry?.let { TextButton(it, Modifier.padding(top = 4.dp)) { Text("Coba lagi", fontWeight = androidx.compose.ui.text.font.FontWeight.SemiBold) } }
    }
}
