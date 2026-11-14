package com.boothpos.customer.feature.checkout.presentation.address

import androidx.compose.foundation.layout.Arrangement
import androidx.compose.foundation.layout.Column
import androidx.compose.foundation.layout.FlowRow
import androidx.compose.foundation.layout.PaddingValues
import androidx.compose.foundation.layout.Spacer
import androidx.compose.foundation.layout.fillMaxSize
import androidx.compose.foundation.layout.fillMaxWidth
import androidx.compose.foundation.layout.height
import androidx.compose.foundation.layout.heightIn
import androidx.compose.foundation.layout.padding
import androidx.compose.foundation.rememberScrollState
import androidx.compose.foundation.verticalScroll
import androidx.compose.material3.AlertDialog
import androidx.compose.material3.ButtonDefaults
import androidx.compose.material3.LinearProgressIndicator
import androidx.compose.material3.MaterialTheme
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
import com.boothpos.customer.core.ui.component.AppCard
import com.boothpos.customer.core.ui.component.EmptyState
import com.boothpos.customer.core.ui.component.InlineMessage
import com.boothpos.customer.core.ui.component.InlineMessageTone
import com.boothpos.customer.core.ui.component.LoadingState
import com.boothpos.customer.core.ui.component.PrimaryButton
import com.boothpos.customer.core.ui.component.SecondaryButton
import com.boothpos.customer.core.ui.component.apiErrorMessage
import com.boothpos.customer.core.ui.icon.SkyIcons
import com.boothpos.customer.core.ui.theme.BoothTheme
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
                icon = rememberVectorPainter(SkyIcons.Pin),
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
                // Aksi yang menghapus: warna galat menguatkan teks "Hapus", bukan menggantikannya.
                TextButton(
                    onClick = onConfirmDelete,
                    colors = ButtonDefaults.textButtonColors(contentColor = MaterialTheme.colorScheme.error),
                    modifier = Modifier.heightIn(min = Dimens.MinTouchTarget),
                ) {
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
                color = BoothTheme.sky.progress,
                trackColor = BoothTheme.sky.progressTrack,
            )
        }
        Column(
            modifier = Modifier
                .weight(1f)
                .fillMaxWidth()
                .verticalScroll(rememberScrollState())
                .padding(start = Spacing.lg, end = Spacing.lg, top = Spacing.sm, bottom = Spacing.lg),
            verticalArrangement = Arrangement.spacedBy(Spacing.ms),
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
            }
        }
        // Tanpa Surface: Surface memotong isinya, sehingga pendar tombol utama ikut terpotong.
        PrimaryButton(
            text = stringResource(R.string.checkout_address_add),
            onClick = onAddAddress,
            enabled = !state.busy,
            icon = SkyIcons.Plus,
            modifier = Modifier
                .fillMaxWidth()
                .padding(horizontal = Spacing.lg, vertical = Spacing.md),
        )
    }
}

/** Satu alamat tersimpan sebagai kartu: isi alamat, lalu aksinya sebagai tombol sekunder dan tautan. */
@Composable
private fun AddressCard(
    address: Address,
    enabled: Boolean,
    onEdit: () -> Unit,
    onSetDefault: () -> Unit,
    onDelete: () -> Unit,
) {
    val name = address.label?.takeIf { it.isNotBlank() } ?: address.recipientName
    AppCard(
        modifier = Modifier.fillMaxWidth(),
        // Baris tautan di dasar kartu sudah membawa ruang kosongnya sendiri (tinggi sentuh 48 dp).
        contentPadding = PaddingValues(start = Spacing.md, top = Spacing.md, end = Spacing.md, bottom = Spacing.xs),
    ) {
        AddressText(address)
        if (!address.isDefault) {
            Spacer(Modifier.height(Spacing.sm))
            SecondaryButton(
                text = stringResource(R.string.checkout_address_set_default),
                onClick = onSetDefault,
                enabled = enabled,
            )
        }
        // FlowRow: dengan huruf besar "Hapus" turun ke baris berikutnya alih-alih terpotong.
        FlowRow(horizontalArrangement = Arrangement.spacedBy(Spacing.sm)) {
            val editLabel = stringResource(R.string.checkout_address_edit_named, name)
            CardLink(
                text = stringResource(R.string.checkout_address_edit),
                onClick = onEdit,
                enabled = enabled,
                icon = SkyIcons.Edit,
                modifier = Modifier.semantics { contentDescription = editLabel },
            )
            val deleteLabel = stringResource(R.string.checkout_address_delete_named, name)
            // Aksi yang menghapus: warna galat menguatkan ikon + teksnya, bukan menggantikannya.
            CardLink(
                text = stringResource(R.string.checkout_address_delete),
                onClick = onDelete,
                enabled = enabled,
                icon = SkyIcons.Trash,
                contentColor = MaterialTheme.colorScheme.error,
                modifier = Modifier.semantics { contentDescription = deleteLabel },
            )
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
