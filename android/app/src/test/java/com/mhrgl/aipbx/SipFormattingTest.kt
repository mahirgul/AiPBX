package com.mhrgl.aipbx

import org.junit.Assert.*
import org.junit.Test

class SipFormattingTest {

    @Test
    fun testCallerNumberCleaningRegex() {
        val regex = Regex("-(mob-webrtc|webrtc|sip)$")
        assertEquals("1001", "1001-mob-webrtc".replace(regex, ""))
        assertEquals("1002", "1002-webrtc".replace(regex, ""))
        assertEquals("1003", "1003-sip".replace(regex, ""))
        assertEquals("05551234567", "05551234567".replace(regex, ""))
        assertEquals("1010", "1010".replace(regex, ""))
    }

    @Test
    fun testSipUsernameFormatting() {
        val ext = "1001"
        val formatted = "${ext}-mob-webrtc"
        assertEquals("1001-mob-webrtc", formatted)
        assertTrue(formatted.endsWith("-mob-webrtc"))
    }

    @Test
    fun testServerUrlSanitization() {
        val raw1 = "  https://santral.example.com/  "
        val clean1 = raw1.trim().trimEnd('/')
        assertEquals("https://santral.example.com", clean1)

        val raw2 = "http://192.168.1.50:8080///"
        val clean2 = raw2.trim().trimEnd('/')
        assertEquals("http://192.168.1.50:8080", clean2)

        assertTrue(clean1.startsWith("https://") || clean1.startsWith("http://"))
    }

    @Test
    fun testDialNumberNormalization() {
        fun normalize(num: String, isTr: Boolean): String {
            var clean = num.replace(Regex("[^0-9*#+]"), "")
            if (clean.startsWith("+90")) {
                clean = "0" + clean.substring(3)
            } else if (isTr && clean.length == 10 && clean.startsWith("5")) {
                clean = "0$clean"
            }
            if (clean.startsWith("+")) {
                clean = "00" + clean.substring(1)
            }
            return clean
        }

        // +90 must always convert to 05xx regardless of isTr
        assertEquals("05321234567", normalize("+905321234567", isTr = false))
        assertEquals("05321234567", normalize("+90 532 123 45 67", isTr = false))
        assertEquals("05321234567", normalize("+905321234567", isTr = true))

        // Local 10-digit number prepends 0 only when isTr is true
        assertEquals("05321234567", normalize("5321234567", isTr = true))
        assertEquals("5321234567", normalize("5321234567", isTr = false))

        // International + prefix converts to 00
        assertEquals("00491711234567", normalize("+491711234567", isTr = false))
        assertEquals("00491711234567", normalize("+491711234567", isTr = true))

        // Normal 0-prefixed number remains untouched
        assertEquals("02121234567", normalize("02121234567", isTr = true))
    }
}
