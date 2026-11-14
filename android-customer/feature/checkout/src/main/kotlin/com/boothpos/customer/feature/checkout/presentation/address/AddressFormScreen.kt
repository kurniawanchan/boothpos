package com.boothpos.customer.feature.checkout.presentation.address

import androidx.compose.foundation.layout.Arrangement
import androidx.compose.foundation.layout.Column
import androidx.compose.foundation.layout.Row
import androidx.compose.foundation.layout.Spacer
import androidx.compose.foundation.layout.fillMaxSize
import androidx.compose.foundation.layout.fillMaxWidth
import androidx.compose.foundation.layout.height
import androidx.compose.foundation.layout.imePadding
import androidx.compose.foundation.layout.padding
import androidx.compose.foundation.layout.width
import androidx.compose.foundation.rememberScrollState
import androidx.compose.foundation.selection.toggleable
import androidx.compose.foundation.text.KeyboardOptions
import androidx.compose.foundation.verticalScroll
import androidx.compose.material3.Checkbox
import androidx.compose.material3.CheckboxDefaults
import androidx.compose.material3.MaterialTheme
import androidx.compose.material3.Text
import androidx.compose.runtime.Composable
import androidx.compose.runtime.LaunchedEffect
import androidx.compose.runtime.getValue
import androidx.compose.runtime.rememberUpdatedState
import androidx.compose.ui.Alignment
import androidx.compose.ui.Modifier
import androidx.compose.ui.autofill.ContentType
import androidx.compose.ui.graphics.vector.rememberVectorPainter
import androidx.compose.ui.platform.LocalSoftwareKeyboardController
import androidx.compose.ui.res.stringResource
import androidx.compose.ui.semantics.Role
import androidx.compose.ui.semantics.contentType
import androidx.compose.ui.semantics.semantics
import androidx.compose.ui.text.input.ImeAction
import androidx.compose.ui.text.input.KeyboardCapitalization
import androidx.compose.ui.text.input.KeyboardType
import androidx.hilt.lifecycle.viewmodel.compose.hiltViewModel
import androidx.lifecycle.Lifecycle
import androidx.lifecycle.compose.LocalLifecycleOwner
import androidx.lifecycle.compose.collectAsStateWithLifecycle
import androidx.lifecycle.repeatOnLifecycle
import com.boothpos.customer.core.ui.component.ApiErrorState
import com.boothpos.customer.core.ui.component.AppCard
import com.boothpos.customer.core.ui.component.AppTextField
import com.boothpos.customer.core.ui.component.EmptyState
import com.boothpos.customer.core.ui.component.InlineMessage
import com.boothpos.customer.core.ui.component.InlineMessageTone
import com.boothpos.customer.core.ui.component.LoadingState
import com.boothpos.customer.core.ui.component.PrimaryButton
import com.boothpos.customer.core.ui.icon.SkyIcons
import com.boothpos.customer.core.ui.theme.Spacing
import com.boothpos.customer.feature.checkout.R
import com.boothpos.customer.feature.checkout.domain.AddressField
import com.boothpos.customer.feature.checkout.domain.AddressRules

/** Bagian stateful formulir alamat: setelah tersimpan, formulir ditutup dan layar asal memuat ulang daftarnya. */
@Composable
internal fun AddressFormScreenRoute(onClose: () -> Unit, viewModel: AddressFormViewModel = hiltViewModel()) {
    val state by viewModel.uiState.collectAsStateWithLifecycle()
    val currentOnClose by rememberUpdatedState(onClose)
    val lifecycleOwner = LocalLifecycleOwner.current
    LaunchedEffect(viewModel, lifecycleOwner) {
        lifecycleOwner.repeatOnLifecycle(Lifecycle.State.STARTED) {
            viewModel.events.collect { event ->
                when (event) {
                    AddressFormEvent.Saved -> currentOnClose()
                }
            }
        }
    }
    AddressFormScreen(
        state = state,
        onFieldChange = viewModel::onFieldChange,
        onDefaultChange = viewModel::onDefaultChange,
        onSubmit = viewModel::submit,
        onRetry = viewModel::retryLoad,
        onClose = onClose,
    )
}

@Composable
internal fun AddressFormScreen(
    state: AddressFormUiState,
    onFieldChange: (AddressField, String) -> Unit,
    onDefaultChange: (Boolean) -> Unit,
    onSubmit: () -> Unit,
    onRetry: () -> Unit,
    onClose: () -> Unit,
    modifier: Modifier = Modifier,
) {
    when (state) {
        AddressFormUiState.Loading -> LoadingState(modifier = modifier)
        is AddressFormUiState.LoadFailed ->
            ApiErrorState(error = state.error, onRetry = onRetry, retrying = state.retrying, modifier = modifier)
        AddressFormUiState.NotFound -> EmptyState(
            title = stringResource(R.string.checkout_address_not_found_title),
            message = stringResource(R.string.checkout_address_gone),
            icon = rememberVectorPainter(SkyIcons.Info),
            actionLabel = stringResource(R.string.checkout_address_not_found_action),
            onAction = onClose,
            modifier = modifier,
        )
        is AddressFormUiState.Form -> AddressForm(
            state = state,
            onFieldChange = onFieldChange,
            onDefaultChange = onDefaultChange,
            onSubmit = onSubmit,
            modifier = modifier,
        )
    }
}

