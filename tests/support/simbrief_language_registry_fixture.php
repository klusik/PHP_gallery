<?php

/**
 * Project: PHP Gallery
 * Repository: https://github.com/klusik/PHP_gallery
 * File: tests/support/simbrief_language_registry_fixture.php
 * Module Type: Test Support
 * Purpose: Supply the maintained content-language registry to isolated SimBrief model contracts.
 * Responsibilities: Expose the four supported content languages without loading the application bootstrap.
 * Author: Rudolf Klusal
 * License: MIT License (see LICENSE file in repository)
 */

declare(strict_types=1);

namespace Gallery\Services {
    /**
     * Return the four maintained languages for isolated SimBrief model contracts.
     *
     * @return array<int,string> Supported language codes.
     */
    function content_supported_languages(): array
    {
        return ['en', 'cs', 'de', 'sv'];
    }
}
