package com.mhrgl.aipbx

import com.mhrgl.aipbx.util.SearchUtils
import org.junit.Assert.assertFalse
import org.junit.Assert.assertTrue
import org.junit.Test

class SearchUtilsTest {

    @Test
    fun testTurkishCharacterMatches() {
        assertTrue(SearchUtils.matches("İsmail Hakkı", "ismail"))
        assertTrue(SearchUtils.matches("İsmail Hakkı", "ISMAIL"))
        assertTrue(SearchUtils.matches("İsmail Hakkı", "hakki"))
        assertTrue(SearchUtils.matches("İsmail Hakkı", "HAKKI"))
        assertTrue(SearchUtils.matches("Çağrı Merkezi", "cagri"))
        assertTrue(SearchUtils.matches("Çağrı Merkezi", "CAGRI"))
        assertTrue(SearchUtils.matches("Şükrü Özgür", "sukru"))
        assertTrue(SearchUtils.matches("Şükrü Özgür", "ozgur"))
    }

    @Test
    fun testExtensionMatches() {
        assertTrue(SearchUtils.matches("19000", "190"))
        assertTrue(SearchUtils.matches("19000", "000"))
        assertFalse(SearchUtils.matches("19000", "888"))
    }

    @Test
    fun testEmptyQueryMatchesAll() {
        assertTrue(SearchUtils.matches("Mahir", ""))
        assertTrue(SearchUtils.matches("Mahir", null))
        assertFalse(SearchUtils.matches("", "mahir"))
        assertFalse(SearchUtils.matches(null, "mahir"))
    }
}
