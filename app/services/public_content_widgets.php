<?php
/**
 * Project: PHP Gallery
 * Repository: https://github.com/klusik/PHP_gallery
 * File: app/services/public_content_widgets.php
 * Module Type: Service
 * Purpose: Normalize public widget state, placement, link policy and publication workflow.
 * Responsibilities:
 *   - Validate an independent safe widget data contract before database writes.
 *   - Coordinate optimistic updates without accepting browser-originated SQL or CSS.
 *   - Filter prepared public rows by closed page-type and publication allowlists.
 * Author: Rudolf Klusal
 * License: MIT License (see LICENSE file in repository)
 */
declare(strict_types=1);

namespace Gallery\Services;

use InvalidArgumentException;
use RuntimeException;
use Throwable;
use function Gallery\Models\public_widget_model_delete;
use function Gallery\Models\public_widget_model_find;
use function Gallery\Models\public_widget_model_insert;
use function Gallery\Models\public_widget_model_list;
use function Gallery\Models\public_widget_model_reorder;
use function Gallery\Models\public_widget_model_replace;

require_once dirname(__DIR__) . '/models/public_content_widgets.php';

/**
 * Cap the administrator's number of stored widgets, including unpublished duplicates.
 * @var int
 * Units: widget rows. Scope: one installation.
 * Consumers: widget creation, duplication, management listing and model admission.
 * Rationale: bound accidental public page complexity and administrative storage on shared hosting.
 */
const PUBLIC_WIDGET_MAX_ITEMS = 48;

/**
 * Cap the UTF-8 Markdown source stored in one authored widget.
 * @var int
 * Units: bytes. Scope: one widget record.
 * Consumers: Admin input validation and public output admission.
 * Rationale: allow practical link collections without admitting arbitrarily large public markup.
 */
const PUBLIC_WIDGET_CONTENT_MAX_BYTES = 16384;

/**
 * Bound the panel width without allowing authored CSS or unbounded overlays.
 * @var int
 * Units: CSS pixels. Scope: one widget.
 * Consumers: validation and future shared responsive renderer.
 * Rationale: maintain readable content while keeping the primary page usable.
 */
const PUBLIC_WIDGET_MIN_WIDTH_PX = 180;

/**
 * Bound the panel width for desktop and enforce safe responsive clamping later.
 * @var int
 * Units: CSS pixels. Scope: one widget.
 * Consumers: validation and future shared responsive renderer.
 * Rationale: prevent a floating widget from occupying an unreasonable viewport area.
 */
const PUBLIC_WIDGET_MAX_WIDTH_PX = 480;

/** Describe a rejected semantic widget field without leaking storage details. */
final class PublicWidgetInvalidField extends InvalidArgumentException
{
    /**
     * Build a field-specific validation failure for an authenticated form.
     * @param string $field Stable field name for the form error.
     * @param string $message Human-readable, transport-independent explanation.
     * @return void
     */
    public function __construct(public readonly string $field, string $message)
    {
        parent::__construct($message);
    }
}

/**
 * Validate a widget identifier without converting or accepting arbitrary SQL keys.
 *
 * @param scalar|array<array-key,mixed>|object|resource|null $value Submitted or persisted value.
 * @return string Lowercase 32-character identifier.
 */
function public_widget_id(mixed $value): string
{
    if (!is_string($value) || preg_match('/^[a-f0-9]{32}$/D', $value) !== 1) {
        throw new PublicWidgetInvalidField('widget_id', 'Choose an existing widget.');
    }
    return $value;
}

/**
 * Enforce an explicitly supported web destination without executable schemes.
 *
 * @param scalar|array<array-key,mixed>|object|resource|null $input Administrator-authored Markdown link target.
 * @return string Valid internal site path/fragment or external HTTP(S) URL.
 */
