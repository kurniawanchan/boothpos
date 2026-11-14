package com.boothpos.customer.feature.auth.presentation

import androidx.compose.foundation.layout.fillMaxWidth
import androidx.compose.foundation.layout.padding
import androidx.compose.foundation.text.KeyboardActions
import androidx.compose.foundation.text.KeyboardOptions
import androidx.compose.material3.MaterialTheme
import androidx.compose.material3.Text
import androidx.compose.runtime.Composable
import androidx.compose.runtime.getValue
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
import androidx.lifecycle.compose.collectAsStateWithLifecycle
import com.boothpos.customer.core.ui.component.AppTextField
import com.boothpos.customer.core.ui.component.InlineMessage
import com.boothpos.customer.core.ui.component.InlineMessageTone
import com.boothpos.customer.core.ui.component.PasswordTextField
import com.boothpos.customer.core.ui.component.PrimaryButton
import com.boothpos.customer.core.ui.theme.Spacing
import com.boothpos.customer.feature.auth.R
import com.boothpos.customer.feature.auth.domain.CredentialRules

/** Bagian stateful layar daftar: mengumpulkan state + kejadian, lalu menyerahkannya ke layar stateless. */
@Composable
internal fun RegisterScreenRoute(
    onAuthenticated: () -> Unit,
    onSignIn: () -> Unit,
    viewModel: RegisterViewModel = hiltViewModel(),
) {
    val state by viewModel.uiState.collectAsStateWithLifecycle()
    CollectAuthEvents(viewModel.events, onAuthenticated)
    RegisterScreen(
        state = state,
        onNameChange = viewModel::onNameChange,
        onEmailChange = viewModel::onEmailChange,
        onPhoneChange = viewModel::onPhoneChange,
        onPasswordChange = viewModel::onPasswordChange,
        onSubmit = viewModel::submit,
        onSignIn = onSignIn,
    )
}

@Composable
internal fun RegisterScreen(
    state: RegisterUiState,
    onNameChange: (String) -> Unit,
    onEmailChange: (String) -> Unit,
    onPhoneChange: (String) -> Unit,
    onPasswordChange: (String) -> Unit,
    onSubmit: () -> Unit,
    onSignIn: () -> Unit,
    modifier: Modifier = Modifier,
) {
    val keyboard = LocalSoftwareKeyboardController.current
    val nameLabel = stringResource(R.string.auth_field_name)
    val emailLabel = stringResource(R.string.auth_field_email)
    val phoneLabel = stringResource(R.string.auth_field_phone)
    val passwordLabel = stringResource(R.string.auth_field_password)

    AuthFormColumn(
        modifier = modifier,
        actions = {
            PrimaryButton(
                text = stringResource(R.string.auth_action_register),
                onClick = {
                    keyboard?.hide()
                    onSubmit()
                },
                loading = state.submitting,
                modifier = Modifier.fillMaxWidth(),
            )
            AuthTextLink(
                text = stringResource(R.string.auth_register_to_sign_in),
                onClick = onSignIn,
                enabled = !state.submitting,
            )
        },
    ) {
        Text(
            text = stringResource(R.string.auth_register_intro),
            style = MaterialTheme.typography.bodyLarge,
            color = MaterialTheme.colorScheme.onSurface,
            // Desain: 24 dp ke kolom pertama = 16 dp di sini + 8 dp jarak antar-isian kerangka formulir.
            modifier = Modifier.padding(bottom = Spacing.md),
        )
        state.formMessage?.let { message ->
            InlineMessage(text = formMessageText(message), tone = InlineMessageTone.Error)
        }
        AppTextField(
            value = state.name,
            onValueChange = onNameChange,
            label = nameLabel,
            error = fieldErrorText(state.nameError, nameLabel),
            enabled = !state.submitting,
            keyboardOptions = KeyboardOptions(
                capitalization = KeyboardCapitalization.Words,
                imeAction = ImeAction.Next,
            ),
            modifier = Modifier.semantics { contentType = ContentType.PersonFullName },
        )
        AppTextField(
            value = state.email,
            onValueChange = onEmailChange,
            label = emailLabel,
            error = fieldErrorText(state.emailError, emailLabel),
            enabled = !state.submitting,
            keyboardOptions = KeyboardOptions(
                keyboardType = KeyboardType.Email,
                autoCorrectEnabled = false,
                imeAction = ImeAction.Next,
            ),
            modifier = Modifier.semantics { contentType = ContentType.NewUsername + ContentType.EmailAddress },
        )
        AppTextField(
            value = state.phone,
            onValueChange = onPhoneChange,
            // Label kolomnya sendiri menyebut "opsional"; galatnya memakai nama kolom tanpa keterangan itu.
            label = stringResource(R.string.auth_field_phone_optional),
            error = fieldErrorText(state.phoneError, phoneLabel),
            enabled = !state.submitting,
            keyboardOptions = KeyboardOptions(keyboardType = KeyboardType.Phone, imeAction = ImeAction.Next),
            modifier = Modifier.semantics { contentType = ContentType.PhoneNumber },
        )
        PasswordTextField(
            value = state.password,
            onValueChange = onPasswordChange,
            label = passwordLabel,
            error = fieldErrorText(state.passwordError, passwordLabel),
            supportingText = pluralStringResource(
                R.plurals.auth_password_hint,
                CredentialRules.PASSWORD_MIN,
                CredentialRules.PASSWORD_MIN,
            ),
            enabled = !state.submitting,
            imeAction = ImeAction.Done,
            keyboardActions = KeyboardActions(
                onDone = {
                    keyboard?.hide()
                    onSubmit()
                },
            ),
            modifier = Modifier.semantics { contentType = ContentType.NewPassword },
        )
    }
}
