package com.boothpos.customer.feature.auth.presentation

import androidx.compose.foundation.layout.fillMaxWidth
import androidx.compose.foundation.text.KeyboardActions
import androidx.compose.foundation.text.KeyboardOptions
import androidx.compose.material3.MaterialTheme
import androidx.compose.material3.Text
import androidx.compose.runtime.Composable
import androidx.compose.runtime.getValue
import androidx.compose.ui.Modifier
import androidx.compose.ui.autofill.ContentType
import androidx.compose.ui.platform.LocalSoftwareKeyboardController
import androidx.compose.ui.res.stringResource
import androidx.compose.ui.semantics.contentType
import androidx.compose.ui.semantics.semantics
import androidx.compose.ui.text.input.ImeAction
import androidx.compose.ui.text.input.KeyboardType
import androidx.hilt.lifecycle.viewmodel.compose.hiltViewModel
import androidx.lifecycle.compose.collectAsStateWithLifecycle
import com.boothpos.customer.core.ui.component.AppTextField
import com.boothpos.customer.core.ui.component.InlineMessage
import com.boothpos.customer.core.ui.component.InlineMessageTone
import com.boothpos.customer.core.ui.component.PasswordTextField
import com.boothpos.customer.core.ui.component.PrimaryButton
import com.boothpos.customer.feature.auth.R

/** Bagian stateful layar masuk: mengumpulkan state + kejadian, lalu menyerahkannya ke layar stateless. */
@Composable
internal fun SignInScreenRoute(
    onAuthenticated: () -> Unit,
    onRegister: () -> Unit,
    viewModel: SignInViewModel = hiltViewModel(),
) {
    val state by viewModel.uiState.collectAsStateWithLifecycle()
    CollectAuthEvents(viewModel.events, onAuthenticated)
    SignInScreen(
        state = state,
        onEmailChange = viewModel::onEmailChange,
        onPasswordChange = viewModel::onPasswordChange,
        onSubmit = viewModel::submit,
        onRegister = onRegister,
    )
}

@Composable
internal fun SignInScreen(
    state: SignInUiState,
    onEmailChange: (String) -> Unit,
    onPasswordChange: (String) -> Unit,
    onSubmit: () -> Unit,
    onRegister: () -> Unit,
    modifier: Modifier = Modifier,
) {
    val keyboard = LocalSoftwareKeyboardController.current
    val emailLabel = stringResource(R.string.auth_field_email)
    val passwordLabel = stringResource(R.string.auth_field_password)

    AuthFormColumn(modifier = modifier) {
        if (state.sessionExpired) {
            InlineMessage(text = stringResource(R.string.auth_session_expired))
        }
        Text(
            text = stringResource(R.string.auth_sign_in_intro),
            style = MaterialTheme.typography.bodyLarge,
            color = MaterialTheme.colorScheme.onSurfaceVariant,
        )
        state.formMessage?.let { message ->
            InlineMessage(text = formMessageText(message), tone = InlineMessageTone.Error)
        }
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
            // Petunjuk isi-otomatis: pengelola kata sandi mengenali pasangan nama pengguna + sandi.
            modifier = Modifier.semantics { contentType = ContentType.Username + ContentType.EmailAddress },
        )
        PasswordTextField(
            value = state.password,
            onValueChange = onPasswordChange,
            label = passwordLabel,
            error = fieldErrorText(state.passwordError, passwordLabel),
            enabled = !state.submitting,
            imeAction = ImeAction.Done,
            keyboardActions = KeyboardActions(
                onDone = {
                    keyboard?.hide()
                    onSubmit()
                },
            ),
            modifier = Modifier.semantics { contentType = ContentType.Password },
        )
        PrimaryButton(
            text = stringResource(R.string.auth_action_sign_in),
            onClick = {
                keyboard?.hide()
                onSubmit()
            },
            loading = state.submitting,
            modifier = Modifier.fillMaxWidth(),
        )
        AuthTextLink(
            text = stringResource(R.string.auth_sign_in_to_register),
            onClick = onRegister,
            enabled = !state.submitting,
        )
        // Kontrak v1 tidak punya alur atur-ulang kata sandi; teks ini jujur soal itu.
        Text(
            text = stringResource(R.string.auth_sign_in_recovery_help),
            style = MaterialTheme.typography.bodyMedium,
            color = MaterialTheme.colorScheme.onSurfaceVariant,
        )
    }
}
