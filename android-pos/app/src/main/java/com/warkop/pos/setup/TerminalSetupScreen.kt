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
import com.warkop.pos.data.TerminalConfig
import com.warkop.pos.ui.PrimaryPill
import kotlinx.coroutines.launch

private enum class Step { SERVER, OUTLET }

/**
 * Penyiapan terminal sekali pakai (research D5): alamat server → login owner/admin → pilih outlet → daftarkan
 * terminal. Token admin hanya dipakai untuk mengambil roster dan memulai sesi PIN; PIN tidak pernah diketik di sini.
 */
@Composable
fun TerminalSetupScreen(backend: PosBackend, onDone: (TerminalConfig) -> Unit) {
    val scope = rememberCoroutineScope()
    var step by remember { mutableStateOf(Step.SERVER) }
    var url by remember { mutableStateOf("") }
    var username by remember { mutableStateOf("") }
    var password by remember { mutableStateOf("") }
    var showPassword by remember { mutableStateOf(false) }
    var outlets by remember { mutableStateOf<List<OutletChoice>>(emptyList()) }
    var chosen by remember { mutableStateOf<OutletChoice?>(null) }
    var terminalName by remember { mutableStateOf("Tablet kasir") }
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
                    OutlinedTextField(url, { url = it }, Modifier.fillMaxWidth(), label = { Text("Alamat server") }, singleLine = true, enabled = !busy,
                        keyboardOptions = KeyboardOptions(keyboardType = KeyboardType.Uri), placeholder = { Text("192.168.1.10:8010") }, supportingText = { Text("Alamat server outlet (emulator: 10.0.2.2:8010)") })
                    OutlinedTextField(username, { username = it }, Modifier.fillMaxWidth().padding(top = 8.dp), label = { Text("Username") }, singleLine = true, enabled = !busy)
                    OutlinedTextField(password, { password = it }, Modifier.fillMaxWidth().padding(top = 8.dp), label = { Text("Kata sandi") }, singleLine = true, enabled = !busy,
                        visualTransformation = if (showPassword) VisualTransformation.None else PasswordVisualTransformation(),
                        trailingIcon = { IconButton({ showPassword = !showPassword }) { Icon(if (showPassword) Icons.Filled.VisibilityOff else Icons.Filled.Visibility, "Tampilkan kata sandi") } })
                } else {
                    outlets.forEach { o ->
                        Row(Modifier.fillMaxWidth().padding(vertical = 2.dp), verticalAlignment = Alignment.CenterVertically) {
                            RadioButton(chosen?.id == o.id, { chosen = o }); Text(o.name, fontSize = 17.sp)
                        }
                    }
                    OutlinedTextField(terminalName, { terminalName = it }, Modifier.fillMaxWidth().padding(top = 12.dp), label = { Text("Nama terminal") }, singleLine = true, enabled = !busy)
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
                }, Modifier.fillMaxWidth(), enabled = url.length > 3 && username.isNotBlank() && password.isNotEmpty())
                else PrimaryPill("Daftarkan terminal", {
                    val o = chosen ?: return@PrimaryPill
                    run { onDone(backend.pairTerminal(o, terminalName.ifBlank { "Terminal" })) }
                }, Modifier.fillMaxWidth(), enabled = chosen != null, icon = Icons.Filled.Check)
            }
        }
    }
}
