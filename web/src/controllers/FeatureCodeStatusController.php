<?php

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
