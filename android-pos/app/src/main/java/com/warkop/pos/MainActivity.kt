package com.warkop.pos

import android.os.Bundle
import androidx.activity.ComponentActivity
import androidx.activity.compose.setContent
import androidx.activity.enableEdgeToEdge
import android.graphics.Color
import androidx.activity.SystemBarStyle
import androidx.activity.viewModels
import androidx.lifecycle.ViewModel
import androidx.lifecycle.ViewModelProvider
import com.warkop.pos.state.PosStore
import com.warkop.pos.ui.PosApp
import com.warkop.pos.ui.autoDark

/** Satu activity; seluruh navigasi dipegang PosStore (lihat state/PosStore.kt) mengikuti prototipe desain. */
class MainActivity : ComponentActivity() {
    private val store: PosStore by viewModels {
        object : ViewModelProvider.Factory {
            @Suppress("UNCHECKED_CAST")
            override fun <T : ViewModel> create(modelClass: Class<T>): T {
                val container = (application as PosApplication).container
                return PosStore(container.backend, container.backend, container.printer) as T
            }
        }
    }

    // 048 / US6 — heartbeat hanya saat aplikasi di latar depan.
    override fun onStart() {
        super.onStart()
        store.startHeartbeat()
    }

    override fun onStop() {
        store.stopHeartbeat()
        super.onStop()
    }

    override fun onCreate(savedInstanceState: Bundle?) {
        super.onCreate(savedInstanceState)
        // Ikon bilah status mengikuti tema aplikasi (terang/gelap otomatis malam hari), bukan tema sistem.
        val dark = autoDark() == true
        val style = if (dark) SystemBarStyle.dark(Color.TRANSPARENT) else SystemBarStyle.light(Color.TRANSPARENT, Color.TRANSPARENT)
        enableEdgeToEdge(statusBarStyle = style, navigationBarStyle = style)
        // Pembandingan visual dengan desain: hanya pada build debuggable (`adb shell am start ... --es preset 12`).
        val debuggable = (applicationInfo.flags and android.content.pm.ApplicationInfo.FLAG_DEBUGGABLE) != 0
        val preset = if (debuggable) intent.getStringExtra("preset") else null
        setContent { PosApp(store, preset = preset) }
    }
}
