<?php
require_once __DIR__ . '/YealinkTemplate.php';
require_once __DIR__ . '/GrandstreamTemplate.php';
require_once __DIR__ . '/FanvilTemplate.php';

/** The template of each vendor. */
final class PhoneTemplates
{
    public static function forVendor(string $vendor): ?PhoneTemplate
    {
        return match ($vendor) {
            'yealink' => new YealinkTemplate(),
            'grandstream' => new GrandstreamTemplate(),
            'fanvil' => new FanvilTemplate(),
            default => null,
        };
    }

    public static function forModel(string $model): ?PhoneTemplate
    {
        return self::forVendor(PhoneModels::vendorOf($model));
    }

    /** @return PhoneTemplate[] */
    public static function all(): array
    {
        return array_values(array_filter(array_map([self::class, 'forVendor'], array_keys(PhoneModels::VENDORS))));
    }
}
