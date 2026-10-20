<?php
/**
 * Project: PHP Gallery
 * Repository: https://github.com/klusik/PHP_gallery
 * File: tests/public_widget_admin_missing_schema_test.php
 * Module Type: Regression Test
 * Purpose: Verify the real Theme Widgets Admin route stays usable when widget storage is missing.
 * Responsibilities:
 *   - Exercise the production Theme route, widget controller, service, model and view with disconnected PDO.
 *   - Keep missing-table diagnostics out of Admin HTML and prove reads and attempted writes fail safely.
 * Author: Rudolf Klusal
 * License: MIT License (see LICENSE file in repository)
 */
declare(strict_types=1);

namespace Gallery\Core {
    /**
     * Return the disconnected PDO fixture used by the Admin widget model.
     *
     * @return \PDO Connection that reports the absent optional widget table.
     */
    function db(): \PDO
    {
        return $GLOBALS['widget_admin_missing_schema_connection'];
    }

    /**
     * Return the request method selected by this isolated route fixture.
     *
     * @return string The fixture request method.
     */
    function request_method(): string
    {
        return $GLOBALS['widget_admin_missing_schema_method'] ?? 'GET';
    }

    /**
     * Record the isolated POST route's CSRF boundary.
     *
     * @return void Increments the number of verified write requests.
     */
    function verify_csrf(): void
    {
        if (($_POST['csrf_token'] ?? null) !== 'missing-schema-fixture') {
            throw new \RuntimeException(
                'The missing-schema fixture must provide its owned CSRF token.',
            );
        }
        $GLOBALS['widget_admin_missing_schema_csrf_calls']++;
    }

    /**
     * Record the Theme and widget controller authentication boundaries.
     *
     * @return void Increments the isolated authentication-call count.
     */
    function require_admin(): void
    {
        $GLOBALS['widget_admin_missing_schema_auth_calls']++;
    }

    /**
     * Return or store an isolated flash message without session state.
     *
     * @param string $key Session message key requested by the controller.
     * @param string|null $message Optional value to store for the request.
     * @return string|null Stored message, or null when no message exists.
     */
    function flash_message(string $key, ?string $message = null): ?string
    {
        if ($message !== null) {
            $GLOBALS['widget_admin_missing_schema_flash'][$key] = $message;
        }
        return $GLOBALS['widget_admin_missing_schema_flash'][$key] ?? null;
    }

    /**
     * Render a minimal test shell around the production Admin view.
     *
     * @param string $title Escaped page title supplied by the controller.
     * @param array<array-key,scalar|array<array-key,scalar|null>|null>|null $currentGallery Opaque legacy gallery context, deliberately ignored by this test shell.
     * @param bool $publicOnly Unused shell rendering mode.
     * @return void Emits a test header marker.
     */
    function render_header(
        string $title,
        ?array $currentGallery = null,
        bool $publicOnly = true,
    ): void {
        echo
            '<header data-test-admin-header>'
                . htmlspecialchars($title, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8')
                . '</header>';
    }

    /**
     * Render the minimal closing marker for the isolated Admin page.
     *
     * @param string $publicWidgetFooterHtml Unused public widget footer content.
     * @return void Emits a test footer marker.
     */
    function render_footer(string $publicWidgetFooterHtml = ''): void
    {
        echo '<footer data-test-admin-footer></footer>';
    }

    /**
     * Return deterministic CSRF markup for the rendered route fixture.
     *
     * @return string Hidden token field that keeps the production editor form structurally usable.
     */
    function csrf_field(): string
    {
        return '<input type="hidden" name="csrf_token" value="missing-schema-fixture">';
    }

    /**
     * Build a deterministic same-origin URL for the production view.
     *
     * @param string $page Route name supplied by the controller.
     * @param array<string,string|int|float|bool|null> $params Scalar semantic route parameters to encode in the query string.
     * @return string Relative route URL with encoded parameters.
     */
    function url_for(string $page, array $params = []): string
    {
        return (
            '/index.php?page='
                . rawurlencode($page)
                . ($params === [] ? '' : '&' . http_build_query($params))
        );
    }

    /**
     * Return the nonpersistent Home preview destination for the Admin editor.
     *
     * @param bool $anonymous Whether the preview should use anonymous visitor access.
     * @return string Safe same-origin preview URL.
     */
    function public_visual_preview_home_url(bool $anonymous = false): string
    {
        return '/index.php?page=home&preview=visual&view_as=anonymous';
    }

    /**
     * Escape one prepared view value using the production HTML encoding contract.
     *
     * @param string|null $value Text supplied to the production Admin view.
     * @return string HTML-escaped text.
     */
    function e(?string $value): string
    {
        return htmlspecialchars(
            $value ?? '',
            ENT_QUOTES | ENT_SUBSTITUTE,
            'UTF-8',
        );
    }
}

namespace Gallery\Services {
    /**
     * Return the supplied English fallback as the isolated maintained translation.
     *
     * @param string $key Translation catalog key.
     * @param string|array<string,string|int|float|bool|null>|null $fallback Explicit fallback text or a string-keyed replacement map.
     * @param array<string,string|int|float|bool|null> $parameters Scalar interpolation values, ignored by this English fixture.
     * @return string Fallback text, or the key when no text fallback is supplied.
     */
    function t(
        string $key,
        string|array|null $fallback = null,
        array $parameters = [],
    ): string {
        return is_string($fallback) ? $fallback : $key;
    }

