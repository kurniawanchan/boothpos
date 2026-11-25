package com.warkop.manager

import android.app.Application

/** 094 — titik awal proses; satu [AppContainer] berumur aplikasi (pola PosApplication aplikasi kasir, research D3). */
class ManagerApplication : Application() {
    lateinit var container: AppContainer
        private set

    override fun onCreate() {
        super.onCreate()
        container = AppContainer(this)
    }
}