function public_widget_safe_url(mixed $input): string
{
    if (!is_string($input) || $input === '' || strlen($input) > 2048
        || preg_match('/[\x00-\x20\x7f\\\\<>"\x27]/u', $input) === 1
        || preg_match('/%(?:0[0-9a-f]|1[0-9a-f]|5c)/i', $input) === 1) {
        throw new PublicWidgetInvalidField('content_md', 'Links need a safe URL without whitespace, control characters or quotes.');
    }
    if (preg_match('~^https?://~i', $input) === 1) {
        if (filter_var($input, FILTER_VALIDATE_URL) === false
            || !is_string(parse_url($input, PHP_URL_HOST))
            || parse_url($input, PHP_URL_USER) !== null
            || parse_url($input, PHP_URL_PASS) !== null) {
            throw new PublicWidgetInvalidField('content_md', 'Enter an absolute HTTP(S) address with a valid host.');
        }
        return $input;
    }
    if ((str_starts_with($input, '/') && !str_starts_with($input, '//'))
        || preg_match('/^index\.php(?:[?#].*)?$/D', $input) === 1
        || preg_match('/^\?(?:page|gallery|lang)=[a-zA-Z0-9_%&=.#-]+$/D', $input) === 1
        || preg_match('/^#[a-zA-Z0-9_-]+$/D', $input) === 1) {
        return $input;
    }
    throw new PublicWidgetInvalidField('content_md', 'Use a site-relative destination or an HTTP(S) URL.');
}

/**
 * Ensure authored Markdown link tokens contain only safe destinations.
 *
 * Non-Markdown plain text is always HTML-escaped by the eventual renderer.
 *
 * @param string $content UTF-8 authored Markdown source.
 * @return void Throws on unsupported or malicious Markdown link destinations.
 */
function public_widget_validate_markdown_links(string $content): void
{
    if (preg_match_all('/(?<!\\\\)\[[^\]\r\n]{1,160}\]\(([^()\s]{1,2048})\)/u', $content, $matches) === false) {
        throw new PublicWidgetInvalidField('content_md', 'The content contains invalid UTF-8 or link syntax.');
    }
    foreach ($matches[1] as $url) {
        public_widget_safe_url($url);
    }
}

/**
 * Normalize a complete editor draft without permitting raw CSS, JS or DOM targets.
 *
 * Inactive mode fields stay validated and stored so switching modes does not
 * unexpectedly discard the previous flow slot or floating anchor/coordinates.
 *
 * @param array<string,mixed> $input Semantic authored values, never HTTP globals.
 * @return array{title:string,content_md:string,status:string,page_scope:string,placement_mode:string,flow_slot:string,floating_anchor:string,x_permille:int,y_permille:int,width_px:int,mobile_fallback:string,appearance:string,sort_order:int,source_language:string} Valid persisted values, excluding identity/revision.
 */
