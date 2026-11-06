<?php
require_once __DIR__ . '/../helpers.php';

/** The shared parts of the portal on one page, to look at and to copy from. Admin only. */
class UiKitController extends BaseController
{
    public static function index(): void
    {
        static::requireRole('admin');
        static::renderPage('ui_kit/index', ['csrf' => getCSRFToken()], ['title' => 'UI kit']);
    }
}
