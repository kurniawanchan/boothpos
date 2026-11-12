package com.boothpos.customer.feature.account.presentation

import androidx.compose.foundation.layout.Arrangement
import androidx.compose.foundation.layout.Column
import androidx.compose.foundation.layout.fillMaxSize
import androidx.compose.foundation.layout.fillMaxWidth
import androidx.compose.foundation.layout.imePadding
import androidx.compose.foundation.layout.padding
import androidx.compose.foundation.rememberScrollState
import androidx.compose.foundation.text.KeyboardActions
import androidx.compose.foundation.text.KeyboardOptions
import androidx.compose.foundation.verticalScroll
import androidx.compose.runtime.Composable
import androidx.compose.runtime.LaunchedEffect
import androidx.compose.runtime.getValue
import androidx.compose.runtime.rememberUpdatedState
import androidx.compose.ui.Modifier
import androidx.compose.ui.autofill.ContentType
import androidx.compose.ui.platform.LocalSoftwareKeyboardController
import androidx.compose.ui.res.pluralStringResource
import androidx.compose.ui.res.stringResource
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
import com.boothpos.customer.core.data.domain.validation.FieldIssue
import com.boothpos.customer.core.ui.component.AppTextField
import com.boothpos.customer.core.ui.component.InlineMessage
import com.boothpos.customer.core.ui.component.InlineMessageTone
import com.boothpos.customer.core.ui.component.LoadingState
import com.boothpos.customer.core.ui.component.PrimaryButton
import com.boothpos.customer.core.ui.component.apiErrorMessage
import com.boothpos.customer.core.ui.theme.Spacing
import com.boothpos.customer.feature.account.R

/** Bagian stateful layar ubah profil: mengumpulkan state + kejadian, lalu menyerahkannya ke layar stateless. */
@Composable
internal fun EditProfileScreenRoute(
    onSaved: () -> Unit,
    onClose: () -> Unit,
    viewModel: EditProfileViewModel = hiltViewModel(),
) {
    val state by viewModel.uiState.collectAsStateWithLifecycle()
    val currentOnSaved by rememberUpdatedState(onSaved)
    val currentOnClose by rememberUpdatedState(onClose)
    val lifecycleOwner = LocalLifecycleOwner.current
    LaunchedEffect(viewModel, lifecycleOwner) {
        lifecycleOwner.repeatOnLifecycle(Lifecycle.State.STARTED) {
            viewModel.events.collect { event ->
                when (event) {
                    EditProfileEvent.Saved -> currentOnSaved()
                    EditProfileEvent.SessionMissing -> currentOnClose()
                }
            }
        }
    }
    EditProfileScreen(
        state = state,
        onNameChange = viewModel::onNameChange,
        onPhoneChange = viewModel::onPhoneChange,
        onSubmit = viewModel::submit,
    )
}

@Composable
internal fun EditProfileScreen(
    state: EditProfileUiState,
    onNameChange: (String) -> Unit,
    onPhoneChange: (String) -> Unit,
    onSubmit: () -> Unit,
    modifier: Modifier = Modifier,
) {
    when (state) {
        EditProfileUiState.Loading -> LoadingState(modifier = modifier)
        is EditProfileUiState.Form -> EditProfileForm(
            state = state,
            onNameChange = onNameChange,
            onPhoneChange = onPhoneChange,
            onSubmit = onSubmit,
            modifier = modifier,
        )
    }
}

@Composable
private fun EditProfileForm(
    state: EditProfileUiState.Form,
    onNameChange: (String) -> Unit,
    onPhoneChange: (String) -> Unit,
    onSubmit: () -> Unit,
    modifier: Modifier = Modifier,
) {
    val keyboard = LocalSoftwareKeyboardController.current
    val nameLabel = stringResource(R.string.account_field_name)
    val phoneLabel = stringResource(R.string.account_field_phone)

    Column(
        modifier = modifier
            .fillMaxSize()
            .imePadding()
            .verticalScroll(rememberScrollState())
            .padding(Spacing.md),
        verticalArrangement = Arrangement.spacedBy(Spacing.md),
    ) {
        state.formError?.let { error ->
            InlineMessage(text = apiErrorMessage(error), tone = InlineMessageTone.Error)
        }
        AppTextField(
            value = state.name,
            onValueChange = onNameChange,
            label = nameLabel,
            error = profileFieldErrorText(state.nameError, nameLabel),
            enabled = !state.submitting,
            keyboardOptions = KeyboardOptions(
                capitalization = KeyboardCapitalization.Words,
                imeAction = ImeAction.Next,
            ),
            modifier = Modifier.semantics { contentType = ContentType.PersonFullName },
        )
        AppTextField(
            value = state.phone,
            onValueChange = onPhoneChange,
            label = stringResource(R.string.account_field_phone_optional),
            error = profileFieldErrorText(state.phoneError, phoneLabel),
            enabled = !state.submitting,
            keyboardOptions = KeyboardOptions(keyboardType = KeyboardType.Phone, imeAction = ImeAction.Done),
            keyboardActions = KeyboardActions(
                onDone = {
                    keyboard?.hide()
                    onSubmit()
                },
            ),
            modifier = Modifier.semantics { contentType = ContentType.PhoneNumber },
        )
        // E-mail ditampilkan sebagai teks, bukan kolom nonaktif: tetap terbaca jelas (kontras) dan
        // tidak memberi kesan dapat disunting.
        ProfileField(
            label = stringResource(R.string.account_field_email),
            value = state.email,
            helper = stringResource(R.string.account_email_read_only),
        )
        PrimaryButton(
            text = stringResource(R.string.account_action_save),
            onClick = {
                keyboard?.hide()
                onSubmit()
            },
            loading = state.submitting,
            modifier = Modifier.fillMaxWidth(),
        )
    }
}

@Composable
private fun profileFieldErrorText(error: ProfileFieldError?, fieldLabel: String): String? = when (error) {
    null -> null
    is ProfileFieldError.Server -> error.message
    is ProfileFieldError.Local -> when (val issue = error.issue) {
        FieldIssue.Required -> stringResource(R.string.account_error_required, fieldLabel)
        is FieldIssue.TooLong ->
            pluralStringResource(R.plurals.account_error_too_long, issue.max, fieldLabel, issue.max)
        // Nama dan telepon tidak punya aturan format atau panjang minimum (kontrak §5).
        FieldIssue.InvalidFormat, is FieldIssue.TooShort -> stringResource(R.string.account_error_invalid, fieldLabel)
    }
}
