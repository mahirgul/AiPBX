<?php

class FeatureCodeStatusController extends BaseController
{
    public static function index(): void
    {
        static::requireRole('admin');

        $users = FeatureCodeStatusRepository::activeSipUsersStatus();

        $page_title = t('fc_status.title');
        require_once dirname(__DIR__) . '/../header.php';
        static::render('feature_codes_status/index', [
            'users' => $users,
        ]);
        require_once dirname(__DIR__) . '/../footer.php';
    }
}
