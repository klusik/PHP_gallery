<?php
/**
 * Project: PHP Gallery
 * Repository: https://github.com/klusik/PHP_gallery
 * File: tests/app_linking_gallery_create_policy_test.php
 * Module Type: Regression Test
 * Purpose: Verify explicit linked gallery-create scope and operation identity.
 * Responsibilities:
 *   - Preserve legacy scope permissions and fail closed on unknown wide schema.
 *   - Check normalized input, issuer binding and safe receipt projection.
 * Author: Rudolf Klusal
 * License: MIT License (see LICENSE file in repository)
 */
declare(strict_types=1);

namespace Gallery\Services {
    /**
     * Supply the isolated wide-schema state without accessing a database.
     *
     * @return array{state:string} Fixture's currently selected schema state.
     */
    function app_linking_wide_scope_schema_status(): array
    {
        return ['state' => $GLOBALS['linked_create_wide_state']];
    }

    /**
     * Expose confirmed authentication schema for a fixture grant lookup.
     *
     * @return array{state:string} Available fixture schema state.
     */
    function app_linking_authentication_schema_status(): array
    {
        return ['state' => 'available'];
    }

    /**
     * Model this fixture's missing gallery mutation storage without a database.
     *
     * @param string $feature Requested scoped operation name.
     * @param array<string,list<string>> $columns Required columns by table.
     * @param array<string,array<string,list<string>>> $keys Required index columns.
     * @param list<string> $transactional Tables that must support transactions.
     * @return array{state:string,requirements:list<array<string,string>>}
     *     Missing mutation storage and its unavailable schema observation.
     */
    function app_linking_schema_status(
        string $feature, array $columns, array $keys, array $transactional,
    ): array {
        return ['state' => 'missing', 'requirements' => []];
    }
}

namespace Gallery\Models {
    /**
     * Return one controlled persisted-grant identity without a database.
     *
     * @param string $tokenHash Digest of the fixture bearer.
     * @param string $issuer Fixture issuer passed by the real service.
     * @return array{grant_id:int,user_id:int,username:string,scope:string} Controlled live grant.
     */
    function app_link_model_lookup_token(string $tokenHash, string $issuer): array
    {
        return ['grant_id' => 4, 'user_id' => 7, 'username' => 'fixture',
            'scope' => $GLOBALS['linked_visibility_fixture_scope']];
    }
}

namespace {
    require_once __DIR__ . '/../app/services/app_linking_policy.php';
    require_once __DIR__ . '/../app/services/admin_operation_keys.php';
    require_once __DIR__ . '/../app/services/security_tokens.php';
    require_once __DIR__ . '/../app/services/gallery_edit_concurrency.php';
    require_once __DIR__ . '/../app/services/app_linking.php';
    require_once __DIR__ . '/../app/services/app_linking_create.php';

