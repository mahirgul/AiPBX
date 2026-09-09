<?php
require_once __DIR__ . '/../helpers.php';

class FeatureCodeController extends BaseController
{
    public static function index(): void
    {
        static::requireRole('admin');

        $message = '';
        $error = '';

        $all_roles = FeatureCodeRepository::allRoles();
        $valid_role_keys = array_column($all_roles, 'role_key');

        if (static::isPost()) {
            if (isset($_POST['save_feature_code'])) {
                $res = PBXHelper::saveFeatureCode($_POST, $valid_role_keys);
                if ($res['success']) $message = $res['message']; else $error = $res['error'];
            } elseif (isset($_POST['toggle_status'])) {
                $res = PBXHelper::toggleFeatureCodeStatus($_POST['feature_id'] ?? 0, $_POST['csrf_token'] ?? '');
                if ($res['success']) $message = $res['message']; else $error = $res['error'];
            }
        }

        $codes = FeatureCodeRepository::allOrderedById();

        $page_title = t('fc.title');
        require_once dirname(__DIR__) . '/../header.php';
        static::render('feature_codes/index', [
            'codes' => $codes,
            'valid_role_keys' => $valid_role_keys,
        ]);
        require_once dirname(__DIR__) . '/../footer.php';
    }
}
