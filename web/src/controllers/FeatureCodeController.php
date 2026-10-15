<?php
require_once __DIR__ . '/../helpers.php';

/**
 * Feature Codes page (/feature-codes).
 * Renders templates/views/feature_codes/index.php with assets/js/feature_codes.js.
 * Logic: FeatureCodeRepository, FeatureCodeService.
 * POST actions: save_feature_code, toggle_status.
 * Admins only.
 */
class FeatureCodeController extends BaseController
{
    public static function index(): void
    {
        static::requireRole('admin');

        $all_roles = FeatureCodeRepository::allRoles();
        $valid_role_keys = array_column($all_roles, 'role_key');

        $notices = static::handlePost([
            'save_feature_code' => fn() => FeatureCodeService::saveFeatureCode($_POST, $valid_role_keys),
            'toggle_status' => fn() => FeatureCodeService::toggleStatus($_POST['feature_id'] ?? 0, static::csrfToken()),
        ]);

        $codes = FeatureCodeRepository::allOrderedById();

        $page_title = t('fc.title');
        static::renderPage('feature_codes/index', [
            'codes' => $codes,
            'valid_role_keys' => $valid_role_keys,
        ], ['title' => $page_title] + $notices);
    }
}
