/**
 * Project: PHP Gallery
 * Repository: https://github.com/klusik/PHP_gallery
 *
 * File: public/assets/gallery-modules/admin-picture-game.js
 * Module Type: Browser Module
 *
 * Purpose:
 *   Provides keyboard support for the picture game admin screen.
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
 *   2026-05-12
 */

// Function `setupPictureGame` executes this focused behavior.
export function setupPictureGame() {
    // Variable `game` stores this steps working value.
    const game = document.querySelector('[data-picture-game]');
    if (!game) {
        return;
    }
    document.addEventListener('keydown', (event) => {
        if (event.key !== 'ArrowLeft' && event.key !== 'ArrowRight') {
            return;
        }
        // Variable `side` stores this steps working value.
        const side = event.key === 'ArrowLeft' ? 'left' : 'right';
        // Variable `button` stores this steps working value.
        const button = game.querySelector(`[data-picture-game-choice="${side}"]`);
        if (button) {
            event.preventDefault();
            button.click();
        }
    });
}