    /**
     * Return the test destination for the existing Appearance settings page.
     *
     * @param string|null $section Optional selected settings section.
     * @param string|null $return Optional return destination.
     * @param bool $includeFragment Whether to include a section fragment.
     * @return string Relative settings URL.
     */
    function admin_settings_url(
        ?string $section = null,
        ?string $return = null,
        bool $includeFragment = false,
    ): string {
        return '/index.php?page=admin_settings';
    }

    /**
     * Return no Gallery candidates so the preview helper does not need Gallery storage.
     *
     * @return list<never> Always empty so the preview picker has no Gallery candidates.
     */
    function public_home_physical_galleries(): array
    {
        return [];
    }
}

namespace {
    /**
     * Simulate a missing widget table without connecting to any database.
     */
    final class PublicWidgetAdminMissingSchemaPdo extends \PDO
    {
        /** @var int Number of production widget SELECT attempts. */
        public int $queryCount = 0;

        /** @var int Number of attempted mutating SQL statements. */
        public int $mutationStatementCount = 0;

        /** @var int Number of prepared statements attempted by this route. */
        public int $prepareCount = 0;

        /** @var int Number of direct SQL executions attempted by this route. */
        public int $execCount = 0;

        /** @var bool Whether the disconnected fixture currently has a simulated transaction open. */
        private bool $transactionActive = false;

        /**
         * Construct a disconnected PDO test double.
         *
         * @return void Opens no database connection.
         */
        public function __construct() {}

        /**
         * Raise the synthetic missing-table error for production widget reads.
         *
         * @param string $query Model-owned SQL statement expected to select widget rows.
         * @param int|null $fetchMode Optional PDO fetch mode supplied by the caller.
         * @param mixed ...$fetchModeArgs Optional inherited PDO fetch arguments, lexically unused by this disconnected fixture.
         * @source-contract-opaque-param $fetchModeArgs Inherited PDO arguments are intentionally ignored by this disconnected fixture.
         * @return never Throws a fixture contract exception for unsupported SQL, or a PDOException for the absent table.
         */
        public function query(
            string $query,
            ?int $fetchMode = null,
            mixed ...$fetchModeArgs,
        ): \PDOStatement|false {
            if (
                !str_contains($query, 'FROM public_content_widgets')
                || $fetchMode !== null
            ) {
                throw new \RuntimeException(
                    'Unexpected Admin missing-schema query.',
                );
            }
            $this->queryCount++;
            if (
                preg_match(
                    '/^\s*(?:INSERT|UPDATE|DELETE|REPLACE|ALTER|DROP|CREATE)\b/i',
                    $query,
                ) === 1
            ) {
                $this->mutationStatementCount++;
                throw new \RuntimeException(
                    'Unexpected direct widget mutation in the missing-schema fixture.',
                );
            }
            throw new \PDOException(
                'SQLSTATE[42S02]: Base table or view not found: 1146 Table public_content_widgets does not exist; WIDGET_ADMIN_PRIVATE_SCHEMA_DIAGNOSTIC',
            );
        }