function public_widget_normalize(array $input): array
{
    foreach (['title', 'content_md'] as $field) {
        if (!is_string($input[$field] ?? null)) {
            throw new PublicWidgetInvalidField($field, 'Enter valid widget text.');
        }
    }
    $title = trim($input['title']);
    $content = trim(str_replace(["\r\n", "\r"], "\n", $input['content_md']));
    if (strlen($title) > 720 || (function_exists('mb_strlen') ? mb_strlen($title, 'UTF-8') > 180 : strlen($title) > 180)) {
        throw new PublicWidgetInvalidField('title', 'Keep the widget title within 180 characters.');
    }
    if (strlen($content) > PUBLIC_WIDGET_CONTENT_MAX_BYTES) {
        throw new PublicWidgetInvalidField('content_md', 'Widget content is too long.');
    }
    if (preg_match('//u', $title . $content) !== 1) {
        throw new PublicWidgetInvalidField('content_md', 'Widget text must contain valid UTF-8.');
    }
    public_widget_validate_markdown_links($content);

    $options = [
        'status' => ['draft', 'published', 'disabled'],
        'page_scope' => ['home', 'gallery', 'all'],
        'placement_mode' => ['flow', 'floating'],
        'flow_slot' => ['content_top', 'content_bottom', 'left_rail', 'right_rail', 'home_before_grid', 'home_after_grid', 'footer'],
        'floating_anchor' => ['top-left', 'top-center', 'top-right', 'middle-left', 'middle-right', 'bottom-left', 'bottom-center', 'bottom-right', 'custom'],
        'mobile_fallback' => ['flow'],
        'appearance' => ['card', 'minimal'],
        'source_language' => ['en', 'cs', 'de', 'sv'],
    ];
    $defaults = [
        'status' => 'draft', 'page_scope' => 'home', 'placement_mode' => 'flow',
        'flow_slot' => 'home_after_grid', 'floating_anchor' => 'bottom-right',
        'mobile_fallback' => 'flow', 'appearance' => 'card', 'source_language' => 'en',
    ];
    $validated = [];
    foreach ($options as $field => $accepted) {
        $value = $input[$field] ?? $defaults[$field];
        if (!is_string($value) || !in_array($value, $accepted, true)) {
            throw new PublicWidgetInvalidField($field, 'Choose one of the supported widget options.');
        }
        $validated[$field] = $value;
    }
    if ($validated['page_scope'] === 'gallery' && in_array($validated['flow_slot'], ['home_before_grid', 'home_after_grid'], true)) {
        throw new PublicWidgetInvalidField('flow_slot', 'Homepage gallery-grid positions are unavailable for gallery-only widgets.');
    }
    if ($validated['status'] === 'published' && $content === '') {
        throw new PublicWidgetInvalidField('content_md', 'Add content before publishing this widget.');
    }

    $numbers = ['x_permille' => [900, 0, 1000], 'y_permille' => [900, 0, 1000], 'width_px' => [320, PUBLIC_WIDGET_MIN_WIDTH_PX, PUBLIC_WIDGET_MAX_WIDTH_PX], 'sort_order' => [0, 0, 10000]];
    $numeric = [];
    foreach ($numbers as $field => [$default, $minimum, $maximum]) {
        $raw = $input[$field] ?? $default;
        if ((!is_int($raw) && (!is_string($raw) || preg_match('/^(?:0|[1-9][0-9]*)$/D', $raw) !== 1))
            || (float) $raw > $maximum || (float) $raw < $minimum) {
            throw new PublicWidgetInvalidField($field, 'Enter a value inside the supported widget range.');
        }
        $numeric[$field] = (int) $raw;
    }

    return [
        'title' => $title, 'content_md' => $content,
        ...$validated,
        ...$numeric,
    ];
}

/**
 * Validate the revision supplied with a concurrent editor mutation.
 *
 * @param scalar|array<array-key,mixed>|object|resource|null $revision Submitted revision, not a source of database authority.
 * @return int Positive optimistic revision.
 */
function public_widget_revision(mixed $revision): int
{
    if ((!is_int($revision) && (!is_string($revision) || preg_match('/^[1-9][0-9]*$/D', $revision) !== 1))
        || (float) $revision > PHP_INT_MAX || (int) $revision < 1) {
        throw new PublicWidgetInvalidField('revision', 'Reload the widget before saving.');
    }
    return (int) $revision;
}

/**
 * Return saved widgets for authenticated administrative editing.
 *
 * @return list<array<string,mixed>> Bounded stored rows, including unpublished widgets.
 */
function public_widget_admin_list(): array
{
    return public_widget_model_list();
}

/**
 * Insert a new independent authored widget; initial state defaults to draft.
 *
 * Authentication and CSRF are controller responsibilities before invoking this service.
 *
 * @param array<string,mixed> $input Administrator-authored semantic draft.
 * @return string Newly allocated stable widget ID.
 */
function public_widget_create(array $input): string
{
    $row = public_widget_normalize($input);
    $id = bin2hex(random_bytes(16));
    public_widget_model_insert(['widget_id' => $id, 'revision' => 1, ...$row], gmdate('Y-m-d H:i:s'), PUBLIC_WIDGET_MAX_ITEMS);
    return $id;
}

/**
 * Save one editor draft only if no other administrator has changed the widget.
 *
 * @param string $widgetId Validated stable identifier.
 * @param scalar|array<array-key,mixed>|object|resource|null $revision Editor's expected revision.
 * @param array<string,mixed> $input Complete authoring values.
 * @return int Next revision after a successful conditional update.
 */
