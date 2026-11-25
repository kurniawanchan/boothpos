package com.warkop.manager.ui.shell

import androidx.lifecycle.ViewModel
import androidx.lifecycle.viewModelScope
import com.warkop.core.domain.ApiError
import com.warkop.core.network.apiCall
import com.warkop.manager.AppContainer
import com.warkop.manager.data.ManagerSession
import com.warkop.manager.data.SessionRepository
import kotlinx.coroutines.flow.MutableStateFlow
import kotlinx.coroutines.flow.SharingStarted
import kotlinx.coroutines.flow.StateFlow
import kotlinx.coroutines.flow.asStateFlow
import kotlinx.coroutines.flow.stateIn
import kotlinx.coroutines.launch

/** Tahap alur awal (T048): server → outlet → login → aplikasi. */
sealed interface Stage {
    data object Server : Stage
    data object Outlet : Stage
    data class Login(val notice: String? = null) : Stage
    data object Restoring : Stage
    data class RestoreFailed(val message: String) : Stage
    data class Ready(val session: ManagerSession, val demo: Boolean) : Stage
}

/**
 * 094 / T048–T049 — menentukan layar akar dari keadaan tersimpan dan menjaga sesi tetap sah: `GET /auth/me` dimuat ulang
 * saat aplikasi kembali ke depan (izin/penanda peran bisa berubah di web — FR-005a), dan mode data server dibandingkan
 * dengan mode outlet terpilih (outlet berbeda per mode; edge case spesifikasi).
 */
class RootViewModel(private val c: AppContainer) : ViewModel() {
    private val _stage = MutableStateFlow(initialStage())
    val stage: StateFlow<Stage> = _stage.asStateFlow()

    /** Mode server berubah selama sesi: dialog yang tak bisa ditutup, lalu pilih outlet ulang. */
    private val _modeChanged = MutableStateFlow<Boolean?>(null)
    val modeChanged: StateFlow<Boolean?> = _modeChanged.asStateFlow()

    val online: StateFlow<Boolean> = c.connectivity.online.stateIn(viewModelScope, SharingStarted.WhileSubscribed(5_000), c.connectivity.isOnline())

    init { if (_stage.value == Stage.Restoring) restore() }

    private fun initialStage(): Stage = when {
        c.profiles.activeBaseUrl() == null -> Stage.Server
        c.session.selectedOutlet() == null -> Stage.Outlet
        c.session.token() == null -> Stage.Login()
        else -> Stage.Restoring
    }

    fun recompute() {
        _modeChanged.value = null
        _stage.value = initialStage()
        if (_stage.value == Stage.Restoring) restore()
    }

    fun onLoggedIn(session: ManagerSession) {
        _stage.value = Stage.Ready(session, c.session.selectedOutlet()?.demo == true)
        checkMode()
    }

    /** Pemulihan sesi tersimpan saat aplikasi dibuka. */
    fun restore() {
        _stage.value = Stage.Restoring
        viewModelScope.launch { refreshSession(initial = true) }
    }

    /** Dipanggil saat aplikasi kembali ke depan. Gagal jaringan dibiarkan diam (data terakhir tetap tampil). */
    fun refreshOnResume() {
        if (_stage.value !is Stage.Ready) return
        viewModelScope.launch { refreshSession(initial = false) }
    }

    private suspend fun refreshSession(initial: Boolean) {
        try {
            val session = c.session.refresh()
            _stage.value = Stage.Ready(session, c.session.selectedOutlet()?.demo == true)
            checkMode()
        } catch (e: ApiError) {
            when {
                SessionRepository.isSessionEnd(e) -> _stage.value = Stage.Login(notice = endNotice(e))
                initial -> _stage.value = Stage.RestoreFailed(e.message)
            }
        }
    }

    private fun checkMode() {
        viewModelScope.launch {
            val serverDemo = runCatching { apiCall { c.api().features() }.systemMode == "demo" }.getOrNull() ?: return@launch
            val outletDemo = c.session.selectedOutlet()?.demo ?: return@launch
            if (serverDemo != outletDemo) _modeChanged.value = serverDemo
        }
    }

    /** Setelah dialog mode: outlet tersimpan milik mode lama, jadi dipilih ulang. */
    fun acknowledgeModeChange() {
        c.session.clearOutlet()
        recompute()
    }

    fun changeOutlet() {
        c.session.clearOutlet()
        recompute()
    }

    fun changeServer() {
        c.session.endSession()
        _stage.value = Stage.Server
    }

    fun logout() {
        viewModelScope.launch {
            c.session.logout()
            _stage.value = Stage.Login()
        }
    }

    /** Sesi berakhir karena aksi di layar mana pun (401 / 403 manager_app_denied). */
    fun sessionEnded(e: ApiError) {
        c.session.endSession()
        _stage.value = Stage.Login(notice = endNotice(e))
    }

    private fun endNotice(e: ApiError) =
        if (e.code == "manager_app_denied") "Akses aplikasi manajemen untuk peran Anda sudah dimatikan. Hubungi pemilik."
        else "Sesi berakhir. Masuk kembali."
}
