package com.mhrgl.aipbx.util

import java.util.Locale

object SearchUtils {

    private val MARKS = Regex("\\p{M}+")

    /**
     * Normalizes accented and non-ASCII characters to canonical ASCII forms for loose, case-insensitive matching.
     * Handles Turkish (İ/I/ı, ğ, ü, ş, ö, ç) and European diacritics (é, è, ł, ó, ą, đ, etc.).
     * e.g. "İsmail" -> "ismail", "ışık" -> "isik", "Çağrı" -> "cagri", "Łukasz" -> "lukasz"
     */
    fun normalize(input: String?): String {
        if (input.isNullOrEmpty()) return ""
        val trReplaced = input.trim()
            .replace('İ', 'i')
            .replace('I', 'i')
            .replace('ı', 'i')
        val decomposed = java.text.Normalizer.normalize(trReplaced, java.text.Normalizer.Form.NFD)
        return MARKS.replace(decomposed, "")
            .replace('đ', 'd').replace('Đ', 'd')
            .replace('ð', 'd').replace('Ð', 'd')
            .replace('ø', 'o').replace('Ø', 'o')
            .replace('æ', 'a').replace('Æ', 'a')
            .replace('œ', 'o').replace('Œ', 'o')
            .replace('ß', 's')
            .replace('ł', 'l').replace('Ł', 'l')
            .lowercase(Locale.ROOT)
    }

    /**
     * Returns true if target contains query, handling Turkish case nuances.
     */
    fun matches(target: String?, query: String?): Boolean {
        if (query.isNullOrEmpty()) return true
        if (target.isNullOrEmpty()) return false

        val normTarget = normalize(target)
        val normQuery = normalize(query)
        return normTarget.contains(normQuery)
    }
}
