<?php
/**
 * Project: PHP Gallery
 * Repository: https://github.com/klusik/PHP_gallery
 * File: database/migrations/202610140001_automatic_cache_cleanup.php
 * Module Type: Database Migration
 * Purpose: Enable the canonical automatic cache capability on installation and upgrade.
 * Responsibilities: Seed absent preferences only; replay and later upgrades preserve an explicit administrator opt-out.
 * Author: Rudolf Klusal
 * License: MIT License (see LICENSE file in repository)
 */
declare(strict_types=1);

return [
    "INSERT INTO app_settings (setting_key, setting_value, updated_at) VALUES ('feature_flag.automatic_cache_cleanup.enabled', '1', NOW()) ON DUPLICATE KEY UPDATE setting_value = setting_value",
];