        /**
         * Reject writes at statement preparation because the widget table is absent.
         *
         * @param string $query SQL statement requested by production code.
         * @param array<array-key,mixed> $options Opaque PDO statement options, lexically unused by this disconnected fixture.
         * @return \PDOStatement|false Never returns; the missing table prevents statement preparation.
         */
        public function prepare(
            string $query,
            array $options = [],
        ): \PDOStatement|false {
            $this->prepareCount++;
            $isMutation =
                preg_match(
                    '/^\s*(?:INSERT|UPDATE|DELETE|REPLACE|ALTER|DROP|CREATE)\b/i',
                    $query,
                ) === 1;
            $targetsWidgetTable =
                preg_match(
                    '/^\s*(?:INSERT\s+INTO|UPDATE|DELETE\s+FROM)\s+(?:`public_content_widgets`|public_content_widgets)(?![a-zA-Z0-9_])/i',
                    $query,
                ) === 1;
            if ($isMutation) {
                if (!$targetsWidgetTable) {
                    throw new \RuntimeException(
                        'Unexpected prepared mutation target in the missing-schema fixture.',
                    );
                }
                $this->mutationStatementCount++;
                throw new \PDOException(
                    'SQLSTATE[42S02]: Base table or view not found: 1146 Table public_content_widgets does not exist; WIDGET_ADMIN_PRIVATE_SCHEMA_DIAGNOSTIC',
                );
            }
            throw new \RuntimeException(
                'Unexpected prepared statement in the missing-schema fixture.',
            );
        }

        /**
         * Reject direct SQL execution in the disconnected missing-schema fixture.
         *
         * @param string $statement SQL statement requested by production code.
         * @return int|false Never returns; direct execution is outside the missing-schema route contract.
         */
        public function exec(string $statement): int|false
        {
            $this->execCount++;
            if (
                preg_match(
                    '/^\s*(?:INSERT|UPDATE|DELETE|REPLACE|ALTER|DROP|CREATE)\b/i',
                    $statement,
                ) === 1
            ) {
                $this->mutationStatementCount++;
            }
            throw new \RuntimeException(
                'Unexpected direct SQL execution in the missing-schema fixture.',
            );
        }

        /**
         * Begin a simulated transaction without opening a database connection.
         *
         * @return bool True after marking the fixture transaction active.
         */
        public function beginTransaction(): bool
        {
            $this->transactionActive = true;
            return true;
        }

        /**
         * Report whether the disconnected fixture has a simulated transaction open.
         *
         * @return bool True until the fixture commits or rolls back.
         */
        public function inTransaction(): bool
        {
            return $this->transactionActive;
        }

        /**
         * End the simulated transaction after a failed widget write.
         *
         * @return bool True after clearing the active fixture transaction.
         */
        public function rollBack(): bool
        {
            $this->transactionActive = false;
            return true;
        }

        /**
         * End the simulated transaction after a successful fixture write.
         *
         * @return bool True after clearing the active fixture transaction.
         */
        public function commit(): bool
        {
            $this->transactionActive = false;
            return true;
        }
    }

    $check = static function (bool $condition, string $message): void {
        if (!$condition) {
            throw new \RuntimeException($message);
        }
    };

    $connection = new PublicWidgetAdminMissingSchemaPdo();
    $GLOBALS['widget_admin_missing_schema_connection'] = $connection;
    $GLOBALS['widget_admin_missing_schema_method'] = 'GET';
    $GLOBALS['widget_admin_missing_schema_csrf_calls'] = 0;
    $GLOBALS['widget_admin_missing_schema_auth_calls'] = 0;
    $GLOBALS['widget_admin_missing_schema_flash'] = [];
    $_GET = ['widgets' => '1'];
    $_POST = [];

    require_once dirname(__DIR__) . '/app/controllers/admin_theme.php';

    ob_start();
    \Gallery\Controllers\cms_admin_theme();
    $html = (string) ob_get_clean();

