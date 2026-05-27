<?php

/**
 * Project: PHP Gallery
 * Repository: https://github.com/klusik/PHP_gallery
 *
 * File: tests/simbrief_description_model_test.php
 * Module Type: Test Script
 *
 * Purpose:
 *   Verifies SimBrief description generation without requiring a live SimBrief
 *   request or a database connection.
 *
 * Responsibilities:
 *   - Cover Pilot ID and pilot-name identifier normalization
 *   - Cover extraction from representative SimBrief JSON v2-style fields
 *   - Cover safe Markdown generation for the gallery description renderer
 *   - Remain executable with plain PHP on shared-hosting style environments
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

require_once __DIR__ . '/../app/services/simbrief_descriptions.php';

/**
 * Throw when a SimBrief model expectation fails.
 *
 * @param mixed $expected Expected value.
 * @param mixed $actual Actual value.
 * @param string $label Human-readable assertion label.
 * @return void
 */
function assert_simbrief_description_same(mixed $expected, mixed $actual, string $label): void
{
    if ($expected !== $actual) {
        throw new RuntimeException($label . ' expected ' . var_export($expected, true) . ', got ' . var_export($actual, true));
    }
}

/**
 * Throw when a generated description does not contain required text.
 *
 * @param string $needle Required text.
 * @param string $haystack Generated description.
 * @param string $label Human-readable assertion label.
 * @return void
 */
function assert_simbrief_description_contains(string $needle, string $haystack, string $label): void
{
    if (!str_contains($haystack, $needle)) {
        throw new RuntimeException($label . ' missing ' . var_export($needle, true) . ' in ' . var_export($haystack, true));
    }
}

$id = simbrief_description_identifier('123456', 'Ignored Name');
assert_simbrief_description_same('userid', $id['kind'], 'Pilot ID identifier kind');
assert_simbrief_description_same('123456', $id['value'], 'Pilot ID identifier value');

$name = simbrief_description_identifier('', 'Rudolf Pilot');
assert_simbrief_description_same('username', $name['kind'], 'pilot name identifier kind');
assert_simbrief_description_same('Rudolf Pilot', $name['value'], 'pilot name identifier value');

$payload = [
    'origin' => [
        'icao_code' => 'LKPR',
        'name' => 'Václav Havel Airport Prague',
        'plan_rwy' => '24',
    ],
    'destination' => [
        'icao_code' => 'EDDM',
        'name' => 'Munich Airport',
        'plan_rwy' => '26R',
    ],
    'alternate' => [
        'icao_code' => 'EDDF',
    ],
    'general' => [
        'icao_airline' => 'CY',
        'flight_number' => '1004',
        'route' => 'LKPR DCT OKL DCT EDDM',
        'initial_altitude' => '35000',
        'route_distance' => '182',
    ],
    'aircraft' => [
        'icao_code' => 'A21N',
        'name' => 'Airbus A321neo',
    ],
    'times' => [
        'est_time_enroute' => '0125',
    ],
    'fuel' => [
        'plan_ramp' => '6100',
    ],
    'weights' => [
        'pax_count' => '184',
    ],
    'params' => [
        'units' => 'KGS',
        'airac' => '2505',
    ],
    'files' => [
        'directory' => 'https://www.simbrief.com/ofp/flightplans',
        'pdf' => 'LKPREDDM_PDF_1779895813.84b5b08c.pdf',
    ],
];

$details = simbrief_description_extract_details($payload);
assert_simbrief_description_same('LKPR', $details['origin_code'], 'origin code');
assert_simbrief_description_same('EDDM', $details['destination_code'], 'destination code');
assert_simbrief_description_same('Airbus A321neo (A21N)', $details['aircraft'], 'aircraft label');
assert_simbrief_description_same('FL350', $details['cruise'], 'cruise altitude');
assert_simbrief_description_same('1 h 25 min', $details['ete'], 'en-route duration');
assert_simbrief_description_same('6 100 kg', $details['fuel'], 'fuel format');

$description = simbrief_description_build_markdown($details);
assert_simbrief_description_contains('**LKPR to EDDM**', $description, 'route heading');
assert_simbrief_description_contains('`CY1004`', $description, 'flight number');
assert_simbrief_description_contains('`LKPR DCT OKL DCT EDDM`', $description, 'filed route');
assert_simbrief_description_contains('184 passengers', $description, 'passenger count');


$routePayload = $payload;
$routePayload['origin']['pos_lat'] = '50.1008';
$routePayload['origin']['pos_long'] = '14.2632';
$routePayload['destination']['pos_lat'] = '48.3538';
$routePayload['destination']['pos_long'] = '11.7861';
$routePayload['navlog'] = [
    'fix' => [
        ['ident' => 'OKL', 'pos_lat' => '50.0967', 'pos_long' => '13.0256'],
        ['ident' => 'BODAL', 'pos_lat' => '4916.8', 'pos_long' => '01217.4'],
    ],
];
$routePoints = simbrief_description_extract_route_points($routePayload, $details);
assert_simbrief_description_same(4, count($routePoints), 'SimBrief route point count');
assert_simbrief_description_same('start', $routePoints[0]['role'], 'SimBrief route start role');
assert_simbrief_description_same('via', $routePoints[1]['role'], 'SimBrief route via role');
assert_simbrief_description_same('end', $routePoints[3]['role'], 'SimBrief route end role');
assert_simbrief_description_same('LKPR OKL BODAL EDDM', simbrief_description_route_text_from_points($routePoints, $details), 'SimBrief route text from OFP points');
assert_simbrief_description_same(49.28, round((float) $routePoints[2]['latitude'], 2), 'compact latitude parsing');
assert_simbrief_description_same(12.29, round((float) $routePoints[2]['longitude'], 2), 'compact longitude parsing');
assert_simbrief_description_same(-2.72, round((float) simbrief_description_coordinate_value('W00243.2', 'lon'), 2), 'west compact longitude parsing');
assert_simbrief_description_same('https://www.simbrief.com/ofp/flightplans/LKPREDDM_PDF_1779895813.84b5b08c.pdf', simbrief_description_pdf_url($payload), 'SimBrief PDF URL normalization');

$unsafeDetails = $details;
$unsafeDetails['aircraft'] = 'A*321 <script>';
$unsafeDescription = simbrief_description_build_markdown($unsafeDetails);
assert_simbrief_description_contains('A\*321 script', $unsafeDescription, 'Markdown escaping');

try {
    simbrief_description_identifier('', '');
    throw new RuntimeException('missing identifier did not throw');
} catch (RuntimeException $exception) {
    assert_simbrief_description_contains('Enter either', $exception->getMessage(), 'missing identifier message');
}

echo "SimBrief description model tests passed.\n";