    $check = static function (bool $condition, string $message): void {
        if (!$condition) {
            throw new \RuntimeException($message);
        }
    };
    $GLOBALS['linked_create_wide_state'] = 'available';
    \Gallery\Services\schema_inspection_set_query_executor_for_tests(
        static fn(string $kind, string $table, string $object, mixed $definition = null): bool => false,
    );
    $client = \Gallery\Services\APP_LINKING_WINAPP_CLIENT_ID;
    $bundles = [
        \Gallery\Services\APP_LINKING_CREATE_SCOPE,
        \Gallery\Services\APP_LINKING_READ_CREATE_SCOPE,
        \Gallery\Services\APP_LINKING_UPLOAD_CREATE_SCOPE,
        \Gallery\Services\APP_LINKING_READ_UPLOAD_CREATE_SCOPE,
    ];
    foreach ($bundles as $scope) {
        foreach ([$scope, $scope . ' offline_access'] as $approved) {
            $check(\Gallery\Services\app_linking_scope_allowed($client, $approved)
                && \Gallery\Services\app_linking_create_scope_allowed($approved),
                'Each exact new bundle must require and retain creation authority.');
        }
    }
    foreach ([
        \Gallery\Services\APP_LINKING_IDENTITY_SCOPE,
        \Gallery\Services\APP_LINKING_GALLERIES_SCOPE,
        \Gallery\Services\APP_LINKING_READ_SCOPE,
        \Gallery\Services\APP_LINKING_UPLOAD_SCOPE,
        \Gallery\Services\APP_LINKING_READ_UPLOAD_OFFLINE_SCOPE,
    ] as $oldScope) {
        $check(!\Gallery\Services\app_linking_create_scope_allowed($oldScope),
            'An existing grant must not gain create authority.');
        $check(!\Gallery\Services\app_linking_visibility_scope_allowed($oldScope),
            'An existing grant must not gain visibility authority.');
    }
    foreach ([
        \Gallery\Services\APP_LINKING_VISIBILITY_SCOPE,
        \Gallery\Services\APP_LINKING_UPLOAD_VISIBILITY_SCOPE,
        \Gallery\Services\APP_LINKING_CREATE_VISIBILITY_SCOPE,
        \Gallery\Services\APP_LINKING_UPLOAD_CREATE_VISIBILITY_SCOPE,
    ] as $visibilityScope) {
        foreach ([$visibilityScope, $visibilityScope . ' offline_access'] as $scope) {
            $check(\Gallery\Services\app_linking_visibility_scope_allowed($scope)
                && !\Gallery\Services\app_linking_scope_allowed($client, $scope),
                'Visibility bundles must refuse missing mutation storage.');
        }
    }
    $GLOBALS['linked_visibility_fixture_scope'] = \Gallery\Services\APP_LINKING_VISIBILITY_SCOPE;
    $bearer = \Gallery\Services\app_linking_access_token_generate();
    $issuer = 'https://gallery.example.test/Galerie';
    $check(\Gallery\Services\app_linking_authenticate($bearer, $issuer) === null,
        'Even an existing live visibility grant must refuse missing mutation storage.');
    $blockedMutation = false;
    try {
        \Gallery\Services\app_linking_change_gallery_visibility(
            $bearer, $issuer, 1, '1', 'private',
        );
    } catch (\RuntimeException) {
        $blockedMutation = true;
    }
    $check($blockedMutation,
        'The actual mutation service must refuse an incomplete visibility schema.');
    $check(\Gallery\Services\app_linking_visibility_next_revision('99') === '100'
        && \Gallery\Services\app_linking_visibility_next_revision('9999999999999999999')
            === '10000000000000000000',
        'Revision increment must not narrow large decimal values to PHP integers.');
    \Gallery\Services\app_linking_visibility_assert_schedule_clear(
        ['scheduled_publish_at' => null], true,
    );
    \Gallery\Services\app_linking_visibility_assert_schedule_clear([], false);
    $scheduleRefused = false;
    try {
        \Gallery\Services\app_linking_visibility_assert_schedule_clear(
            ['scheduled_publish_at' => '2030-01-01 00:00:00'], true,
        );
    } catch (\Gallery\Services\LinkedGalleryScheduledPublicationActive) {
        $scheduleRefused = true;
    }
    $check($scheduleRefused, 'A stored deadline must be resolved before changing visibility.');
    $unknownSchedule = false;
    try {
        \Gallery\Services\app_linking_visibility_assert_schedule_clear([], true);
    } catch (\RuntimeException) {
        $unknownSchedule = true;
    }
    $check($unknownSchedule, 'A row missing its confirmed schedule column must be refused.');
    $check(!\Gallery\Services\app_linking_scope_allowed(
        $client, 'link:identity galleries:list galleries:create gallery:upload',
    ), 'A differently ordered bundle must not be accepted.');
    $GLOBALS['linked_create_wide_state'] = 'unknown';
    $check(!\Gallery\Services\app_linking_scope_allowed($client, $bundles[0])
        && \Gallery\Services\app_linking_scope_allowed(
            $client, \Gallery\Services\APP_LINKING_UPLOAD_SCOPE,
        ), 'Unknown wide storage must refuse new authority but retain legacy upload.');

    $input = \Gallery\Services\app_linking_create_input([
        'title' => '  Summer  ', 'parent_id' => null,
    ]);
    $check($input === ['title' => 'Summer', 'parent_id' => 0],
        'A root creation must normalize title and parent exactly.');
    foreach ([
        ['title' => '', 'parent_id' => null],
        ['title' => "bad\nname", 'parent_id' => null],
        ['title' => str_repeat('x', 256), 'parent_id' => null],
        ['title' => 'valid', 'parent_id' => 0],
        ['title' => 'valid', 'parent_id' => '1'],
    ] as $bad) {
        try {
            \Gallery\Services\app_linking_create_input($bad);
            throw new \LogicException('Invalid creation input was accepted.');
        } catch (\RuntimeException $expected) {
            $check(!$expected instanceof \LogicException, 'Invalid input must be refused.');
        }
    }
    $key = str_repeat('a', 64);
    $one = \Gallery\Services\app_linking_create_ledger_key($key, 'https://one.example');
    $check($one === \Gallery\Services\app_linking_create_ledger_key($key, 'https://one.example')
        && $one !== \Gallery\Services\app_linking_create_ledger_key($key, 'https://two.example'),
        'An operation identity must survive reauthorization but remain issuer-bound.');
    $receipt = \Gallery\Services\app_linking_create_project_receipt([
        'gallery_id' => 4, 'gallery_title' => 'Summer', 'parent_gallery_id' => 0,
        'folder_path' => '/private/fixture',
    ]);
    $check($receipt === ['state' => 'completed', 'gallery' => [
        'id' => 4, 'title' => 'Summer', 'parent_id' => null,
    ]], 'The safe receipt must not expose server paths.');
    echo "linked gallery creation policy tests passed\n";
}