function public_widget_save(string $widgetId, mixed $revision, array $input): int
{
    $id = public_widget_id($widgetId);
    $expected = public_widget_revision($revision);
    $row = public_widget_normalize($input);
    if (!public_widget_model_replace(['widget_id' => $id, 'revision' => $expected, ...$row], gmdate('Y-m-d H:i:s'))) {
        throw new PublicWidgetInvalidField('revision', 'The widget changed in another tab. Reload before saving.');
    }
    return $expected + 1;
}

/**
 * Duplicate current authored content into a different, unpublished identity.
 *
 * @param string $widgetId Existing validated widget ID.
 * @return string New draft widget identifier.
 */
function public_widget_duplicate(string $widgetId): string
{
    $original = public_widget_model_find(public_widget_id($widgetId));
    if ($original === null) {
        throw new PublicWidgetInvalidField('widget_id', 'The widget no longer exists.');
    }
    $copy = public_widget_normalize($original);
    $copy['status'] = 'draft';
    return public_widget_create($copy);
}

/**
 * Delete an exact version of a widget after controller-confirmed user intent.
 *
 * @param string $widgetId Widget selected by its administrator.
 * @param scalar|array<array-key,mixed>|object|resource|null $revision Current editor revision.
 * @return void Throws on a stale or absent row.
 */
function public_widget_delete(string $widgetId, mixed $revision): void
{
    if (!public_widget_model_delete(public_widget_id($widgetId), public_widget_revision($revision))) {
        throw new PublicWidgetInvalidField('revision', 'The widget changed in another tab. Reload before deletion.');
    }
}

/**
 * Reorder selected widget identities atomically against their current revisions.
 *
 * @param list<array{widget_id:mixed,revision:mixed,sort_order:mixed}> $submitted Complete changed rows from the authorized editor.
 * @return void Throws on duplicates, invalid ordering or a stale write.
 */
function public_widget_reorder(array $submitted): void
{
    if ($submitted === [] || count($submitted) > PUBLIC_WIDGET_MAX_ITEMS) {
        throw new PublicWidgetInvalidField('sort_order', 'Choose between 1 and 48 widgets to reorder.');
    }
    $rows = [];
    $seenIds = [];
    $seenOrders = [];
    foreach ($submitted as $entry) {
        $id = public_widget_id($entry['widget_id'] ?? null);
        $revision = public_widget_revision($entry['revision'] ?? null);
        $order = $entry['sort_order'] ?? null;
        if ((!is_int($order) && (!is_string($order) || preg_match('/^(?:0|[1-9][0-9]*)$/D', $order) !== 1))
            || (float) $order > 10000 || isset($seenIds[$id]) || isset($seenOrders[(string) $order])) {
            throw new PublicWidgetInvalidField('sort_order', 'Widget order contains invalid or repeated values.');
        }
        $seenIds[$id] = true;
        $seenOrders[(string) $order] = true;
        $rows[] = ['widget_id' => $id, 'revision' => $revision, 'sort_order' => (int) $order];
    }
    if (!public_widget_model_reorder($rows, gmdate('Y-m-d H:i:s'))) {
        throw new PublicWidgetInvalidField('revision', 'Widget positions changed in another tab. Reload before reordering.');
    }
}

/**
 * Determine whether a published widget belongs on a permitted public document.
 *
 * @param array<string,mixed> $row Validated stored widget.
 * @param string $pageType Controller-authenticated 'home' or eligible 'gallery' document type.
 * @return bool True only when publication and page scope match a known public document type.
 */
function public_widget_visible_on_page(array $row, string $pageType): bool
{
    return ($row['status'] ?? '') === 'published'
        && (($pageType === 'home' && in_array($row['page_scope'] ?? '', ['home', 'all'], true))
            || ($pageType === 'gallery' && in_array($row['page_scope'] ?? '', ['gallery', 'all'], true)));
}

