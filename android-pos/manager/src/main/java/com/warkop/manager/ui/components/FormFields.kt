package com.warkop.manager.ui.components

import androidx.compose.foundation.layout.Arrangement
import androidx.compose.foundation.layout.Column
import androidx.compose.foundation.layout.Row
import androidx.compose.foundation.layout.fillMaxWidth
import androidx.compose.foundation.layout.padding
import androidx.compose.foundation.text.KeyboardOptions
import androidx.compose.material3.AlertDialog
import androidx.compose.material3.DropdownMenuItem
import androidx.compose.material3.ExperimentalMaterial3Api
import androidx.compose.material3.ExposedDropdownMenuBox
import androidx.compose.material3.ExposedDropdownMenuDefaults
import androidx.compose.material3.MaterialTheme
import androidx.compose.material3.OutlinedTextField
import androidx.compose.material3.Switch
import androidx.compose.material3.Text
import androidx.compose.material3.TextButton
import androidx.compose.runtime.Composable
import androidx.compose.runtime.getValue
import androidx.compose.runtime.mutableStateOf
import androidx.compose.runtime.remember
import androidx.compose.runtime.setValue
import androidx.compose.ui.Alignment
import androidx.compose.ui.Modifier
import androidx.compose.ui.text.input.KeyboardCapitalization
import androidx.compose.ui.text.input.KeyboardType
import androidx.compose.ui.unit.dp

/**
 * 094 / T041 — kolom formulir bersama. Galat per kolom berasal dari 422 server dengan kunci SAMA dengan payload web
 * ([com.warkop.manager.domain.FormStatus]); batas panjang di sini hanya membantu mengetik, penegaknya server.
 */
@Composable
fun TextInput(
    label: String,
    value: String,
    onChange: (String) -> Unit,
    error: String? = null,
    maxLength: Int? = null,
    enabled: Boolean = true,
    helper: String? = null,
    singleLine: Boolean = true,
    keyboard: KeyboardType = KeyboardType.Text,
    caps: KeyboardCapitalization = KeyboardCapitalization.Sentences,
    modifier: Modifier = Modifier,
) {
    OutlinedTextField(
        value = value,
        onValueChange = { v -> onChange(if (maxLength != null) v.take(maxLength) else v) },
        label = { Text(label) },
        isError = error != null,
        enabled = enabled,
        singleLine = singleLine,
        minLines = if (singleLine) 1 else 3,
        keyboardOptions = KeyboardOptions(keyboardType = keyboard, capitalization = caps),
        supportingText = (error ?: helper ?: maxLength?.let { "${value.length}/$it" })?.let { t -> { Text(t) } },
        modifier = modifier.fillMaxWidth(),
    )
}

/** Angka bulat; hanya digit (dan minus bila [signed]) yang diterima saat mengetik. */
@Composable
fun IntInput(label: String, value: String, onChange: (String) -> Unit, error: String? = null, signed: Boolean = false, helper: String? = null, enabled: Boolean = true) =
    TextInput(label, value, { v -> if (v.isEmpty() || v.matches(if (signed) Regex("-?\\d{0,9}") else Regex("\\d{0,9}"))) onChange(v) }, error, enabled = enabled, helper = helper, keyboard = KeyboardType.Number)

/** Desimal (titik atau koma diterima, disimpan dengan titik — format server). */
@Composable
fun DecimalInput(label: String, value: String, onChange: (String) -> Unit, error: String? = null, signed: Boolean = false, helper: String? = null, enabled: Boolean = true) =
    TextInput(label, value, { raw -> val v = raw.replace(',', '.'); if (v.isEmpty() || v.matches(if (signed) Regex("-?\\d{0,12}(\\.\\d{0,3})?") else Regex("\\d{0,12}(\\.\\d{0,3})?"))) onChange(v) }, error, enabled = enabled, helper = helper, keyboard = KeyboardType.Decimal)

/** Rupiah: bilangan bulat tanpa pemisah ribuan saat mengetik (server menerima "15000" atau "15000.00"). */
@Composable
fun MoneyInput(label: String, value: String, onChange: (String) -> Unit, error: String? = null, helper: String? = null, enabled: Boolean = true) =
    TextInput(label, value, { v -> if (v.isEmpty() || v.matches(Regex("\\d{0,12}"))) onChange(v) }, error, enabled = enabled, helper = helper ?: "Rupiah", keyboard = KeyboardType.Number)

@Composable
fun SwitchRow(label: String, checked: Boolean, onChange: (Boolean) -> Unit, helper: String? = null, enabled: Boolean = true) {
    Row(Modifier.fillMaxWidth().padding(vertical = 4.dp), verticalAlignment = Alignment.CenterVertically) {
        Column(Modifier.weight(1f)) {
            Text(label, style = MaterialTheme.typography.bodyLarge)
            helper?.let { Text(it, style = MaterialTheme.typography.bodySmall, color = MaterialTheme.colorScheme.onSurfaceVariant) }
        }
        Switch(checked = checked, onCheckedChange = onChange, enabled = enabled)
    }
}

data class Choice<T>(val value: T, val label: String)

/** Pilih satu dari daftar; [allowNone] menambah pilihan kosong bernama [noneLabel]. */
@OptIn(ExperimentalMaterial3Api::class)
@Composable
fun <T> SelectInput(
    label: String,
    choices: List<Choice<T>>,
    selected: T?,
    onSelect: (T?) -> Unit,
    error: String? = null,
    allowNone: Boolean = false,
    noneLabel: String = "— Tidak ada —",
    enabled: Boolean = true,
) {
    var open by remember { mutableStateOf(false) }
    val text = choices.firstOrNull { it.value == selected }?.label ?: if (selected == null && allowNone) noneLabel else ""
    ExposedDropdownMenuBox(expanded = open, onExpandedChange = { if (enabled) open = it }) {
        OutlinedTextField(
            value = text,
            onValueChange = {},
            readOnly = true,
            enabled = enabled,
            label = { Text(label) },
            isError = error != null,
            supportingText = error?.let { { Text(it) } },
            trailingIcon = { ExposedDropdownMenuDefaults.TrailingIcon(open) },
            modifier = Modifier.menuAnchor().fillMaxWidth(),
        )
        ExposedDropdownMenu(expanded = open, onDismissRequest = { open = false }) {
            if (allowNone) DropdownMenuItem(text = { Text(noneLabel) }, onClick = { onSelect(null); open = false })
            choices.forEach { c -> DropdownMenuItem(text = { Text(c.label) }, onClick = { onSelect(c.value); open = false }) }
        }
    }
}

/** Kolom formulir bertumpuk dengan jarak seragam. */
@Composable
fun FormColumn(modifier: Modifier = Modifier, content: @Composable () -> Unit) =
    Column(modifier.fillMaxWidth(), verticalArrangement = Arrangement.spacedBy(8.dp)) { content() }

/** Konfirmasi aksi yang sulit dibatalkan (hapus, batalkan PO, putar QR). */
@Composable
fun ConfirmDialog(title: String, body: String, confirmLabel: String, onConfirm: () -> Unit, onDismiss: () -> Unit, destructive: Boolean = false) {
    AlertDialog(
        onDismissRequest = onDismiss,
        title = { Text(title) },
        text = { Text(body) },
        confirmButton = {
            TextButton(onClick = onConfirm) { Text(confirmLabel, color = if (destructive) MaterialTheme.colorScheme.error else MaterialTheme.colorScheme.primary) }
        },
        dismissButton = { TextButton(onClick = onDismiss) { Text("Batal") } },
    )
}
