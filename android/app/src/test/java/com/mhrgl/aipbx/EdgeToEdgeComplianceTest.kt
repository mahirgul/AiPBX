package com.mhrgl.aipbx

import org.junit.Assert.assertFalse
import org.junit.Assert.assertTrue
import org.junit.Test
import java.io.File

/**
 * Architectural compliance test for Android 15+ Edge-to-Edge standards.
 *
 * This test enforces the rule established to permanently prevent Google Play Console warnings:
 * - "Edge-to-edge may not display for all users"
 * - "Your app uses deprecated APIs or parameters for edge-to-edge"
 *
 * Requirements:
 * 1. Every UI Activity must inherit from [com.mhrgl.aipbx.ui.BaseActivity].
 * 2. No calls to deprecated window APIs (setDecorFitsSystemWindows, setStatusBarColor, setNavigationBarColor),
 *    and no androidx enableEdgeToEdge(): its Android 8–14 code calls them (see BaseActivity).
 * 3. No calls to deprecated SystemBarStyle factory methods with custom color parameters.
 * 4. No theme opt-out attributes (windowOptOutEdgeToEdgeEnforcement, android:statusBarColor, android:navigationBarColor).
 */
class EdgeToEdgeComplianceTest {

    private val projectRoot: File by lazy {
        // Resolve project root from user dir or class path
        var dir = File(System.getProperty("user.dir") ?: ".")
        if (dir.name == "app") dir = dir.parentFile ?: dir
        if (!File(dir, "app/src/main/AndroidManifest.xml").exists()) {
            dir = File(dir, "android")
        }
        dir
    }

    private val srcMainJava: File by lazy { File(projectRoot, "app/src/main/java") }
    private val srcMainRes: File by lazy { File(projectRoot, "app/src/main/res") }
    private val manifestFile: File by lazy { File(projectRoot, "app/src/main/AndroidManifest.xml") }

    @Test
    fun testAllManifestActivitiesExtendBaseActivity() {
        assertTrue("AndroidManifest.xml must exist", manifestFile.exists())
        val manifestContent = manifestFile.readText()

        val activityRegex = Regex("""android:name="\.ui\.(\w+)"""")
        val activityMatches = activityRegex.findAll(manifestContent).map { it.groupValues[1] }.toList()

        assertTrue("Should detect UI activities in AndroidManifest", activityMatches.isNotEmpty())

        val violations = mutableListOf<String>()
        for (activityName in activityMatches) {
            val file = File(srcMainJava, "com/mhrgl/aipbx/ui/$activityName.kt")
            if (!file.exists()) {
                violations.add("Activity file not found: $activityName.kt")
                continue
            }
            val text = file.readText()
            val classDefRegex = Regex("""class\s+$activityName\s*:\s*(\w+)""")
            val match = classDefRegex.find(text)
            val superclass = match?.groupValues?.get(1)

            if (superclass != "BaseActivity") {
                violations.add("Activity $activityName extends '$superclass' instead of 'BaseActivity'")
            }
        }

        assertTrue(
            "All UI Activities must inherit from BaseActivity to ensure edge-to-edge compliance! Violations:\n" +
                    violations.joinToString("\n"),
            violations.isEmpty()
        )
    }

    @Test
    fun testNoDeprecatedEdgeToEdgeApisUsed() {
        val forbiddenPatterns = listOf(
            "setDecorFitsSystemWindows" to "Window.setDecorFitsSystemWindows / WindowCompat.setDecorFitsSystemWindows is deprecated in Android 15",
            "setStatusBarColor" to "Window.setStatusBarColor is deprecated in Android 15",
            "setNavigationBarColor" to "Window.setNavigationBarColor is deprecated in Android 15",
            "SystemBarStyle.dark(" to "SystemBarStyle.dark(...) with custom parameters triggers Play Console deprecation warnings",
            "SystemBarStyle.light(" to "SystemBarStyle.light(...) with custom parameters triggers Play Console deprecation warnings",
            "FLAG_LAYOUT_NO_LIMITS" to "FLAG_LAYOUT_NO_LIMITS is incompatible with Android 15 edge-to-edge standard insets",
            "enableEdgeToEdge" to "androidx enableEdgeToEdge() calls Window.setStatusBarColor/setNavigationBarColor on Android 8-14, which Play flags (see BaseActivity)",
            "EdgeToEdge.enable" to "androidx EdgeToEdge.enable() calls Window.setStatusBarColor/setNavigationBarColor on Android 8-14, which Play flags (see BaseActivity)",
            "setStatusBarContrastEnforced" to "Window.setStatusBarContrastEnforced is deprecated in Android 15",
            "setNavigationBarContrastEnforced" to "Window.setNavigationBarContrastEnforced is deprecated in Android 15",
            "LAYOUT_IN_DISPLAY_CUTOUT_MODE_SHORT_EDGES" to "LAYOUT_IN_DISPLAY_CUTOUT_MODE_SHORT_EDGES is deprecated in Android 15"
        )

        val violations = mutableListOf<String>()

        srcMainJava.walkTopDown().filter { it.extension in listOf("kt", "java") }.forEach { file ->
            // Skip BaseActivity itself for comments
            val lines = file.readLines()
            lines.forEachIndexed { idx, line ->
                val trimmed = line.trim()
                if (trimmed.startsWith("*") || trimmed.startsWith("//") || trimmed.startsWith("/*")) {
                    return@forEachIndexed
                }
                for ((pattern, reason) in forbiddenPatterns) {
                    if (trimmed.contains(pattern)) {
                        violations.add("${file.name}:${idx + 1} uses '$pattern' - $reason")
                    }
                }
            }
        }

        assertTrue(
            "Found deprecated Edge-to-Edge APIs in source code! Violations:\n" + violations.joinToString("\n"),
            violations.isEmpty()
        )
    }

    @Test
    fun testNoOptOutOrBarColorsInThemes() {
        val forbiddenXmlAttributes = listOf(
            "windowOptOutEdgeToEdgeEnforcement",
            "android:statusBarColor",
            "android:navigationBarColor"
        )

        val violations = mutableListOf<String>()

        srcMainRes.walkTopDown().filter { it.extension == "xml" && it.parentFile?.name?.startsWith("values") == true }.forEach { file ->
            val content = file.readText()
            for (attr in forbiddenXmlAttributes) {
                if (content.contains(attr)) {
                    violations.add("${file.relativeTo(srcMainRes).path} contains '$attr'")
                }
            }
        }

        assertTrue(
            "Themes must not override system bar colors or opt out of edge-to-edge! Violations:\n" +
                    violations.joinToString("\n"),
            violations.isEmpty()
        )
    }

    @Test
    fun testBaseActivityConfiguredCorrectly() {
        val baseActivityFile = File(srcMainJava, "com/mhrgl/aipbx/ui/BaseActivity.kt")
        assertTrue("BaseActivity.kt must exist", baseActivityFile.exists())
        val content = baseActivityFile.readText()

        assertTrue(
            "BaseActivity must wrap base context with L10n.wrap",
            content.contains("L10n.wrap(newBase)")
        )
    }
}