/**
 * Prepare only published, validated rows for an allowed public page type.
 *
 * Missing or incomplete schema never emits partial/disabled widget content.
 * This is a presentation feature: database read failure keeps the previous
 * empty public layout and must not alter access-control or security policy.
 *
 * @param string $pageType Explicit controller allowlisted 'home' or accessible 'gallery' page.
 * @return list<array<string,int|string>> Valid ordered published widget records, or an empty safe fallback.
 */
function public_widget_public_rows(string $pageType): array
{
    if (!in_array($pageType, ['home', 'gallery'], true)) {
        return [];
    }
    try {
        $rows = public_widget_model_list(true);
    } catch (Throwable) {
        return [];
    }
    $prepared = [];
    foreach ($rows as $row) {
        try {
            $valid = public_widget_normalize($row);
            if (!public_widget_visible_on_page($valid, $pageType)) {
                continue;
            }
            $prepared[] = ['widget_id' => public_widget_id($row['widget_id'] ?? null), ...$valid];
        } catch (InvalidArgumentException) {
            // Incomplete or legacy records are excluded rather than rendered in an unsafe location.
        }
    }
    return $prepared;
}


/**
 * Render emphasized widget text after escaping all user-authored HTML.
 *
 * Markdown controls are constrained to a tiny inline subset so neither Admin
 * previews nor public pages ever execute author-provided HTML.
 *
 * @param string $text Untrusted plain inline text excluding recognized link tokens.
 * @return string Escaped HTML with emphasis and code spans only.
 */
