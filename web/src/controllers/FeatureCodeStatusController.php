<?php

/**
 * Active Codes page (/feature-codes-status).
 * Renders templates/views/feature_codes_status/index.php.
 * Logic: FeatureCodeStatusRepository.
 * Admins only.
 */
class FeatureCodeStatusController extends BaseController
{
    public static function index(): void
    {
        static::requireRole('admin');

        $users = FeatureCodeStatusRepository::activeSipUsersStatus();

        $page_title = t('fc_status.title');
        static::renderPage('feature_codes_status/index', [
            'users' => $users,
        ], ['title' => $page_title]);
    }
}
