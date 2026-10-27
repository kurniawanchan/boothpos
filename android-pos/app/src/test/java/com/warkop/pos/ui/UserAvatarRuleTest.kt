package com.warkop.pos.ui

import com.warkop.pos.data.ServerUrl
import com.warkop.pos.model.StaffUser
import org.junit.Assert.assertEquals
import org.junit.Assert.assertNull
import org.junit.Test

/** 077 / US7 — foto bila ada, selain itu inisial; URL foto selalu lewat alamat server yang dipakai tablet. */
class UserAvatarRuleTest {
    @Test fun `foto kosong atau spasi dianggap tidak ada`() {
        assertNull(avatarPhotoUrl(null))
        assertNull(avatarPhotoUrl(""))
        assertNull(avatarPhotoUrl("   "))
        assertEquals("http://10.0.0.5:8010/storage/user-photos/a.jpg", avatarPhotoUrl(" http://10.0.0.5:8010/storage/user-photos/a.jpg "))
    }

    @Test fun `inisial pengganti tidak berubah dari dua huruf pertama nama`() {
        assertEquals("SA", StaffUser("1", "Sari", "Kasir", "CSH").initials)
        assertEquals("BU", StaffUser("2", "budi", "Kasir", "CSH").initials)
    }

    @Test fun `alamat foto dari server dengan host localhost dialihkan ke alamat server tablet`() {
        assertEquals(
            "http://10.0.0.5:8010/storage/user-photos/a.jpg",
            ServerUrl.resolve("http://10.0.0.5:8010", "http://localhost:8010/storage/user-photos/a.jpg"),
        )
        assertNull(ServerUrl.resolve("http://10.0.0.5:8010", null))
    }
}
