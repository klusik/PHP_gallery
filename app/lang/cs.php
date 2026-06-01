<?php

/**
 * Project: PHP Gallery
 * Repository: https://github.com/klusik/PHP_gallery
 *
 * File: app/lang/cs.php
 * Module Type: Language File
 *
 * Purpose:
 *   Stores Czech translation strings for the gallery application.
 *
 * Responsibilities:
 *   - Provide Czech strings for keys that are already wired through t()
 *   - Keep untranslated areas on the English fallback path
 *   - Allow the language selector to produce visible, testable changes
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
 *   2026-05-10
 */

return [
    'language.selector_label' => 'Jazyk',
    'language.english' => 'English',
    'language.czech' => 'Česky',
    'gallery.actions' => 'Akce galerie',
    'gallery.download' => 'Stáhnout galerii',
    'gallery.show_map' => 'Zobrazit mapu galerie',
    'gallery.play_picture_game' => 'Spustit obrázkovou hru',
    'gallery.add_here' => 'Přidat galerii sem',
    'gallery.add_inside' => 'Přidat galerii do {title}',
    'gallery.edit_current' => 'Upravit aktuální galerii',
    'gallery.edit_named' => 'Upravit galerii {title}',
    'gallery.workflow' => 'Práce s galerií',
    'gallery.editor' => 'Editor galerie',
    'gallery.edit' => 'Upravit galerii',
    'admin.gallery_editor.gallery_date' => 'Datum',
    'admin.gallery_editor.gallery_date_help' => 'Volitelné ručně zadané datum galerie, například datum akce, výletu nebo focení.',
    'admin.gallery_editor.invalid_gallery_date' => 'Zadejte platné datum galerie.',
    'admin.menu.edit_tags' => 'Upravit štítky',
    'admin.tags.title' => 'Upravit štítky',
    'admin.tags.saved' => 'Štítek byl uložen.',
    'admin.tags.deleted' => 'Štítek byl smazán.',
    'admin.tags.error_delete_failed' => 'Štítek se nepodařilo smazat.',
    'gallery.tag_editor' => 'Editor štítku',
    'gallery.edit_tag' => 'Upravit štítek',
    'gallery.edit_tag_named' => 'Upravit štítek {name}',
    'gallery.remove_tag_named' => 'Odebrat štítek {name} z CMS',    'js.admin.date_picker.open' => 'Otevřít kalendář',    'js.admin.date_picker.today' => 'Dnes',    'js.admin.date_picker.delete' => 'Smazat',
    'admin.theme.media.theme_background_image_hint' => 'Nahrajte obrázek, který má zůstat uložený jako originál. Galerie může návštěvníkům servírovat menší WebP kopii.',
    'admin.theme.media.background_optimized_size' => 'Optimalizovaná velikost zobrazení',
    'admin.theme.media.background_optimized_size_hint' => 'Pro běžné obrazovky použijte 1920 px, pro velmi velké displeje 2560 px nebo více.',
    'admin.theme.media.view_served_image' => 'Zobrazit použitý obrázek',
    'admin.theme.media.view_original_image' => 'Zobrazit originál',
    'admin.theme.media.current_theme_background_alt' => 'Náhled vybraného pozadí',
    'admin.theme.media.background_selected' => 'Pozadí je vybrané',
    'admin.theme.media.background_not_selected' => 'Pozadí není vybrané',
    'admin.theme.media.background_optimized_ready' => 'Optimalizovaný WebP je aktivní',
    'admin.theme.media.background_serving_original' => 'Používá se původní obrázek',
    'admin.theme.media.background_optimized_size_value' => 'Nejdelší strana {size} px',
    'admin.theme.media.generate_optimized_background' => 'Vytvořit optimalizované pozadí',
    'admin.theme.media.regenerate_optimized_background' => 'Znovu vytvořit optimalizované pozadí',
    'admin.theme.media.delete_optimized_background' => 'Smazat optimalizovanou kopii',
    'admin.theme.media.optimized_webp_active' => 'optimalizovaný WebP je aktivní',
    'admin.theme.media.optimized_webp_unavailable' => 'optimalizovaný WebP není dostupný, používá se originál',
    'admin.theme.layout.description_layout_horizontal_summary' => 'Nejdřív obrázek, potom kompaktní karta s příběhem.',
    'admin.theme.layout.description_layout_vertical_summary' => 'Obrázek a text vedle sebe, blízko klasickému vzhledu galerie.',
    'admin.theme.layout.description_preview_title' => 'Letní galerie',
    'admin.theme.layout.description_preview_meta' => '12 fotek',
    'admin.dashboard.notice_url_rewrite_saved' => 'Nastavení URL rewrite bylo uloženo.',
    'admin.dashboard.url_rewrite_warning_unknown_reason' => 'Podpora URL rewrite nebyla detekována.',
    'admin.dashboard.url_rewrite_warning_title' => 'URL rewrite je zapnutý, ale podpora nebyla detekována.',
    'admin.dashboard.url_rewrite_warning_hint' => 'Veřejné odkazy budou podle možností padat zpět na index.php URL. Zkontrolujte .htaccess, mod_rewrite, nebo URL rewrite níže vypněte, pokud ho hosting nepodporuje.',
    'admin.dashboard.url_rewrite_status_disabled' => 'Záměrně vypnuto',
    'admin.dashboard.url_rewrite_status_supported' => 'Podporováno',
    'admin.dashboard.url_rewrite_status_likely_supported' => 'Pravděpodobně podporováno',
    'admin.dashboard.url_rewrite_status_unsupported' => 'Nedetekováno',
    'admin.dashboard.url_rewrite_status_unknown' => 'Neznámé',
    'admin.dashboard.url_rewrite_reason_unknown' => 'Pro tento požadavek není dostupný detailní signál kompatibility.',
    'admin.dashboard.url_rewrite_title' => 'URL rewrite',
    'admin.dashboard.url_rewrite_hint' => 'Čisté veřejné URL jsou ve výchozím stavu zapnuté. Vypínejte je jen tehdy, když hosting neumí spolehlivě směrovat přepsané cesty.',
    'admin.dashboard.url_rewrite_enable_clean_urls' => 'Používat čisté přepsané veřejné URL',
    'admin.dashboard.url_rewrite_detected_status' => 'Detekovaný stav:',
    'admin.dashboard.url_rewrite_save' => 'Uložit URL rewrite',
    'gallery.card.unpublished_admin_hint' => 'Tuto galerii ve vypisech vidi pouze prihlaseni administratori.',
    'admin.updates.force_check_button' => 'Vynutit kontrolu',
    'admin.updates.force_check_completed' => 'Vynucená kontrola aktualizací přes GitHub byla dokončena.',
    'admin.updates.force_check_completed_with_error' => 'Vynucená kontrola přes GitHub byla dokončena s varováním: {error}',
    'admin.updates.force_check_hint' => 'Obejde místní pětihodinovou cache a zeptá se GitHubu hned. Hlavičky rate limitu z GitHubu se po odpovědi stále uloží a respektují.',
    'admin.updates.github_api_etag' => 'ETag',
    'admin.updates.github_api_hint' => 'Updater používá hlavičky z běžných odpovědí GitHub API. Nevolá /rate_limit jen kvůli zjištění limitů.',
    'admin.updates.github_api_kicker' => 'Zásady GitHub API',
    'admin.updates.github_api_last_checked' => 'Poslední odpověď GitHub API',
    'admin.updates.github_api_next_allowed' => 'Další povolená kontrola: {time}',
    'admin.updates.github_api_remaining' => 'Zbývající kvóta',
    'admin.updates.github_api_reset' => 'Reset primárního limitu',
    'admin.updates.github_api_cache_hit' => 'obslouženo z lokální ETag cache',
    'admin.updates.github_api_status_code' => 'Poslední HTTP stav',
    'admin.updates.github_api_resource' => 'Zdroj',
    'admin.updates.github_api_title' => 'Stav rate limitu',
    'admin.updates.github_api_used' => 'Použitá kvóta',
    'admin.updates.github_api_waiting' => 'Čekání',
    'admin.gallery_editor.flight_route_map' => 'Letová mapa trasy',
    'admin.gallery_editor.flight_route_label' => 'Text trasy',
    'admin.gallery_editor.flight_route_help' => 'Pro simflying galerie může tato galerie uložit jednu vyřešenou mapu trasy. Body trasy se vyřeší při uložení galerie. Veřejná mapa dostane pouze uložené souřadnice. Nevyřešené body se přeskočí a platné body zůstanou propojené. Použijte Admin > Maintenance > Update navdata pro import letišť a radionavigačních bodů z OurAirports do lokální DB, nebo ruční body NAME@latitude,longitude.',
    'admin.gallery_editor.flight_route_status' => 'Vyřešené body: {points}. Přeskočené nevyřešené body: {unresolved}.',
    'admin.gallery_editor.flight_route_migration_hidden' => 'Ovládání letové mapy trasy bude dostupné po použití databázové migrace.',
    'admin.gallery_editor.flight_route_saved_notice' => 'Letová trasa uložena s {points} vyřešenými body; {unresolved} nevyřešených bodů bylo přeskočeno.',
    'admin.dashboard.confirm_update_navdata' => 'Stáhnout aktuální letiště a radionavigační body z OurAirports a nahradit lokální OurAirports lookup řádky?',
    'admin.dashboard.flight_navdata' => 'Flight map navdata',
    'admin.dashboard.flight_navdata_requires_migration' => 'Před importem flight-map navdata spusťte databázové migrace.',
    'admin.dashboard.update_navdata' => 'Update navdata',
    'admin.dashboard.flight_navdata_status' => 'Lokální lookup řádky: {total}. Poslední update: {updated}. Poslední import: {airports} identifikátorů letišť, {navaids} radionavigačních bodů, {skipped} přeskočených řádků.',
    'admin.dashboard.flight_navdata_empty' => 'Zatím nebyla importována žádná lokální route lookup data. Mapy tras mohou stále používat ruční body NAME@latitude,longitude.',
    'admin.dashboard.flight_navdata_scope_hint' => 'Importuje letiště a radionavigační body z OurAirports. Neobsahuje úplné IFR fixy ani geometrii SID/STAR procedur.',
    'admin.dashboard.updating_navdata' => 'Aktualizuji navdata...',
    'admin.dashboard.navdata_update_in_progress' => 'Stahuji a importuji navdata. Nechte tuto stránku otevřenou, dokud aktualizace neskončí.',
    'admin.dashboard.notice_navdata_updated' => 'Flight-map navdata aktualizována. Importováno {airports} identifikátorů letišť, {navaids} radionavigačních bodů, přeskočeno {skipped} řádků, odstraněno {deleted} zastaralých řádků.',
    'admin.dashboard.notice_navdata_failed' => 'Update flight-map navdata selhal: {error}',
    'admin.theme.media.separator_width' => 'Šířka oddělovače',
    'admin.theme.media.separator_width_hint' => 'Pixely. Hodnota 0 ponechá aktuální responzivní šířku stránky.',
    'admin.theme.media.separator_height' => 'Výška oddělovače',
    'admin.theme.media.separator_height_hint' => 'Pixely. Při zachování poměru stran jde o maximum; při roztažení jde o přesnou výšku vykreslení.',
    'admin.theme.media.kicker' => 'Branding a média',
    'admin.theme.media.title' => 'Branding hlavičky, oddělovač, favicon a pozadí',
    'admin.theme.media.description' => 'Nejdřív nastavte obrázky veřejné hlavičky, potom identitu prohlížeče a výchozí pozadí galerií.',
    'admin.theme.tab_media' => 'Branding a média',
    'admin.theme.media.public_header_banner' => 'Banner veřejné hlavičky',
    'admin.theme.media.public_header_banner_hint' => 'Nahrajte výchozí banner veřejné hlavičky. Nahradí viditelný název webu, pokud galerie nemá vlastní banner.',
    'admin.theme.media.public_header_separator' => 'Oddělovač veřejné hlavičky',
    'admin.theme.media.public_header_separator_hint' => 'Nahrajte a nastavte velikost dekorativního vodorovného oddělovače pod sdílenou veřejnou hlavičkou. Oddělovač nastavený přímo v galerii má na stránce dané galerie stále přednost.',
    'admin.theme.media.separator_stretch' => 'Roztáhnout na přesnou šířku a výšku',
    'admin.theme.media.separator_stretch_hint' => 'Umožní škálovat oddělovač neproporcionálně, bez zachování původního poměru stran.',
];