@Composable
private fun AddressForm(
    state: AddressFormUiState.Form,
    onFieldChange: (AddressField, String) -> Unit,
    onDefaultChange: (Boolean) -> Unit,
    onSubmit: () -> Unit,
    modifier: Modifier = Modifier,
) {
    val keyboard = LocalSoftwareKeyboardController.current
    Column(
        modifier = modifier
            .fillMaxSize()
            .imePadding()
            .verticalScroll(rememberScrollState())
            // Bantalan bawah lebih longgar: ruang bagi pendar tombol utama di ujung gulungan.
            .padding(start = Spacing.lg, end = Spacing.lg, top = Spacing.sm, bottom = Spacing.xl),
        verticalArrangement = Arrangement.spacedBy(Spacing.sm),
    ) {
        state.formError?.let { error ->
            InlineMessage(text = addressErrorMessage(error), tone = InlineMessageTone.Error)
        }
        AddressField.entries.forEach { field ->
            val label = stringResource(field.labelRes())
            AppTextField(
                value = state.draft.valueOf(field),
                onValueChange = { onFieldChange(field, it) },
                label = if (AddressRules.isRequired(field)) label else stringResource(R.string.checkout_field_optional, label),
                error = when (val error = state.errors[field]) {
                    null -> null
                    is AddressFieldError.Server -> error.message
                    is AddressFieldError.Local -> fieldIssueText(error.issue, label)
                },
                supportingText = field.hintRes()?.let { stringResource(it) },
                enabled = !state.submitting,
                singleLine = field != AddressField.AddressLine && field != AddressField.Notes,
                keyboardOptions = field.keyboardOptions(),
                modifier = field.contentType()?.let { type -> Modifier.semantics { contentType = type } } ?: Modifier,
            )
        }
        DefaultToggle(state = state, onDefaultChange = onDefaultChange)
        Spacer(Modifier.height(Spacing.sm))
        PrimaryButton(
            text = stringResource(R.string.checkout_address_save),
            onClick = {
                keyboard?.hide()
                onSubmit()
            },
            loading = state.submitting,
            modifier = Modifier.fillMaxWidth(),
        )
    }
}

/**
 * Seluruh kartu adalah target sentuhnya; kotak centang sendiri tidak menerima ketukan terpisah.
 * Keadaan tercentang dibawa semantik `toggleable` kartunya, bukan warnanya.
 */
@Composable
private fun DefaultToggle(state: AddressFormUiState.Form, onDefaultChange: (Boolean) -> Unit) {
    val enabled = !state.defaultLocked && !state.submitting
    val scheme = MaterialTheme.colorScheme
    AppCard(
        modifier = Modifier.fillMaxWidth(),
        interaction = Modifier.toggleable(
            value = state.draft.isDefault,
            enabled = enabled,
            role = Role.Checkbox,
            onValueChange = onDefaultChange,
        ),
    ) {
        Row(verticalAlignment = Alignment.CenterVertically) {
            Checkbox(
                checked = state.draft.isDefault,
                onCheckedChange = null,
                enabled = enabled,
                // Kuning aksi desain, sama dengan tanda pilihan di kartu pilihan checkout.
                colors = CheckboxDefaults.colors(
                    checkedColor = scheme.primaryContainer,
                    checkmarkColor = scheme.onPrimaryContainer,
                ),
            )
            Spacer(Modifier.width(Spacing.ms))
            Column(modifier = Modifier.weight(1f)) {
                Text(stringResource(R.string.checkout_address_default_toggle), style = MaterialTheme.typography.titleMedium)
                Text(
                    text = stringResource(
                        if (state.defaultLocked) R.string.checkout_address_default_locked else R.string.checkout_address_default_hint,
                    ),
                    style = MaterialTheme.typography.bodySmall,
                    color = scheme.onSurfaceVariant,
                )
            }
        }
    }
}

private fun AddressField.labelRes(): Int = when (this) {
    AddressField.Label -> R.string.checkout_address_field_label
    AddressField.RecipientName -> R.string.checkout_address_field_recipient
    AddressField.Phone -> R.string.checkout_address_field_phone
    AddressField.AddressLine -> R.string.checkout_address_field_line
    AddressField.City -> R.string.checkout_address_field_city
    AddressField.Province -> R.string.checkout_address_field_province
    AddressField.PostalCode -> R.string.checkout_address_field_postal
    AddressField.Notes -> R.string.checkout_address_field_notes
}

private fun AddressField.hintRes(): Int? = when (this) {
    AddressField.Label -> R.string.checkout_address_field_label_hint
    AddressField.Notes -> R.string.checkout_address_field_notes_hint
    else -> null
}

private fun AddressField.keyboardOptions(): KeyboardOptions = when (this) {
    AddressField.Phone -> KeyboardOptions(keyboardType = KeyboardType.Phone, imeAction = ImeAction.Next)
    AddressField.PostalCode -> KeyboardOptions(keyboardType = KeyboardType.Number, imeAction = ImeAction.Next)
    AddressField.RecipientName, AddressField.City, AddressField.Province ->
        KeyboardOptions(capitalization = KeyboardCapitalization.Words, imeAction = ImeAction.Next)
    AddressField.Label -> KeyboardOptions(capitalization = KeyboardCapitalization.Sentences, imeAction = ImeAction.Next)
    // Kolom multibaris: tombol Enter membuat baris baru.
    AddressField.AddressLine, AddressField.Notes -> KeyboardOptions(capitalization = KeyboardCapitalization.Sentences)
}

private fun AddressField.contentType(): ContentType? = when (this) {
    AddressField.RecipientName -> ContentType.PersonFullName
    AddressField.Phone -> ContentType.PhoneNumber
    AddressField.AddressLine -> ContentType.AddressStreet
    AddressField.City -> ContentType.AddressLocality
    AddressField.Province -> ContentType.AddressRegion
    AddressField.PostalCode -> ContentType.PostalCode
    AddressField.Label, AddressField.Notes -> null
}
