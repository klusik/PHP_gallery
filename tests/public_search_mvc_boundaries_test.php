<?php

/**
 * Protect the MVC boundaries introduced for progressive public search.
 */

declare(strict_types=1);

/**
 * Assert one public-search MVC boundary.
 *
 * @param bool $condition Assertion result.
 * @param string $label Failure label.
 */
function public_search_mvc_assert(bool $condition, string $label): void
{
    if (!$condition) {
        throw new RuntimeException($label);
    }
}

$root = dirname(__DIR__);
$servicesLoader = (string) file_get_contents($root . '/app/services.php');
$modelsLoader = (string) file_get_contents($root . '/app/models.php');
$compatibilityModelSource = (string) file_get_contents($root . '/app/models/public_search.php');
$modelSource = $compatibilityModelSource . "\n" . (string) file_get_contents($root . '/app/models/public_search_progressive.php') . "\n" . (string) file_get_contents($root . '/app/models/public_search_progressive/deferred.php');
$compatibilityServiceSource = (string) file_get_contents($root . '/app/services/public_search.php');
$serviceSource = $compatibilityServiceSource . "\n" . (string) file_get_contents($root . '/app/services/public_search_progressive.php') . "\n" . (string) file_get_contents($root . '/app/services/public_search_progressive/deferred.php');
$controllerSource = (string) file_get_contents($root . '/app/controllers/public_search.php');
$homeControllerSource = (string) file_get_contents($root . '/app/controllers/public_gallery_home.php');
$pageControllerSource = (string) file_get_contents($root . '/app/controllers/public_gallery_page.php');
$viewSource = (string) file_get_contents($root . '/app/views/public_search.php');
$controllersLoader = (string) file_get_contents($root . '/app/controllers.php');
$viewsLoader = (string) file_get_contents($root . '/app/views.php');

public_search_mvc_assert(str_contains($modelsLoader, "'/models/public_search.php'"), 'Model loader must register the compatibility public-search model.');
public_search_mvc_assert(str_contains($modelsLoader, "'/models/public_search_progressive.php'"), 'Model loader must register the progressive public-search model.');
public_search_mvc_assert(str_contains((string) file_get_contents($root . '/app/models/public_search_progressive.php'), "'/public_search_progressive/deferred.php'"), 'Progressive search model must load its deferred model part.');
public_search_mvc_assert(strpos($servicesLoader, "'/models.php'") < strpos($servicesLoader, "'/services/public_search.php'"), 'Models must load before public-search services.');

public_search_mvc_assert(str_contains($modelSource, 'namespace Gallery\\Models;'), 'Public-search data access must use the model namespace.');
public_search_mvc_assert(str_contains($modelSource, 'use function Gallery\\Core\\db;'), 'Search model must own PDO access through the core DB gateway.');
public_search_mvc_assert(!str_contains($modelSource, 'Gallery\\Services\\'), 'Search model must not depend upward on the service namespace.');
public_search_mvc_assert(!str_contains($modelSource, '$_GET') && !str_contains($modelSource, '$_POST'), 'Search model must not read HTTP request globals.');
public_search_mvc_assert(!str_contains($modelSource, 'echo '), 'Search model must not render presentation output.');

public_search_mvc_assert(!str_contains($serviceSource, 'db()->') && !str_contains($serviceSource, '->prepare(') && !str_contains($serviceSource, 'SELECT '), 'All public-search services must delegate SQL to the model layer.');
public_search_mvc_assert(str_contains($compatibilityServiceSource, 'public_search_model_compatibility_gallery_rows(') && str_contains($compatibilityServiceSource, 'public_search_model_compatibility_image_rows('), 'Compatibility search service must delegate legacy data access to its model.');
public_search_mvc_assert(str_contains($compatibilityModelSource, 'function public_search_model_compatibility_gallery_rows(') && str_contains($compatibilityModelSource, 'function public_search_model_compatibility_image_rows('), 'Compatibility model must own both legacy query paths.');
public_search_mvc_assert(str_contains($serviceSource, 'public_search_model_primary_gallery_title_rows('), 'Primary service orchestration must call the gallery-title model query.');
public_search_mvc_assert(str_contains($serviceSource, 'public_search_model_media_image_tag_rows('), 'Media service orchestration must call the image-tag model query.');

public_search_mvc_assert(str_contains($controllerSource, 'function cms_public_search(): void'), 'Dedicated public-search controller must own the endpoint.');
public_search_mvc_assert(!str_contains($controllerSource, 'SELECT ') && !str_contains($controllerSource, 'db()->'), 'Public-search controller must not own SQL.');
public_search_mvc_assert(!str_contains($controllerSource, 'public-home-search-shell'), 'Public-search controller must not render search-bar HTML.');
public_search_mvc_assert(str_contains($controllersLoader, "'/controllers/public_search.php'"), 'Controller loader must register the dedicated search controller.');
public_search_mvc_assert(!str_contains($homeControllerSource, 'function cms_public_search('), 'Home controller must not retain the search endpoint after MVC extraction.');

public_search_mvc_assert(str_contains($viewSource, 'function view_render_public_search_bar('), 'Dedicated public-search view must own search-bar markup.');
public_search_mvc_assert(str_contains($viewSource, 'data-public-home-search'), 'Public-search view must retain the browser integration root.');
public_search_mvc_assert(!str_contains($viewSource, 'db()->') && !str_contains($viewSource, '$_GET'), 'Public-search view must not query data or inspect request globals.');
public_search_mvc_assert(str_contains($viewsLoader, "'/views/public_search.php'"), 'View loader must register the dedicated search view.');
public_search_mvc_assert(str_contains($homeControllerSource, 'view_render_public_search_bar();'), 'Home controller must delegate search markup to the view.');
public_search_mvc_assert(str_contains($pageControllerSource, 'view_render_public_search_bar($gallery);'), 'Gallery controller must delegate search markup to the view.');

fwrite(STDOUT, "Public-search MVC boundary checks passed.\n");
