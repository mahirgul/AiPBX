package com.mhrgl.aipbx.util

import java.util.Locale

object SearchUtils {

    /**
     * Normalizes Turkish characters and accents to canonical forms for loose, case-insensitive matching.
     * e.g. "İsmail" -> "ismail", "ISMAİL" -> "ismail", "ışık" -> "isik", "Çağrı" -> "cagri"
     */
    fun normalize(input: String?): String {
        if (input.isNullOrEmpty()) return ""
        return input.trim()
            .replace('İ', 'i')
            .replace('I', 'i')
            .replace('ı', 'i')
            .replace('ğ', 'g').replace('Ğ', 'g')
            .replace('ü', 'u').replace('Ü', 'u')
            .replace('ş', 's').replace('Ş', 's')
            .replace('ö', 'o').replace('Ö', 'o')
            .replace('ç', 'c').replace('Ç', 'c')
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
