/**
 * Project: PHP Gallery
 * Repository: https://github.com/klusik/PHP_gallery
 *
 * File: public/assets/telemetry.js
 * Module Type: Browser Module
 *
 * Purpose:
 *   Provides client-side behavior for the PHP Gallery user interface.
 *
 * Responsibilities:
 *   - Attach behavior to existing server-rendered markup
 *   - Keep DOM interaction predictable and readable
 *   - Avoid unnecessary layout work in performance-sensitive paths
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
 *   2026-05-04
 */

(function () {
    'use strict';

    const config = window.PHPGalleryTelemetry || {};
    if (!config.enabled || !config.endpoint) {
        return;
    }
    if (config.respectDnt && navigator.doNotTrack === '1') {
        return;
    }

    const sessionKey = 'php_gallery_telemetry_session_id';
    let sessionId = sessionStorage.getItem(sessionKey);
    if (!sessionId && window.crypto && typeof window.crypto.randomUUID === 'function') {
        sessionId = window.crypto.randomUUID();
        sessionStorage.setItem(sessionKey, sessionId);
    }
    if (!sessionId) {
        sessionId = String(Date.now()) + ':' + String(Math.random()).slice(2);
        sessionStorage.setItem(sessionKey, sessionId);
    }

    const queue = [];
    const maxBatchSize = 20;
    let flushTimer = null;
    let currentPhotoId = null;
    let currentPhotoGalleryId = null;
    let currentPhotoStartedAt = 0;
    let lastPhotoOpenedAt = 0;
    let lastPhotoOpenedId = null;
    let sessionStarted = sessionStorage.getItem(sessionKey + '_started') === '1';

    function browserFamily() {
        const agentData = navigator.userAgentData;
        if (agentData && Array.isArray(agentData.brands)) {
            const brands = agentData.brands.map((brand) => String(brand.brand).toLowerCase());
            if (brands.some((brand) => brand.includes('edge'))) {
                return 'edge';
            }
            if (brands.some((brand) => brand.includes('chrome') || brand.includes('chromium'))) {
                return 'chrome';
            }
        }
        const userAgent = navigator.userAgent.toLowerCase();
        if (userAgent.includes('edg/')) {
            return 'edge';
        }
        if (userAgent.includes('firefox/')) {
            return 'firefox';
        }
        if (userAgent.includes('opr/') || userAgent.includes('opera')) {
            return 'opera';
        }
        if (userAgent.includes('safari/') && !userAgent.includes('chrome/')) {
            return 'safari';
        }
        if (userAgent.includes('chrome/')) {
            return 'chrome';
        }
        return 'unknown';
    }

    function browserMajorBucket() {
        const userAgent = navigator.userAgent.toLowerCase();
        const match = userAgent.match(/(?:chrome|firefox|version|edg|opr)\/(\d+)/);
        return match ? Number(match[1]) : null;
    }

    function osFamily() {
        const platform = String(navigator.userAgentData?.platform || navigator.platform || '').toLowerCase();
        const userAgent = navigator.userAgent.toLowerCase();
        if (platform.includes('win') || userAgent.includes('windows')) {
            return 'windows';
        }
        if (platform.includes('mac') || userAgent.includes('mac os')) {
            return 'macos';
        }
        if (userAgent.includes('iphone') || userAgent.includes('ipad')) {
            return 'ios';
        }
        if (userAgent.includes('android')) {
            return 'android';
        }
        if (platform.includes('linux') || userAgent.includes('linux')) {
            return 'linux';
        }
        return 'unknown';
    }

    function deviceType() {
        const userAgent = navigator.userAgent.toLowerCase();
        if (userAgent.includes('bot') || userAgent.includes('crawler') || userAgent.includes('spider')) {
            return 'bot';
        }
        if (userAgent.includes('ipad') || userAgent.includes('tablet')) {
            return 'tablet';
        }
        if (userAgent.includes('mobile') || userAgent.includes('iphone') || userAgent.includes('android')) {
            return 'phone';
        }
        return 'desktop';
    }

    function baseEvent(eventName) {
        return {
            event_name: eventName,
            source: 'client',
            session_id: sessionId,
            occurred_at: new Date().toISOString(),
            route_name: config.routeName || 'unknown',
            page_kind: config.pageKind || 'unknown',
            gallery_id: config.galleryId || null,
            image_id: config.imageId || null,
            referrer_category: config.referrerCategory || 'unknown',
            browser_family: browserFamily(),
            browser_major_bucket: browserMajorBucket(),
            os_family: osFamily(),
            device_type: deviceType(),
            viewport_width: window.innerWidth || null,
            locale: navigator.language || null
        };
    }

    function enqueue(event) {
        const sampleRate = Number(config.sampleRate || 1);
        if (sampleRate < 1 && Math.random() > sampleRate) {
            return;
        }
        queue.push(event);
        if (queue.length >= maxBatchSize) {
            flush();
            return;
        }
        scheduleFlush();
    }

    function scheduleFlush() {
        if (flushTimer !== null) {
            return;
        }
        flushTimer = window.setTimeout(function () {
            flushTimer = null;
            flush();
        }, 750);
    }

    function flush() {
        if (flushTimer !== null) {
            window.clearTimeout(flushTimer);
            flushTimer = null;
        }
        if (!queue.length) {
            return;
        }
        const events = queue.splice(0, queue.length);
        const payload = JSON.stringify({events: events});
        if (navigator.sendBeacon) {
            const accepted = navigator.sendBeacon(config.endpoint, new Blob([payload], {type: 'application/json'}));
            if (accepted) {
                return;
            }
        }
        fetch(config.endpoint, {
            method: 'POST',
            headers: {'Content-Type': 'application/json'},
            body: payload,
            keepalive: true,
            credentials: 'same-origin'
        }).catch(() => {
            if (queue.length < maxBatchSize) {
                queue.unshift(...events.slice(0, maxBatchSize - queue.length));
            }
        });
    }

    function visibleWidthBucket(width) {
        if (!width || width <= 0) {
            return 'unknown';
        }
        if (width <= 300) {
            return '0_300';
        }
        if (width <= 600) {
            return '301_600';
        }
        if (width <= 800) {
            return '601_800';
        }
        if (width <= 1200) {
            return '801_1200';
        }
        return '1201_plus';
    }

    function startPageEvents() {
        if (!sessionStarted) {
            sessionStarted = true;
            sessionStorage.setItem(sessionKey + '_started', '1');
            enqueue(baseEvent('public.session.started'));
        }
        enqueue(baseEvent(config.pageKind === 'gallery' ? 'public.gallery.viewed' : 'public.page.viewed'));
    }

    window.PHPGalleryTelemetryPhotoOpened = function (imageId, galleryId, mode) {
        const normalizedImageId = Number(imageId || 0);
        const openedAt = performance.now();
        if (normalizedImageId > 0 && lastPhotoOpenedId === normalizedImageId && openedAt - lastPhotoOpenedAt < 500) {
            return;
        }
        lastPhotoOpenedId = normalizedImageId;
        lastPhotoOpenedAt = openedAt;
        window.PHPGalleryTelemetryPhotoClosed();
        currentPhotoId = normalizedImageId;
        currentPhotoGalleryId = galleryId || config.galleryId || null;
        currentPhotoStartedAt = performance.now();
        const event = baseEvent('public.photo.opened');
        event.image_id = normalizedImageId;
        event.gallery_id = currentPhotoGalleryId;
        event.page_kind = 'photo';
        event.context = {lightbox_mode: mode || 'normal'};
        enqueue(event);
        flush();
    };

    window.PHPGalleryTelemetryPhotoClosed = function () {
        if (!currentPhotoId || !currentPhotoStartedAt) {
            return;
        }
        const durationMs = Math.min(
            Math.round(performance.now() - currentPhotoStartedAt),
            Number(config.maxPhotoViewSeconds || 900) * 1000
        );
        const event = baseEvent('public.photo.visible_time');
        event.image_id = currentPhotoId;
        event.gallery_id = currentPhotoGalleryId;
        event.page_kind = 'photo';
        event.duration_ms = durationMs;
        event.context = {lightbox_mode: document.fullscreenElement ? 'fullscreen' : 'normal'};
        enqueue(event);
        flush();
        currentPhotoId = null;
        currentPhotoGalleryId = null;
        currentPhotoStartedAt = 0;
    };

    window.PHPGalleryTelemetryImageDecoded = function (imageId, galleryId, elapsedMs, mediaVariant, cacheResult, displayWidth) {
        const event = baseEvent('client.performance.image_decode');
        event.image_id = imageId || null;
        event.gallery_id = galleryId || config.galleryId || null;
        event.page_kind = 'photo';
        event.value_ms = Math.max(0, Math.round(elapsedMs || 0));
        event.media_variant = mediaVariant || 'unknown';
        event.cache_result = cacheResult || 'unknown';
        event.context = {display_width_bucket: visibleWidthBucket(displayWidth)};
        enqueue(event);
    };

    window.PHPGalleryTelemetryCacheEvent = function (eventName, imageId, galleryId, sourceKind) {
        const event = baseEvent(eventName);
        event.image_id = imageId || null;
        event.gallery_id = galleryId || config.galleryId || null;
        event.context = {source_kind: sourceKind || 'unknown'};
        enqueue(event);
    };


    function cardFromTelemetryClick(event) {
        if (!(event.target instanceof Element)) {
            return null;
        }
        if (event.target.closest('form, [data-admin-inline-editor], [data-photo-map], [data-gallery-map-url]')) {
            return null;
        }
        return event.target.closest('[data-lightbox-image], [data-lightbox-source]');
    }

    function setupLightboxFallbackObservers() {
        document.addEventListener('click', function (event) {
            const card = cardFromTelemetryClick(event);
            if (!card) {
                return;
            }
            const imageId = Number(card.dataset.imageId || 0);
            if (imageId <= 0) {
                return;
            }
            window.PHPGalleryTelemetryPhotoOpened(
                imageId,
                Number(card.dataset.galleryId || config.galleryId || 0),
                document.fullscreenElement ? 'fullscreen' : 'normal'
            );
        }, true);

        document.addEventListener('click', function (event) {
            if (!(event.target instanceof Element)) {
                return;
            }
            if (event.target.closest('[data-lightbox-action="close"]')) {
                window.PHPGalleryTelemetryPhotoClosed();
            }
        }, true);

        const overlay = document.querySelector('[data-lightbox]');
        if (!overlay || !window.MutationObserver) {
            return;
        }
        const observer = new MutationObserver(function () {
            if (overlay.hidden) {
                window.PHPGalleryTelemetryPhotoClosed();
            }
        });
        observer.observe(overlay, {attributes: true, attributeFilter: ['hidden']});
    }

    function collectPerformanceNavigation() {
        const navigation = performance.getEntriesByType ? performance.getEntriesByType('navigation')[0] : null;
        if (!navigation) {
            return;
        }
        const sampleRate = Number(config.performanceSampleRate || 0.25);
        if (sampleRate < 1 && Math.random() > sampleRate) {
            return;
        }
        const event = baseEvent('client.performance.page_load');
        event.value_ms = Math.max(0, Math.round(navigation.loadEventEnd || navigation.duration || 0));
        event.sampled_rate = sampleRate;
        enqueue(event);
    }

    window.addEventListener('error', function () {
        const event = baseEvent('client.error.javascript');
        event.error_kind = 'javascript_error';
        event.sampled_rate = Number(config.errorSampleRate || 1);
        enqueue(event);
    });

    window.addEventListener('visibilitychange', function () {
        if (document.visibilityState === 'hidden') {
            window.PHPGalleryTelemetryPhotoClosed();
            flush();
        }
    });

    window.addEventListener('pagehide', function () {
        window.PHPGalleryTelemetryPhotoClosed();
        flush();
    });

    startPageEvents();
    setupLightboxFallbackObservers();
    window.addEventListener('load', function () {
        collectPerformanceNavigation();
        window.setTimeout(flush, 500);
    });
})();
