<?php

/**
 * Project: PHP Gallery
 * Repository: https://github.com/klusik/PHP_gallery
 * File: tests/fixtures/breadcrumbs.php
 * Module Type: Browser Fixture
 * Purpose: Render the production breadcrumb component with realistic path lengths.
 * Responsibilities: Supply isolated helpers and call the real breadcrumb view renderer.
 * Author: Rudolf Klusal
 * License: MIT License (see LICENSE file in repository)
 */

declare(strict_types=1);

namespace Gallery\Core {
    /**
     * Escape one value using the application-compatible HTML escaping policy.
     *
     * @param scalar|array<array-key,mixed>|object|resource|null $value Value to encode for HTML output.
     * @return string Escaped HTML value.
     */
    function e(mixed $value): string
    {
        return htmlspecialchars((string) $value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }
}

namespace Gallery\Services {
    /**
     * Return the provided fallback text for the browser fixture.
     *
     * @param string $key Translation key requested by the component.
     * @param string|array|null $fallback Fallback text or interpolation data.
     * @param array<string,scalar|null> $parameters Optional interpolation parameters.
     * @return string Fixture translation text.
     */
    function t(string $key, string|array|null $fallback = null, array $parameters = []): string
    {
        return is_string($fallback) ? $fallback : $key;
    }
}

namespace {
    require_once dirname(__DIR__, 2) . '/app/services/breadcrumbs.php';
    require_once dirname(__DIR__, 2) . '/app/views/breadcrumbs.php';

    $breadcrumbRegistry = \Gallery\Services\breadcrumb_style_registry();
    $breadcrumbStyleIds = array_keys($breadcrumbRegistry);
    $pickerItems = [
        ['label' => 'Home'],
        ['label' => 'Europe'],
        ['label' => 'Current', 'current' => true],
    ];
    $pickerOptions = [[
        'value' => 'inherit',
        'label' => 'Inherit from Theme',
        'preview' => ['style_class' => $breadcrumbRegistry['chevron']['class'], 'items' => $pickerItems],
    ]];
    foreach ($breadcrumbRegistry as $styleId => $definition) {
        $pickerOptions[] = [
            'value' => (string) $styleId,
            'label' => (string) $definition['label'],
            'preview' => ['style_class' => (string) $definition['class'], 'items' => $pickerItems],
        ];
    }
    $pickerViewModel = [
        'field_name' => 'fixture_breadcrumb_style_light',
        'label' => 'Breadcrumb style',
        'current' => 'inherit',
        'options' => $pickerOptions,
    ];
    $viewModel = [
        'aria_label' => 'Breadcrumb path',
        'style_class' => $breadcrumbRegistry['chevron']['class'],
        'items' => [
            ['label' => 'Home', 'url' => '/'],
            ['label' => 'Europe', 'url' => '/gallery/europe'],
            ['label' => 'A very long uninterrupted ancestor name that should wrap gracefully 01234567890123456789012345678901234567890', 'url' => '/gallery/europe/long-name'],
            ['label' => 'Deep route with a query', 'url' => '/gallery/europe/long-name/deep?sort=date&direction=asc'],
            ['label' => 'Current collection', 'current' => true],
        ],
    ];

