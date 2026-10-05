<?php
/**
 * Shared minimum security entry point for the api/ layer — for JSON/fetch()
 * based endpoints (files that answer browser JS, not render a page).
 *
 * api/cc.php does NOT USE IT — it handles its per-action requireRole()+CSRF
 * flow itself in a more nuanced way (see the note at the top of cc.php).
 * Endpoints the browser opens BY DIRECT navigation (img src / a href /
 * audio src), such as fax_download.php/sound_play.php, do not use it either
 * — for them a redirect to the page (requireLogin()) on session expiry is the
 * right behaviour, not JSON.
 *
 * Every new JSON api/*.php file could forget to add requireLogin()/
 * requireApiLogin() (exactly that happened once in destinations.php on
 * 2026-08-21) — requiring just this file instead moves that risk from
 * per-file discipline to one mandatory entry point. Module-specific extra
 * RBAC/ownership checks (like destinations.php's hasModulePermission() check)
 * still happen in the file itself, AFTER this point.
 */
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../auth.php';
requireApiLogin();
