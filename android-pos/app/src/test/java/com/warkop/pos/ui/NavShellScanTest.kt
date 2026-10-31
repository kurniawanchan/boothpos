package com.warkop.pos.ui

import com.warkop.pos.state.Dest
import org.junit.Assert.assertTrue
import org.junit.Test
import java.io.File

/**
 * BUG YANG DITEMUKAN & DIPERBAIKI (2026-11-01, laporan pemilik "tidak bisa kembali"): layar "Stok Bahan" dibuka tanpa
 * `PosShell`, jadi tampil layar penuh tanpa rail/bilah navigasi dan tidak ada jalan keluar. Setiap tujuan menu
 * ([NAV_SPECS]) harus dirender di dalam `PosShell` — baik oleh layarnya sendiri maupun oleh PosApp.
 */
class NavShellScanTest {
    private val root = listOf("src/main/java/com/warkop/pos", "app/src/main/java/com/warkop/pos").map(::File).first { it.isDirectory }

    /** Composable layar untuk tiap tujuan menu (PosApp memetakan Dest → composable ini). */
    private val screens = mapOf(
        Dest.EDITOR to "EditorScreen", Dest.TABLES to "TablesScreen", Dest.ORDERS to "OrdersScreen",
        Dest.SHIFT to "ShiftHubScreen", Dest.MATERIALS to "MaterialsScreen", Dest.SYNC to "SyncScreen",
    )

    @Test fun `setiap tujuan menu dirender di dalam PosShell`() {
        val app = File(root, "ui/PosApp.kt").readText()
        val sources = File(root, "ui/screens").listFiles()!!.associate { it.name to it.readText() }
        val missing = NAV_SPECS.map { it.dest }.filter { dest ->
            val fn = screens[dest] ?: return@filter true
            val wrappedByApp = Regex("Dest\\.${dest.name} -> PosShell\\(").containsMatchIn(app)
            val body = sources.values.firstNotNullOfOrNull { src ->
                val start = src.indexOf("fun $fn(").takeIf { it >= 0 } ?: return@firstNotNullOfOrNull null
                val next = src.indexOf("\n@Composable", start + 1).let { if (it < 0) src.length else it }
                src.substring(start, next)
            }
            !wrappedByApp && (body == null || "PosShell(" !in body)
        }
        assertTrue("Tujuan menu tanpa PosShell (tidak ada navigasi keluar): $missing", missing.isEmpty())
    }
}