    echo '<!doctype html><html lang="en"><head><meta charset="utf-8">';
    echo '<meta name="viewport" content="width=device-width, initial-scale=1">';
    echo '<title>Breadcrumb browser fixture</title>';
    foreach ([
        '/public/assets/styles/base.css',
        '/public/assets/styles/public.css',
        '/public/assets/styles/lightbox.css',
        '/public/assets/styles/public-shared.css',
        '/public/assets/styles/utilities.css',
        '/public/assets/styles.css',
        '/public/assets/styles/breadcrumbs.css',
    ] as $stylesheet) {
        echo '<link rel="stylesheet" href="' . $stylesheet . '">';
    }
    echo '<style id="fixture-style">html,body{min-height:100%}.host{width:320px;max-width:900px;margin:0 auto 24px}#light{--ink:#172033;--muted:#667085;--accent:#2962d0;--accent-dark:#174ea6;--paper:#fff;--panel:#f3f6fa;--gallery-panel:#f3f6fa;--line:#bdc7d5;--radius:12px}#dark{--ink:#f5f7fb;--muted:#aab6c6;--accent:#9bc1ff;--accent-dark:#9bc1ff;--paper:#17212e;--panel:#253243;--gallery-panel:#253243;--line:#586779;--radius:12px;background:#17212e;color:var(--ink)}</style>';
    echo '</head><body class="public-page">';
    echo '<div id="light" class="host public-page"><main class="site-main"><section class="hero"><div class="hero-primary"><h1>Gallery path</h1>';
    \Gallery\Views\view_render_breadcrumbs($viewModel);
    \Gallery\Views\view_render_breadcrumb_style_picker($pickerViewModel);
    echo '</div></section></main></div><div id="dark" class="host public-page" data-theme="dark"><main class="site-main"><section class="hero"><div class="hero-primary"><h1>Gallery path</h1>';
    \Gallery\Views\view_render_breadcrumbs($viewModel);
    $pickerViewModel['field_name'] = 'fixture_breadcrumb_style_dark';
    \Gallery\Views\view_render_breadcrumb_style_picker($pickerViewModel);
    echo '</div></section></main></div><iframe id="narrow-frame" title="Narrow breadcrumb layout" width="320" height="520" style="width:320px;height:520px;border:0"></iframe><pre id="results">BROWSER PENDING</pre>';
    echo str_replace('__BREADCRUMB_STYLE_IDS__', (string) json_encode($breadcrumbStyleIds, JSON_THROW_ON_ERROR | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT), <<<'HTML'
<script>
(async () => {
    try {
        const styles = __BREADCRUMB_STYLE_IDS__;
        const hosts = [document.querySelector('#light'), document.querySelector('#dark')];
        const check = (condition, label) => { if (!condition) throw new Error(label); };
        let checks = 0;
        const narrowFrame = document.querySelector('#narrow-frame');
        const narrowReady = new Promise((resolve, reject) => {
            narrowFrame.addEventListener('load', resolve, {once: true});
            setTimeout(() => reject(new Error('320px fixture did not load')), 10000);
        });
        const styleLinks = [...document.querySelectorAll('link[rel="stylesheet"]')]
            .map(link => `<link rel="stylesheet" href="${link.href}">`).join('');
        const lightHost = hosts[0];
        narrowFrame.srcdoc = `<!doctype html><html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1">${styleLinks}<style>${document.querySelector('#fixture-style').textContent}</style></head><body class="public-page"><div id="light" class="host public-page">${lightHost.innerHTML}</div></body></html>`;
        await narrowReady;
        const narrowWindow = narrowFrame.contentWindow;
        const narrowDocument = narrowFrame.contentDocument;
        check(narrowWindow.innerWidth === 320, 'narrow fixture must use a 320px viewport'); checks++;
        check([...document.querySelectorAll('link[rel="stylesheet"]'), ...narrowDocument.querySelectorAll('link[rel="stylesheet"]')].every(link => link.sheet !== null), 'all production stylesheet dependencies must load'); checks++;
        for (const style of styles) {
            const narrowHost = narrowDocument.querySelector('#light');
            const narrowNav = narrowHost.querySelector('nav.breadcrumbs');
            narrowNav.className = `breadcrumbs breadcrumbs--${style}`;
            const list = narrowNav.querySelector('ol.breadcrumbs__list');
            const narrowLinks = [...narrowNav.querySelectorAll('a.breadcrumbs__link')];
            const current = narrowNav.querySelector('.breadcrumbs__current[aria-current="page"]');
            check(narrowNav.getAttribute('aria-label') === 'Breadcrumb path', 'accessible nav label'); checks++;
            check(list.children.length === 5 && narrowLinks.length === 4 && current, 'ordered path and current-page semantics'); checks++;
            check(current.tagName === 'SPAN' && current.parentElement === list.lastElementChild
                && parseInt(narrowWindow.getComputedStyle(current).fontWeight, 10) > parseInt(narrowWindow.getComputedStyle(narrowLinks[3]).fontWeight, 10),
                'current destination remains emphasized after its ancestors in the hierarchy'); checks++;
            check(narrowLinks[0].getAttribute('href') === '/' && narrowLinks[3].getAttribute('href') === '/gallery/europe/long-name/deep?sort=date&direction=asc', 'preserved root and deep URLs'); checks++;
            check(narrowNav.scrollWidth <= narrowNav.clientWidth, 'narrow breadcrumb must not overflow its hero'); checks++;
            const navRect = narrowNav.getBoundingClientRect();
            check(narrowLinks.every(link => {
                const rect = link.getBoundingClientRect();
                return rect.width > 0 && rect.left >= navRect.left && rect.right <= navRect.right;
            }), 'every ancestor destination must remain visible and reachable'); checks++;
            const longLink = narrowLinks[2];
            check(longLink.getBoundingClientRect().height > parseFloat(narrowWindow.getComputedStyle(longLink).lineHeight) * 1.4, 'long ancestor must wrap'); checks++;
            const narrowHeight = navRect.height;
            for (const host of hosts) {
                const nav = host.querySelector('nav.breadcrumbs');
                nav.className = `breadcrumbs breadcrumbs--${style}`;
                host.style.width = '900px';
                const desktopHeight = nav.getBoundingClientRect().height;
                check(desktopHeight < narrowHeight, 'desktop path must use less vertical space than 320px layout'); checks++;
                const computed = host.ownerDocument.defaultView.getComputedStyle.bind(host.ownerDocument.defaultView);
                const currentNode = nav.querySelector('.breadcrumbs__current');
                if (style === 'pills') {
                    check(computed(currentNode).backgroundColor !== 'rgba(0, 0, 0, 0)', 'pill current item has a surface'); checks++;
                }
                if (style === 'surface') {
                    check(computed(nav).backgroundColor !== 'rgba(0, 0, 0, 0)', 'surface style has a background'); checks++;
                }
                if (style === 'ribbon') {
                    const arrow = computed(nav.querySelector('.breadcrumbs__item'), '::after');
                    check(arrow.content !== 'none' && arrow.clipPath.includes('polygon') && arrow.backgroundColor !== 'rgba(0, 0, 0, 0)', 'ribbon uses a visible clipped arrow motif'); checks++;
                }
                if (style === 'nodes') {
                    const connector = computed(nav.querySelector('.breadcrumbs__item + .breadcrumbs__item'), '::before');
                    check(connector.backgroundImage.includes('radial-gradient') && connector.backgroundImage.includes('linear-gradient'), 'node preset draws connected route markers'); checks++;
                }
                if (style === 'tabs') {
                    check(parseFloat(computed(currentNode).borderBottomWidth) >= 2 && computed(currentNode).borderBottomStyle === 'solid', 'tab current page has an active underline'); checks++;
                }
                if (style === 'tiles') {
                    const tile = computed(currentNode.closest('.breadcrumbs__item'));
                    check(tile.borderBottomStyle === 'solid' && tile.boxShadow !== 'none', 'tile preset raises the current breadcrumb card'); checks++;
                }
                if (style === 'gradient') {
                    const stripe = computed(nav, '::before');
                    check(computed(nav).backgroundImage.includes('linear-gradient') && stripe.content !== 'none' && parseFloat(stripe.height) > 0,
                        'gradient preset renders its colored surface and decorative stripe'); checks++;
                }
            }
        }
        const lightColor = getComputedStyle(hosts[0].querySelector('nav.breadcrumbs')).color;
        const darkColor = getComputedStyle(hosts[1].querySelector('nav.breadcrumbs')).color;
        check(darkColor !== lightColor, 'dark theme tokens apply to the same component'); checks++;
        const expectedPickerValues = ['inherit', ...styles];
        for (const [host, fieldName] of [[hosts[0], 'fixture_breadcrumb_style_light'], [hosts[1], 'fixture_breadcrumb_style_dark']]) {
            const picker = host.querySelector('.breadcrumb-style-picker');
            const radios = [...picker.querySelectorAll('input[type="radio"]')];
            check(picker.tagName === 'FIELDSET' && picker.querySelector('legend')?.textContent === 'Breadcrumb style', 'picker exposes a labelled native radio group'); checks++;
            check(radios.length === expectedPickerValues.length && radios.every((radio, index) =>
                radio.name === fieldName && radio.value === expectedPickerValues[index]), 'picker radios map inherit and every registered style in stable order'); checks++;
            check(radios.filter(radio => radio.checked).length === 1 && radios[0].checked, 'picker shows the prepared inherited selection'); checks++;
            for (const [index, radio] of radios.entries()) {
                const preview = radio.parentElement.querySelector('.breadcrumb-style-picker__preview');
                const breadcrumb = preview.querySelector('.breadcrumbs');
                const expectedClass = index === 0 ? 'breadcrumbs--chevron' : `breadcrumbs--${styles[index - 1]}`;
                check(radio.parentElement.tagName === 'LABEL' && breadcrumb.classList.contains(expectedClass), 'picker preview uses its style registry class'); checks++;
                check(preview.getAttribute('aria-hidden') === 'true' && !preview.querySelector('a, nav'), 'picker previews are decorative and contain no nested navigation'); checks++;
                check([...preview.querySelectorAll('.breadcrumbs__item')].every(item => [...item.children].every(child => child.tagName === 'SPAN')), 'picker samples use non-interactive spans'); checks++;
            }
        }
        const narrowPicker = narrowDocument.querySelector('.breadcrumb-style-picker');
        check(narrowPicker.scrollWidth <= narrowPicker.clientWidth, 'picker cards fit a 320px layout without horizontal overflow'); checks++;
        const selectableRadio = hosts[0].querySelector('input[name="fixture_breadcrumb_style_light"][value="nodes"]');
        selectableRadio.focus();
        const focusedCard = selectableRadio.parentElement.querySelector('.breadcrumb-style-picker__card');
        check(selectableRadio.tabIndex === 0 && document.activeElement === selectableRadio
            && getComputedStyle(focusedCard).outlineStyle !== 'none', 'keyboard-focusable radio has a visible focus treatment on its card'); checks++;
        const focusedCardRect = focusedCard.getBoundingClientRect();
        check(focusedCardRect.width > 0 && focusedCardRect.height > 0, 'preview card has a visible interactive bounding box'); checks++;
        focusedCard.click();
        check(selectableRadio.checked && !hosts[0].querySelector('input[name="fixture_breadcrumb_style_light"][value="inherit"]').checked, 'selecting a preview card updates the native radio value'); checks++;
        document.querySelector('#results').textContent = `BROWSER PASS: ${checks} responsive, style, and theme assertions`;
    } catch (error) {
        document.querySelector('#results').textContent = `BROWSER FAIL: ${error.message}`;
    }
})();
</script>
HTML);
    echo '</body></html>';
}
