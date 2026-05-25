<?php

/**
 * Project: PHP Gallery
 * Repository: https://github.com/klusik/PHP_gallery
 *
 * File: app/views/simbrief_descriptions.php
 * Module Type: View Module
 *
 * Purpose:
 *   Converts normalized SimBrief flight details into an editable Markdown draft.
 *
 * Responsibilities:
 *   - Keep prose and Markdown formatting out of the SimBrief data service
 *   - Preserve safe escaping for generated gallery descriptions
 *   - Return plain strings that the normal gallery editor can still modify
 *
 * Author:
 *   Rudolf Klusal
 *
 * Contact:
 *   https://github.com/klusik
 *
 * License:
 *   MIT License (see LICENSE file in repository)
 *
 * Notes:
 *   - Keep comments and docstrings intact when modifying this file.
 *   - Prefer small, readable changes over broad rewrites.
 *
 * Last Updated:
 *   2026-05-24
 */

declare(strict_types=1);

function view_simbrief_description_markdown(array $details): string
{
    $originCode = view_simbrief_markdown_code($details['origin_code'] ?? '');
    $destinationCode = view_simbrief_markdown_code($details['destination_code'] ?? '');
    $originLabel = view_simbrief_place_label((string) ($details['origin_name'] ?? ''), $originCode);
    $destinationLabel = view_simbrief_place_label((string) ($details['destination_name'] ?? ''), $destinationCode);
    $aircraft = view_simbrief_markdown_text((string) ($details['aircraft'] ?? ''));
    $flightLabel = view_simbrief_markdown_code((string) ($details['flight_label'] ?? ''));

    $openingParts = [];
    $opening = '**' . $originCode . ' to ' . $destinationCode . '** was planned as';
    if ($aircraft !== '') {
        $opening .= ' ' . view_simbrief_indefinite_article($aircraft) . ' ' . $aircraft . ' sector';
    } else {
        $opening .= ' a flight-sim sector';
    }
    $opening .= ' from ' . $originLabel . ' to ' . $destinationLabel . '.';
    $openingParts[] = $opening;

    $dispatchContext = [];
    if ($flightLabel !== '') {
        $dispatchContext[] = 'flight `' . $flightLabel . '`';
    }
    if (($details['cruise'] ?? '') !== '') {
        $dispatchContext[] = 'a planned cruise at `' . view_simbrief_markdown_code((string) $details['cruise']) . '`';
    }
    if (($details['ete'] ?? '') !== '') {
        $dispatchContext[] = 'an estimated en-route time of about ' . view_simbrief_markdown_text((string) $details['ete']);
    }
    if ($dispatchContext !== []) {
        $openingParts[] = 'The OFP gives it ' . view_simbrief_join_phrase($dispatchContext) . '.';
    }

    $paragraphs = [implode(' ', $openingParts)];

    $routeParts = [];
    if (($details['route'] ?? '') !== '') {
        $routeParts[] = 'SimBrief filed the route as `' . view_simbrief_markdown_code(view_simbrief_shorten((string) $details['route'], 300)) . '`.';
    }
    $runwayParts = [];
    if (($details['origin_runway'] ?? '') !== '') {
        $runwayParts[] = 'departure runway ' . view_simbrief_markdown_text((string) $details['origin_runway']);
    }
    if (($details['destination_runway'] ?? '') !== '') {
        $runwayParts[] = 'arrival runway ' . view_simbrief_markdown_text((string) $details['destination_runway']);
    }
    if ($runwayParts !== []) {
        $routeParts[] = 'The plan also notes ' . view_simbrief_join_phrase($runwayParts) . '.';
    }
    if (($details['alternate_code'] ?? '') !== '') {
        $routeParts[] = 'The listed alternate is `' . view_simbrief_markdown_code((string) $details['alternate_code']) . '`.';
    }
    if ($routeParts !== []) {
        $paragraphs[] = implode(' ', $routeParts);
    }

    $dispatchParts = [];
    if (($details['distance'] ?? '') !== '') {
        $dispatchParts[] = 'planned distance ' . view_simbrief_markdown_text((string) $details['distance']);
    }
    if (($details['passengers'] ?? '') !== '') {
        $dispatchParts[] = view_simbrief_markdown_text((string) $details['passengers']) . ' passengers';
    }
    if (($details['fuel'] ?? '') !== '') {
        $dispatchParts[] = 'dispatch fuel ' . view_simbrief_markdown_text((string) $details['fuel']);
    }
    if (($details['airac'] ?? '') !== '') {
        $dispatchParts[] = 'AIRAC ' . view_simbrief_markdown_text((string) $details['airac']);
    }
    if ($dispatchParts !== []) {
        $paragraphs[] = 'For the gallery, this gives the flight a clean dispatch-log frame: ' . view_simbrief_join_phrase($dispatchParts) . '. The photos can sit around that planned story rather than only showing a raw route string.';
    }

    return trim(implode("\n\n", $paragraphs));
}

function view_simbrief_indefinite_article(string $label): string
{
    $trimmed = trim($label);
    if ($trimmed === '') {
        return 'a';
    }

    $upper = strtoupper($trimmed);
    if (preg_match('/^(A|E|F|H|I|L|M|N|O|R|S|X)([^A-Z]|$)/', $upper) === 1) {
        return 'an';
    }

    $first = strtolower(substr($trimmed, 0, 1));
    return in_array($first, ['a', 'e', 'i', 'o', 'u'], true) ? 'an' : 'a';
}

function view_simbrief_markdown_text(string $value): string
{
    $value = simbrief_description_plain_text($value);
    $value = str_replace(['\\', '`', '*', '_', '[', ']', '<', '>'], ['\\\\', '\`', '\*', '\_', '\[', '\]', '', ''], $value);
    return trim($value);
}

function view_simbrief_markdown_code(string $value): string
{
    $value = simbrief_description_plain_text($value);
    $value = str_replace('`', "'", $value);
    return trim($value);
}

function view_simbrief_place_label(string $name, string $code): string
{
    $name = view_simbrief_markdown_text($name);
    $code = view_simbrief_markdown_code($code);
    if ($name !== '') {
        return $name . ' (`' . $code . '`)';
    }
    return '`' . $code . '`';
}

function view_simbrief_shorten(string $value, int $limit): string
{
    $value = simbrief_description_plain_text($value);
    if ($limit <= 0) {
        return '';
    }
    $length = function_exists('mb_strlen') ? mb_strlen($value, 'UTF-8') : strlen($value);
    if ($length <= $limit) {
        return $value;
    }
    $cut = function_exists('mb_substr') ? (string) mb_substr($value, 0, max(1, $limit - 6), 'UTF-8') : substr($value, 0, max(1, $limit - 6));
    $cut = preg_replace('/\s+\S*$/u', '', $cut) ?: $cut;
    return rtrim($cut, ' ,;:') . ' (...)';
}

function view_simbrief_join_phrase(array $parts): string
{
    $parts = array_values(array_filter(array_map(static fn (string $part): string => trim($part), $parts), static fn (string $part): bool => $part !== ''));
    $count = count($parts);
    if ($count === 0) {
        return '';
    }
    if ($count === 1) {
        return $parts[0];
    }
    if ($count === 2) {
        return $parts[0] . ' and ' . $parts[1];
    }
    $last = array_pop($parts);
    return implode(', ', $parts) . ', and ' . $last;
}