    $check(
        str_contains($html, 'data-test-admin-header')
            && str_contains($html, 'data-test-admin-footer'),
        'The actual Theme route must render the surrounding Admin page shell.',
    );
    $check(
        str_contains($html, '<h1>Public content widgets</h1>')
            && str_contains($html, 'class="public-widgets-form"'),
        'The actual Theme route must render a usable production widget editor.',
    );
    $check(
        str_contains(
            $html,
            'Widget storage is unavailable. Check database migrations and try again.',
        ),
        'The Admin view must explain the unavailable widget storage with the safe user-facing message.',
    );
    $check(
        str_contains(
            $html,
            'No stored widgets yet. Public pages remain unchanged.',
        ),
        'The Admin view must retain a safe empty-list state.',
    );
    $check(
        !str_contains($html, 'WIDGET_ADMIN_PRIVATE_SCHEMA_DIAGNOSTIC')
            && !str_contains($html, 'SQLSTATE[42S02]')
            && !str_contains($html, 'public_content_widgets does not exist'),
        'Raw missing-table diagnostics must not enter Admin HTML.',
    );
    $check(
        $connection->queryCount === 1,
        'The route must exercise exactly one production widget-list read.',
    );
    $check(
        $connection->prepareCount === 0
            && $connection->execCount === 0
            && $connection->mutationStatementCount === 0,
        'The read-only route must not issue prepared, direct or mutating SQL.',
    );
    $check(
        $GLOBALS['widget_admin_missing_schema_auth_calls'] === 2,
        'Both the Theme route and widget controller must retain their Admin authentication guards.',
    );

    $validDraft = [
        'title' => 'Unavailable widget storage',
        'content_md' => 'A safe unpublished draft.',
        'status' => 'draft',
        'page_scope' => 'all',
        'placement_mode' => 'flow',
        'flow_slot' => 'content_top',
        'floating_anchor' => 'bottom-right',
        'x_permille' => '900',
        'y_permille' => '900',
        'width_px' => '320',
        'mobile_fallback' => 'flow',
        'appearance' => 'card',
        'sort_order' => '0',
        'source_language' => 'en',
    ];
    $writeAttempts = [
        'create' => [
            'widget_action' => 'create',
            'widget_id' => 'new',
            ...$validDraft,
        ],
        'save' => [
            'widget_action' => 'save',
            'widget_id' => str_repeat('a', 32),
            'revision' => '1',
            ...$validDraft,
        ],
        'delete' => [
            'widget_action' => 'delete',
            'widget_id' => str_repeat('a', 32),
            'revision' => '1',
            'confirm_delete' => '1',
        ],
    ];
    foreach ($writeAttempts as $action => $post) {
        $GLOBALS['widget_admin_missing_schema_method'] = 'POST';
        $GLOBALS['widget_admin_missing_schema_auth_calls'] = 0;
        $GLOBALS['widget_admin_missing_schema_csrf_calls'] = 0;
        $GLOBALS['widget_admin_missing_schema_flash'] = [];
        $_GET = ['widgets' => '1'];
        $_POST = ['csrf_token' => 'missing-schema-fixture', ...$post];
        ob_start();
        \Gallery\Controllers\cms_admin_theme();
        $writeHtml = (string) ob_get_clean();
        $check(
            str_contains(
                $writeHtml,
                'Widget storage is unavailable. Check database migrations and try again.',
            ),
            'The missing table must produce the safe storage message for '
                . $action
                . '.',
        );
        $check(
            !str_contains($writeHtml, 'WIDGET_ADMIN_PRIVATE_SCHEMA_DIAGNOSTIC')
                && !str_contains($writeHtml, 'SQLSTATE[42S02]')
                && !str_contains(
                    $writeHtml,
                    '1146 Table public_content_widgets does not exist',
                ),
            'The storage diagnostic must stay private for ' . $action . '.',
        );
        $check(
            $GLOBALS['widget_admin_missing_schema_auth_calls'] === 2
                && $GLOBALS['widget_admin_missing_schema_csrf_calls'] === 1,
            'The '
                . $action
                . ' request must retain both Admin guards and its CSRF check.',
        );
        $check(
            !$connection->inTransaction(),
            'The failed ' . $action . ' request must close its transaction.',
        );
    }
    $check(
        $connection->queryCount === 5,
        'Create, save and delete must each fail at the missing storage boundary and retain one safe Admin list read.',
    );
    $check(
        $connection->prepareCount === 2
            && $connection->mutationStatementCount === 2
            && $connection->execCount === 0,
        'Save and delete may attempt their guarded statements, but no prepared mutation executes or direct SQL path is used.',
    );

    echo "public_widget_admin_missing_schema_test: PASS\n";
}
