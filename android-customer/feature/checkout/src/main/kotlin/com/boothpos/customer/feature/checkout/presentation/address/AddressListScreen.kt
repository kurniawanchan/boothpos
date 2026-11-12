package com.boothpos.customer.feature.checkout.presentation.address

import androidx.compose.foundation.layout.Arrangement
import androidx.compose.foundation.layout.Column
import androidx.compose.foundation.layout.Row
import androidx.compose.foundation.layout.fillMaxSize
import androidx.compose.foundation.layout.fillMaxWidth
import androidx.compose.foundation.layout.heightIn
import androidx.compose.foundation.layout.padding
import androidx.compose.foundation.rememberScrollState
import androidx.compose.foundation.verticalScroll
import androidx.compose.material.icons.Icons
import androidx.compose.material.icons.filled.LocationOn
import androidx.compose.material3.AlertDialog
import androidx.compose.material3.HorizontalDivider
import androidx.compose.material3.LinearProgressIndicator
import androidx.compose.material3.Surface
import androidx.compose.material3.Text
import androidx.compose.material3.TextButton
import androidx.compose.runtime.Composable
import androidx.compose.runtime.LaunchedEffect
import androidx.compose.runtime.getValue
import androidx.compose.ui.Modifier
import androidx.compose.ui.graphics.vector.rememberVectorPainter
import androidx.compose.ui.res.stringResource
import androidx.compose.ui.semantics.contentDescription
import androidx.compose.ui.semantics.semantics
import androidx.hilt.lifecycle.viewmodel.compose.hiltViewModel
import androidx.lifecycle.compose.collectAsStateWithLifecycle
import com.boothpos.customer.core.data.domain.model.Address
import com.boothpos.customer.core.network.error.ApiError
import com.boothpos.customer.core.network.error.ApiErrorCodes
import com.boothpos.customer.core.ui.component.ApiErrorState
import com.boothpos.customer.core.ui.component.EmptyState
import com.boothpos.customer.core.ui.component.InlineMessage
import com.boothpos.customer.core.ui.component.InlineMessageTone
import com.boothpos.customer.core.ui.component.LoadingState
import com.boothpos.customer.core.ui.component.PrimaryButton
import com.boothpos.customer.core.ui.component.apiErrorMessage
import com.boothpos.customer.core.ui.theme.Dimens
import com.boothpos.customer.core.ui.theme.Spacing
import com.boothpos.customer.feature.checkout.R

/** Bagian stateful daftar alamat. Daftar dimuat ulang setiap kali layar tampil lagi. */
@Composable
internal fun AddressListScreenRoute(
    onAddAddress: () -> Unit,
    onEditAddress: (Long) -> Unit,
    viewModel: AddressListViewModel = hiltViewModel(),
) {
    val state by viewModel.uiState.collectAsStateWithLifecycle()
    LaunchedEffect(viewModel) { viewModel.refresh() }
    AddressListScreen(
        state = state,
        onRetry = viewModel::refresh,
        onAddAddress = onAddAddress,
        onEditAddress = onEditAddress,
        onSetDefault = viewModel::setDefault,
        onRequestDelete = viewModel::requestDelete,
        onConfirmDelete = viewModel::confirmDelete,
        onDismissDelete = viewModel::dismissDelete,
    )
}

@Composable
internal fun AddressListScreen(
    state: AddressListUiState,
    onRetry: () -> Unit,
    onAddAddress: () -> Unit,
    onEditAddress: (Long) -> Unit,
    onSetDefault: (Long) -> Unit,
    onRequestDelete: (Long) -> Unit,
    onConfirmDelete: () -> Unit,
    onDismissDelete: () -> Unit,
    modifier: Modifier = Modifier,
) {
    when (state) {
        AddressListUiState.Loading -> LoadingState(modifier = modifier)
        is AddressListUiState.LoadFailed ->
            ApiErrorState(error = state.error, onRetry = onRetry, retrying = state.retrying, modifier = modifier)
        is AddressListUiState.Content -> if (state.addresses.isEmpty() && state.actionError == null) {
            EmptyState(
                title = stringResource(R.string.checkout_address_list_empty_title),
                message = stringResource(R.string.checkout_address_list_empty_message),
                icon = rememberVectorPainter(Icons.Filled.LocationOn),
                actionLabel = stringResource(R.string.checkout_address_add),
                onAction = onAddAddress,
                modifier = modifier,
            )
        } else {
            AddressListContent(
                state = state,
                onRetry = onRetry,
                onAddAddress = onAddAddress,
                onEditAddress = onEditAddress,
                onSetDefault = onSetDefault,
                onRequestDelete = onRequestDelete,
                modifier = modifier,
            )
        }
    }

    (state as? AddressListUiState.Content)?.pendingDelete?.let { address ->
        AlertDialog(
            onDismissRequest = onDismissDelete,
            title = { Text(stringResource(R.string.checkout_address_delete_title)) },
            text = {
                Text(
                    stringResource(
                        R.string.checkout_address_delete_message,
                        address.label?.takeIf { it.isNotBlank() } ?: address.recipientName,
                    ),
                )
            },
            confirmButton = {
                TextButton(onClick = onConfirmDelete, modifier = Modifier.heightIn(min = Dimens.MinTouchTarget)) {
                    Text(stringResource(R.string.checkout_address_delete))
                }
            },
            dismissButton = {
                TextButton(onClick = onDismissDelete, modifier = Modifier.heightIn(min = Dimens.MinTouchTarget)) {
                    Text(stringResource(R.string.checkout_action_cancel))
                }
            },
        )
    }
}