function public_widget_emphasis_html(string $text): string
{
    $escaped = htmlspecialchars($text, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    $escaped = preg_replace('/\x60([^\x60\n]+)\x60/u', '<code>$1</code>', $escaped) ?? $escaped;
    $escaped = preg_replace('/\*\*([^*\n]+)\*\*/u', '<strong>$1</strong>', $escaped) ?? $escaped;
    $escaped = preg_replace('/__([^_\n]+)__/u', '<strong>$1</strong>', $escaped) ?? $escaped;
    $escaped = preg_replace('/(?<!\*)\*([^*\n]+)\*(?!\*)/u', '<em>$1</em>', $escaped) ?? $escaped;
    $escaped = preg_replace('/(?<!_)_([^_\n]+)_(?!_)/u', '<em>$1</em>', $escaped) ?? $escaped;
    return $escaped;
}

/**
 * Resolve recognized Markdown links into safe markup without marker-token injection.
 *
 * External links intentionally open in a new tab; site-local routes and fragments
 * remain in the current tab. Invalid or unrecognized Markdown remains inert text.
 *
 * @param string $text Untrusted Markdown inline content.
 * @return string Safe escaped link and emphasis HTML.
 */
function public_widget_inline_html(string $text): string
{
    $pattern = '/(?<!\\\\)\[([^\]\r\n]{1,160})\]\(([^()\s]{1,2048})\)/u';
    $matchCount = preg_match_all($pattern, $text, $matches, PREG_OFFSET_CAPTURE);
    if ($matchCount === false || $matchCount === 0) {
        return public_widget_emphasis_html($text);
    }
    $html = '';
    $offset = 0;
    for ($index = 0; $index < $matchCount; $index++) {
        $token = (string) $matches[0][$index][0];
        $start = (int) $matches[0][$index][1];
        $html .= public_widget_emphasis_html(substr($text, $offset, $start - $offset));
        $url = (string) $matches[2][$index][0];
        try {
            $href = public_widget_safe_url($url);
        } catch (PublicWidgetInvalidField) {
            $html .= public_widget_emphasis_html($token);
            $offset = $start + strlen($token);
            continue;
        }
        $external = preg_match('~^https?://~i', $href) === 1;
        $attributes = $external ? ' target="_blank" rel="noopener noreferrer"' : '';
        $html .= '<a href="' . htmlspecialchars($href, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') . '"' . $attributes . '>'
            . public_widget_emphasis_html((string) $matches[1][$index][0]) . '</a>';
        $offset = $start + strlen($token);
    }
    return $html . public_widget_emphasis_html(substr($text, $offset));
}

/**
 * Flush pending Markdown paragraph and list fragments without dynamic callables.
 *
 * The runtime module compiler requires explicit function dependency edges,
 * so this isolated helper is invoked directly instead of a variable closure.
 *
 * @param list<string> $blocks Completed HTML block fragments, mutated in place.
 * @param list<string> $paragraph Pending escaped paragraph source lines, consumed in place.
 * @param list<string> $listItems Pending safe HTML list items, consumed in place.
 * @param string $listTag Current list element name, cleared after flushing.
 * @return void Appends safe blocks to the output accumulator.
 */
function public_widget_flush_markdown_blocks(array &$blocks, array &$paragraph, array &$listItems, string &$listTag): void
{
    if ($paragraph !== []) {
        $parts = [];
        foreach ($paragraph as $line) {
            $parts[] = public_widget_inline_html($line);
        }
        $blocks[] = '<p>' . implode('<br>', $parts) . '</p>';
        $paragraph = [];
    }
    if ($listItems !== []) {
        $blocks[] = '<' . $listTag . '>' . implode('', $listItems) . '</' . $listTag . '>';
        $listItems = [];
        $listTag = '';
    }
}

/**
 * Render the bounded, intentionally small Markdown subset shared by Admin and public pages.
 *
 * Supported blocks: paragraphs, hard line breaks, level 1-3 headings, ordered and
 * unordered lists. Links, bold, italic and inline code share one escaping policy.
 * HTML blocks, embedded media, CSS and arbitrary scripting remain inert source text.
 *
 * @param string $markdown Untrusted UTF-8 widget content from a stored or unsaved draft.
 * @return string Safe HTML fragment, never a complete document or executable markup.
 */
function public_widget_markdown_html(string $markdown): string
{
    if (strlen($markdown) > PUBLIC_WIDGET_CONTENT_MAX_BYTES || preg_match('//u', $markdown) !== 1) {
        return '';
    }
    $source = trim(str_replace(["\r\n", "\r"], "\n", $markdown));
    if ($source === '') {
        return '';
    }
    $blocks = [];
    $paragraph = [];
    $listItems = [];
    $listTag = '';
    foreach (explode("\n", $source) as $line) {
        if (trim($line) === '') {
            public_widget_flush_markdown_blocks($blocks, $paragraph, $listItems, $listTag);
            continue;
        }
        if (preg_match('/^\s{0,3}(#{1,3})\s+(.+)$/u', $line, $heading) === 1) {
            public_widget_flush_markdown_blocks($blocks, $paragraph, $listItems, $listTag);
            $level = min(4, strlen($heading[1]) + 1);
            $blocks[] = '<h' . $level . '>' . public_widget_inline_html(trim($heading[2])) . '</h' . $level . '>';
            continue;
        }
        if (preg_match('/^\s{0,3}(?:[-*+]\s+([^\r\n]+)|[0-9]{1,3}[.)]\s+([^\r\n]+))$/u', $line, $item) === 1) {
            if ($paragraph !== []) {
                public_widget_flush_markdown_blocks($blocks, $paragraph, $listItems, $listTag);
            }
            $tag = isset($item[2]) && $item[2] !== '' ? 'ol' : 'ul';
            if ($listTag !== '' && $listTag !== $tag) {
                public_widget_flush_markdown_blocks($blocks, $paragraph, $listItems, $listTag);
            }
            $listTag = $tag;
            $itemText = $tag === 'ol' ? $item[2] : $item[1];
            $listItems[] = '<li>' . public_widget_inline_html(trim($itemText)) . '</li>';
            continue;
        }
        if ($listItems !== []) {
            public_widget_flush_markdown_blocks($blocks, $paragraph, $listItems, $listTag);
        }
        $paragraph[] = trim($line);
    }
    public_widget_flush_markdown_blocks($blocks, $paragraph, $listItems, $listTag);
    return implode("\n", $blocks);
}
