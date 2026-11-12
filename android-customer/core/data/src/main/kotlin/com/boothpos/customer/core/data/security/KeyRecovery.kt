package com.boothpos.customer.core.data.security

import java.security.InvalidKeyException
import java.security.KeyStoreException
import java.security.UnrecoverableKeyException

/**
 * Putusan pemulihan kunci enkripsi sesi, dipisah dari Android Keystore supaya dapat diuji di JVM.
 *
 * Kunci Keystore bisa menjadi tak terpakai TANPA kesalahan aplikasi: dibatalkan sistem (mis. setelah
 * pemulihan perangkat atau perubahan kunci layar), entri rusak, atau Keystore menolaknya. Tanpa
 * pemulihan, setiap penyimpanan sesi berikutnya gagal selamanya dan pelanggan tidak pernah bisa
 * masuk lagi selain menghapus data aplikasi.
 *
 * AKIBAT yang diterima dan disengaja: kunci lama dibuang, jadi sesi yang tersimpan dengan kunci itu
 * tidak dapat dibuka lagi (pelanggan masuk ulang). Hanya satu kali percobaan ulang per panggilan.
 */
internal object KeyRecovery {

    /** Kegagalan memakai kunci tersimpan yang boleh dipulihkan dengan membuang kunci lalu membuatnya ulang. */
    fun isRecoverable(error: Throwable): Boolean =
        error is UnrecoverableKeyException || error is KeyStoreException || error is InvalidKeyException

    /**
     * Menjalankan [block]; bila gagal dengan kegagalan yang dapat dipulihkan, memanggil [discardKey]
     * SEKALI lalu menjalankan [block] sekali lagi. Kegagalan lain, atau kegagalan kedua, diteruskan
     * apa adanya (tidak ada putaran tanpa akhir).
     */
    inline fun <T> withRecovery(discardKey: () -> Unit, block: () -> T): T {
        try {
            return block()
        } catch (e: Exception) {
            if (!isRecoverable(e)) throw e
        }
        discardKey()
        return block()
    }
}
