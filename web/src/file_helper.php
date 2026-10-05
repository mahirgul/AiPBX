<?php
/**
 * File & Storage Helper for PBX Portal
 * Safe atomic file writing, permission management (asterisk:asterisk), and directory creation.
 */

class FileHelper {
    /**
     * Safely write a file with automatic directory creation and ownership/permission enforcement.
     */
    public static function writeFile($filepath, $content, $owner = 'asterisk', $group = 'asterisk', $mode = 0664) {
        $dir = dirname($filepath);
        if (!is_dir($dir)) {
            @mkdir($dir, 0775, true);
            if ($owner) @chown($dir, $owner);
            if ($group) @chgrp($dir, $group);
            @chmod($dir, 0775);
        }

        // Writing the target file directly with file_put_contents() is NOT
        // atomic — if two concurrent requests (two PHP-FPM workers) write the
        // same master .conf file (e.g. pjsip_endpoints.conf) and an Asterisk
        // reload is triggered right after, Asterisk can read a half/mixed file
        // (found in the 2026-08-21 audit). Writing to a temp file in the same
        // directory and moving it with rename() is atomic on POSIX — the reader
        // sees either the old or the new COMPLETE file, never half of it.
        $tmp_path = $filepath . '.tmp' . getmypid() . '_' . uniqid();
        $res = file_put_contents($tmp_path, $content);
        if ($res === false) {
            @unlink($tmp_path);
            return false;
        }

        if ($owner) @chown($tmp_path, $owner);
        if ($group) @chgrp($tmp_path, $group);
        if ($mode) @chmod($tmp_path, $mode);

        if (!@rename($tmp_path, $filepath)) {
            @unlink($tmp_path);
            return false;
        }
        return true;
    }

    /**
     * Safely delete a file if it exists.
     */
    public static function deleteFile($filepath) {
        if (file_exists($filepath) && is_file($filepath)) {
            return @unlink($filepath);
        }
        return false;
    }

    /**
     * Sanitize audio file path for Asterisk dialplan playback (removes extensions like .wav, .gsm, .mp3)
     */
    public static function sanitizeAudioPath($path) {
        $clean = preg_replace('/[^a-zA-Z0-9_.\/-]/', '', trim($path));
        return preg_replace('/\.(wav|gsm|mp3|sln)$/i', '', $clean);
    }
}