@Composable
private fun AddressListContent(
    state: AddressListUiState.Content,
    onRetry: () -> Unit,
    onAddAddress: () -> Unit,
    onEditAddress: (Long) -> Unit,
    onSetDefault: (Long) -> Unit,
    onRequestDelete: (Long) -> Unit,
    modifier: Modifier = Modifier,
) {
    Column(modifier = modifier.fillMaxSize()) {
        if (state.busy) {
            val busyLabel = stringResource(R.string.checkout_address_updating)
            LinearProgressIndicator(
                modifier = Modifier
                    .fillMaxWidth()
                    .semantics { contentDescription = busyLabel },
            )
        }
        Column(
            modifier = Modifier
                .weight(1f)
                .verticalScroll(rememberScrollState())
                .padding(Spacing.md),
            verticalArrangement = Arrangement.spacedBy(Spacing.sm),
        ) {
            state.actionError?.let { error ->
                InlineMessage(
                    text = addressErrorMessage(error),
                    tone = InlineMessageTone.Error,
                    actionLabel = stringResource(R.string.checkout_address_reload),
                    onAction = onRetry,
                )
            }
            state.addresses.forEach { address ->
                AddressCard(
                    address = address,
                    enabled = !state.busy,
                    onEdit = { onEditAddress(address.id) },
                    onSetDefault = { onSetDefault(address.id) },
                    onDelete = { onRequestDelete(address.id) },
                )
                HorizontalDivider()
            }
        }
        HorizontalDivider()
        Surface {
            PrimaryButton(
                text = stringResource(R.string.checkout_address_add),
                onClick = onAddAddress,
                enabled = !state.busy,
                modifier = Modifier
                    .fillMaxWidth()
                    .padding(horizontal = Spacing.md, vertical = Spacing.sm),
            )
        }
    }
}

@Composable
private fun AddressCard(
    address: Address,
    enabled: Boolean,
    onEdit: () -> Unit,
    onSetDefault: () -> Unit,
    onDelete: () -> Unit,
) {
    val name = address.label?.takeIf { it.isNotBlank() } ?: address.recipientName
    Column {
        AddressText(address)
        if (!address.isDefault) {
            TextButton(
                onClick = onSetDefault,
                enabled = enabled,
                modifier = Modifier.heightIn(min = Dimens.MinTouchTarget),
            ) { Text(stringResource(R.string.checkout_address_set_default)) }
        }
        Row {
            val editLabel = stringResource(R.string.checkout_address_edit_named, name)
            TextButton(
                onClick = onEdit,
                enabled = enabled,
                modifier = Modifier
                    .heightIn(min = Dimens.MinTouchTarget)
                    .semantics { contentDescription = editLabel },
            ) { Text(stringResource(R.string.checkout_address_edit)) }
            val deleteLabel = stringResource(R.string.checkout_address_delete_named, name)
            TextButton(
                onClick = onDelete,
                enabled = enabled,
                modifier = Modifier
                    .heightIn(min = Dimens.MinTouchTarget)
                    .semantics { contentDescription = deleteLabel },
            ) { Text(stringResource(R.string.checkout_address_delete)) }
        }
    }
}

/** Pesan untuk galat alamat; kode khusus §7 punya kalimatnya sendiri, sisanya pesan baku. */
@Composable
internal fun addressErrorMessage(error: ApiError): String {
    val http = error as? ApiError.Http
    return when (http?.code) {
        ApiErrorCodes.ADDRESS_LIMIT_REACHED ->
            http.detail?.takeIf { it.isNotBlank() } ?: stringResource(R.string.checkout_address_limit_reached)
        ApiErrorCodes.NOT_FOUND -> stringResource(R.string.checkout_address_gone)
        else -> apiErrorMessage(error)
    }
}
