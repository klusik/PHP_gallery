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
];
