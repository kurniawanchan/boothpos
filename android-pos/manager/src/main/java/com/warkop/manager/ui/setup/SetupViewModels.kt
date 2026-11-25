package com.warkop.manager.ui.setup

import androidx.lifecycle.ViewModel
import androidx.lifecycle.viewModelScope
import com.warkop.core.domain.ApiError
import com.warkop.core.network.apiCall
import com.warkop.manager.AppContainer
import com.warkop.manager.data.SelectedOutlet
import com.warkop.manager.data.ServerKind
import com.warkop.manager.data.ManagerSession
import com.warkop.manager.domain.FormStatus
import com.warkop.manager.ui.components.Load
import kotlinx.coroutines.flow.MutableStateFlow
import kotlinx.coroutines.flow.StateFlow
import kotlinx.coroutines.flow.asStateFlow
import kotlinx.coroutines.flow.update
import kotlinx.coroutines.launch

/** 094 / T045 — layar Pengaturan server: dua profil, uji sambungan, ambil alamat pilihan admin. */
class ServerSettingsViewModel(private val c: AppContainer, defaults: Pair<String, String>) : ViewModel() {
    data class State(
        val local: String,
        val online: String,
        val active: ServerKind,
        val testing: Boolean = false,
        val result: String? = null,
        val resultOk: Boolean = false,
    )

    private val _state = MutableStateFlow(
        State(
            local = c.profiles.profile(ServerKind.LOCAL).url ?: defaults.first,
            online = c.profiles.profile(ServerKind.ONLINE).url ?: defaults.second,
            active = c.profiles.active,
        ),
    )
    val state: StateFlow<State> = _state.asStateFlow()

    fun setLocal(v: String) = _state.update { it.copy(local = v, result = null) }
    fun setOnline(v: String) = _state.update { it.copy(online = v, result = null) }
    fun setActive(k: ServerKind) = _state.update { it.copy(active = k, result = null) }

    private fun activeUrl(s: State) = if (s.active == ServerKind.LOCAL) s.local else s.online

    /** Uji alamat profil terpilih lewat `GET /pos-setup/branding`; berhasil = disimpan dan diaktifkan. */
    fun testAndSave(onSaved: () -> Unit) {
        val s = _state.value
        val url = activeUrl(s).trim()
        if (url.isEmpty()) { _state.update { it.copy(result = "Isi alamat server dulu.", resultOk = false) }; return }
        _state.update { it.copy(testing = true, result = null) }
        viewModelScope.launch {
            try {
                val branding = apiCall { c.probeApi(url).branding() }
                c.profiles.save(ServerKind.LOCAL, s.local)
                c.profiles.save(ServerKind.ONLINE, s.online)
                c.profiles.activate(s.active)
                val mode = if (branding.systemMode == "demo") "DEMO" else "LIVE"
                _state.update { it.copy(testing = false, resultOk = true, result = "Tersambung ke ${branding.storeName ?: "server Warkop WJM"} ($mode).") }
                onSaved()
            } catch (e: ApiError) {
                val msg = if (e is ApiError.NotFound) "Alamat ini bukan server Warkop WJM atau versinya terlalu lama." else "Server tidak dapat dihubungi. ${e.message}"
                _state.update { it.copy(testing = false, resultOk = false, result = msg) }
            }
        }
    }

    /** Isi alamat dari pilihan admin di web (`GET /server-address`, tanpa token) memakai alamat lokal yang sudah diketik. */
    fun fetchFromServer() {
        val base = _state.value.local.trim().ifEmpty { return }
        _state.update { it.copy(testing = true, result = null) }
        viewModelScope.launch {
            try {
                val addr = apiCall { c.probeApi(base).serverAddress() }
                _state.update {
                    val online = if (addr.selected == "online" && !addr.url.isNullOrBlank()) addr.url!! else it.online
                    it.copy(testing = false, online = online, resultOk = true, result = "Alamat pilihan admin: ${if (addr.selected == "online") "Online" else "Lokal"}.")
                }
            } catch (e: ApiError) {
                _state.update { it.copy(testing = false, resultOk = false, result = "Gagal mengambil alamat dari server. ${e.message}") }
            }
        }
    }
}

/** 094 / T046 — pilih outlet sebelum login (FR-003). */
class OutletPickerViewModel(private val c: AppContainer) : ViewModel() {
    data class OutletRow(val id: Long, val name: String)

    private val _state = MutableStateFlow<Load<List<OutletRow>>>(Load.Loading)
    val state: StateFlow<Load<List<OutletRow>>> = _state.asStateFlow()
    val selectedId: Long? get() = c.session.selectedOutlet()?.id

    init { load() }

    fun load() {
        val current = _state.value
        _state.value = if (current is Load.Ready) current.copy(refreshing = true) else Load.Loading
        viewModelScope.launch {
            _state.value = try {
                Load.Ready(c.session.outlets().map { OutletRow(it.id, it.name) })
            } catch (e: ApiError) {
                Load.Failed(e.message)
            }
        }
    }

    fun choose(row: OutletRow, onChosen: () -> Unit) {
        viewModelScope.launch {
            c.session.selectOutlet(SelectedOutlet(row.id, row.name, demo = c.session.outletIsDemo(row.id)))
            onChosen()
        }
    }
}

/** 094 / T047 — login username + kata sandi; kata sandi hanya di memori ViewModel (tidak di state tersimpan). */
class LoginViewModel(private val c: AppContainer) : ViewModel() {
    data class State(val username: String = "", val password: String = "", val status: FormStatus = FormStatus())

    private val _state = MutableStateFlow(State())
    val state: StateFlow<State> = _state.asStateFlow()

    val outlet: SelectedOutlet? get() = c.session.selectedOutlet()
    val insecureOnline: Boolean get() = c.profiles.activeProfile().insecureOnline

    fun setUsername(v: String) = _state.update { it.copy(username = v, status = it.status.copy(fieldErrors = it.status.fieldErrors - "username", message = null)) }
    fun setPassword(v: String) = _state.update { it.copy(password = v, status = it.status.copy(fieldErrors = it.status.fieldErrors - "password", message = null)) }

    fun submit(onSuccess: (ManagerSession) -> Unit) {
        val s = _state.value
        if (s.status.saving) return
        val missing = buildMap {
            if (s.username.isBlank()) put("username", "Wajib diisi.")
            if (s.password.isEmpty()) put("password", "Wajib diisi.")
        }
        if (missing.isNotEmpty()) { _state.update { it.copy(status = FormStatus(fieldErrors = missing)) }; return }
        _state.update { it.copy(status = FormStatus.saving()) }
        viewModelScope.launch {
            try {
                val session = c.session.login(s.username, s.password)
                _state.update { State() }
                onSuccess(session)
            } catch (e: ApiError) {
                _state.update { it.copy(password = "", status = loginStatus(e)) }
            }
        }
    }

    companion object {
        /** Pesan login per kode server (kontrak A1); 429 memakai pesan server yang sudah memuat lama tunggu. */
        fun loginStatus(e: ApiError): FormStatus = when {
            e is ApiError.Forbidden && e.code == "manager_app_denied" -> FormStatus(message = "Akun ini tidak punya akses ke aplikasi manajemen. Minta pemilik mengaktifkannya di Pengaturan → Peran.")
            e is ApiError.Forbidden && e.code == "outlet_not_permitted" -> FormStatus(message = "Akun ini tidak terdaftar di outlet yang dipilih. Pilih outlet lain.")
            else -> FormStatus.from(e)
        }
    }
}
