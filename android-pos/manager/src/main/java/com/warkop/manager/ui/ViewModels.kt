package com.warkop.manager.ui

import androidx.compose.runtime.Composable
import androidx.compose.ui.platform.LocalContext
import androidx.lifecycle.ViewModel
import androidx.lifecycle.viewmodel.CreationExtras
import androidx.lifecycle.viewmodel.compose.viewModel
import androidx.lifecycle.viewmodel.initializer
import androidx.lifecycle.viewmodel.viewModelFactory
import com.warkop.manager.AppContainer
import com.warkop.manager.ManagerApplication

/** Kontainer aplikasi dari Compose (research D3: tanpa framework DI). */
@Composable
fun container(): AppContainer = (LocalContext.current.applicationContext as ManagerApplication).container

/**
 * ViewModel yang dibangun dari kontainer; `key` membedakan instans per layar detail (mis. id produk). Penerima
 * [CreationExtras] memberi `createSavedStateHandle()` untuk isian yang harus bertahan saat proses dibuat ulang (FR-029).
 */
@Composable
inline fun <reified VM : ViewModel> managerViewModel(key: String? = null, crossinline create: CreationExtras.(AppContainer) -> VM): VM {
    val c = container()
    return viewModel(key = key, factory = viewModelFactory { initializer { create(c) } })
}
