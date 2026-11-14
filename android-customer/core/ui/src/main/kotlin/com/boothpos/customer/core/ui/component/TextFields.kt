package com.boothpos.customer.core.ui.component

import androidx.compose.foundation.interaction.MutableInteractionSource
import androidx.compose.foundation.layout.defaultMinSize
import androidx.compose.foundation.layout.fillMaxWidth
import androidx.compose.foundation.layout.size
import androidx.compose.foundation.text.BasicTextField
import androidx.compose.foundation.text.KeyboardActions
import androidx.compose.foundation.text.KeyboardOptions
import androidx.compose.foundation.text.selection.LocalTextSelectionColors
import androidx.compose.material3.ExperimentalMaterial3Api
import androidx.compose.material3.Icon
import androidx.compose.material3.IconButton
import androidx.compose.material3.LocalTextStyle
import androidx.compose.material3.MaterialTheme
import androidx.compose.material3.OutlinedTextFieldDefaults
import androidx.compose.material3.Text
import androidx.compose.material3.TextFieldDefaults
import androidx.compose.runtime.Composable
import androidx.compose.runtime.CompositionLocalProvider
import androidx.compose.runtime.getValue
import androidx.compose.runtime.mutableStateOf
import androidx.compose.runtime.remember
import androidx.compose.runtime.saveable.rememberSaveable
import androidx.compose.runtime.setValue
import androidx.compose.ui.Modifier
import androidx.compose.ui.graphics.Shape
import androidx.compose.ui.graphics.SolidColor
import androidx.compose.ui.res.painterResource
import androidx.compose.ui.res.stringResource
import androidx.compose.ui.semantics.error
import androidx.compose.ui.semantics.semantics
import androidx.compose.ui.text.input.ImeAction
import androidx.compose.ui.text.input.KeyboardType
import androidx.compose.ui.text.input.PasswordVisualTransformation
import androidx.compose.ui.text.input.VisualTransformation
import androidx.compose.ui.unit.dp
import com.boothpos.customer.core.ui.R
import com.boothpos.customer.core.ui.theme.Dimens

/**
 * Kolom isian baku desain: permukaan putih bersudut 16 dp dengan garis tepi tipis, label kecil di
 * DALAM kolom di atas nilainya. [error] ditampilkan tepat di bawah kolom dan diumumkan pembaca
 * layar sebagai galat kolom itu (bukan hanya warna merah).
 *
 * Disusun dari `BasicTextField` + kotak dekorasi Material 3, persis seperti `TextField` bawaan,
 * hanya wadahnya yang diganti: tata letak "label di dalam" milik kolom terisi digabung dengan
 * garis tepi milik kolom bergaris. Tidak ada komponen Material yang menyediakan gabungan itu.
 */
@OptIn(ExperimentalMaterial3Api::class)
@Composable
fun AppTextField(
    value: String,
    onValueChange: (String) -> Unit,
    label: String,
    modifier: Modifier = Modifier,
    error: String? = null,
    supportingText: String? = null,
    enabled: Boolean = true,
    singleLine: Boolean = true,
    keyboardOptions: KeyboardOptions = KeyboardOptions.Default,
    keyboardActions: KeyboardActions = KeyboardActions.Default,
    visualTransformation: VisualTransformation = VisualTransformation.None,
    leadingIcon: (@Composable () -> Unit)? = null,
    trailingIcon: (@Composable () -> Unit)? = null,
    shape: Shape = MaterialTheme.shapes.medium,
) {
    val scheme = MaterialTheme.colorScheme
    val isError = error != null
    val helper = error ?: supportingText
    val interactionSource = remember { MutableInteractionSource() }
    val colors = OutlinedTextFieldDefaults.colors(
        focusedContainerColor = scheme.surfaceContainerLow,
        unfocusedContainerColor = scheme.surfaceContainerLow,
        disabledContainerColor = scheme.surfaceContainerLow,
        errorContainerColor = scheme.surfaceContainerLow,
        unfocusedBorderColor = scheme.outlineVariant,
        disabledBorderColor = scheme.outlineVariant,
    )
    val textStyle = LocalTextStyle.current.merge(
        MaterialTheme.typography.bodyLarge.copy(
            color = if (enabled) scheme.onSurface else scheme.onSurface.copy(alpha = DISABLED_ALPHA),
        ),
    )
    CompositionLocalProvider(LocalTextSelectionColors provides colors.textSelectionColors) {
        BasicTextField(
            value = value,
            onValueChange = onValueChange,
            modifier = modifier
                .fillMaxWidth()
                .semantics { if (error != null) error(error) }
                .defaultMinSize(minHeight = Dimens.ButtonHeight),
            enabled = enabled,
            textStyle = textStyle,
            cursorBrush = SolidColor(if (isError) scheme.error else scheme.primary),
            visualTransformation = visualTransformation,
            keyboardOptions = keyboardOptions,
            keyboardActions = keyboardActions,
            interactionSource = interactionSource,
            singleLine = singleLine,
            decorationBox = { innerTextField ->
                TextFieldDefaults.DecorationBox(
                    value = value,
                    innerTextField = innerTextField,
                    enabled = enabled,
                    singleLine = singleLine,
                    visualTransformation = visualTransformation,
                    interactionSource = interactionSource,
                    isError = isError,
                    label = { Text(label) },
                    leadingIcon = leadingIcon,
                    trailingIcon = trailingIcon,
                    supportingText = helper?.let { text -> { Text(text) } },
                    shape = shape,
                    colors = colors,
                    container = {
                        OutlinedTextFieldDefaults.Container(
                            enabled = enabled,
                            isError = isError,
                            interactionSource = interactionSource,
                            colors = colors,
                            shape = shape,
                            focusedBorderThickness = BorderThickness,
                            unfocusedBorderThickness = BorderThickness,
                        )
                    },
                )
            },
        )
    }
}

/** Kolom kata sandi dengan tombol tampilkan/sembunyikan. Koreksi otomatis dimatikan. */
@Composable
fun PasswordTextField(
    value: String,
    onValueChange: (String) -> Unit,
    label: String,
    modifier: Modifier = Modifier,
    error: String? = null,
    supportingText: String? = null,
    enabled: Boolean = true,
    imeAction: ImeAction = ImeAction.Done,
    keyboardActions: KeyboardActions = KeyboardActions.Default,
) {
    // Murni keadaan tampilan; tidak disimpan di ViewModel.
    var visible by rememberSaveable { mutableStateOf(false) }
    AppTextField(
        value = value,
        onValueChange = onValueChange,
        label = label,
        modifier = modifier,
        error = error,
        supportingText = supportingText,
        enabled = enabled,
        keyboardOptions = KeyboardOptions(
            keyboardType = KeyboardType.Password,
            autoCorrectEnabled = false,
            imeAction = imeAction,
        ),
        keyboardActions = keyboardActions,
        visualTransformation = if (visible) VisualTransformation.None else PasswordVisualTransformation(),
        trailingIcon = {
            // Ukuran eksplisit: IconButton Material 3 berukuran 40dp di dalam slot kolom isian.
            IconButton(onClick = { visible = !visible }, modifier = Modifier.size(Dimens.MinTouchTarget)) {
                Icon(
                    painter = painterResource(
                        if (visible) R.drawable.core_ui_ic_visibility_off else R.drawable.core_ui_ic_visibility,
                    ),
                    contentDescription = stringResource(
                        if (visible) R.string.core_ui_hide_password else R.string.core_ui_show_password,
                    ),
                )
            }
        },
    )
}

private val BorderThickness = 2.dp
private const val DISABLED_ALPHA = 0.38f
