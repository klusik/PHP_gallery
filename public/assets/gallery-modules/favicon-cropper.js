/**
 * Favicon cropper
 *
 * Implements the small canvas crop UI used by the admin theme screen.
 *
 * Example usage from the gallery entrypoint:
 *
 * import { setupExample } from './gallery-modules/example.js';
 * setupExample();
 */

export function setupFaviconCropper() {
    // input stores state or configuration for the gallery front-end flow.
    const input = document.querySelector('[data-favicon-input]');
    // cropper stores state or configuration for the gallery front-end flow.
    const cropper = document.querySelector('[data-favicon-cropper]');
    // canvas stores state or configuration for the gallery front-end flow.
    const canvas = document.querySelector('[data-favicon-canvas]');
    // preview stores state or configuration for the gallery front-end flow.
    const preview = document.querySelector('[data-favicon-preview]');
    // zoom stores state or configuration for the gallery front-end flow.
    const zoom = document.querySelector('[data-favicon-zoom]');
    // output stores state or configuration for the gallery front-end flow.
    const output = document.querySelector('[data-favicon-cropped]');
    if (!input || !cropper || !canvas || !preview || !zoom || !output) {
        return;
    }
    // context stores state or configuration for the gallery front-end flow.
    const context = canvas.getContext('2d');
    // previewContext stores state or configuration for the gallery front-end flow.
    const previewContext = preview.getContext('2d');
    // image stores state or configuration for the gallery front-end flow.
    const image = new Image();
    // imageLoaded stores state or configuration for the gallery front-end flow.
    let imageLoaded = false;
    // dragging stores state or configuration for the gallery front-end flow.
    let dragging = false;
    // lastPointerX stores state or configuration for the gallery front-end flow.
    let lastPointerX = 0;
    // lastPointerY stores state or configuration for the gallery front-end flow.
    let lastPointerY = 0;
    // offsetX stores state or configuration for the gallery front-end flow.
    let offsetX = 0;
    // offsetY stores state or configuration for the gallery front-end flow.
    let offsetY = 0;

    /**
     * Handles draw favicon crop behavior for the gallery UI.
     * @returns {*} Result of the UI operation, when a value is produced.
     */
    function drawFaviconCrop() {
        if (!imageLoaded) {
            return;
        }
        // scale stores state or configuration for the gallery front-end flow.
        const scale = Math.max(canvas.width / image.width, canvas.height / image.height) * Number(zoom.value || 1);
        // drawWidth stores state or configuration for the gallery front-end flow.
        const drawWidth = image.width * scale;
        // drawHeight stores state or configuration for the gallery front-end flow.
        const drawHeight = image.height * scale;
        // minOffsetX stores state or configuration for the gallery front-end flow.
        const minOffsetX = canvas.width - drawWidth;
        // minOffsetY stores state or configuration for the gallery front-end flow.
        const minOffsetY = canvas.height - drawHeight;
        offsetX = Math.min(0, Math.max(minOffsetX, offsetX));
        offsetY = Math.min(0, Math.max(minOffsetY, offsetY));
        context.clearRect(0, 0, canvas.width, canvas.height);
        context.drawImage(image, offsetX, offsetY, drawWidth, drawHeight);
        context.strokeRect(0.5, 0.5, canvas.width - 1, canvas.height - 1);
        previewContext.clearRect(0, 0, preview.width, preview.height);
        previewContext.drawImage(canvas, 0, 0, preview.width, preview.height);
        output.value = canvas.toDataURL('image/png');
    }

    input.addEventListener('change', () => {
        // file stores state or configuration for the gallery front-end flow.
        const file = input.files && input.files[0] ? input.files[0] : null;
        output.value = '';
        if (!file || !file.type.startsWith('image/')) {
            cropper.hidden = true;
            imageLoaded = false;
            return;
        }
        // reader stores state or configuration for the gallery front-end flow.
        const reader = new FileReader();
        reader.addEventListener('load', () => {
            image.addEventListener('load', () => {
                imageLoaded = true;
                zoom.value = '1';
                // baseScale stores state or configuration for the gallery front-end flow.
                const baseScale = Math.max(canvas.width / image.width, canvas.height / image.height);
                offsetX = (canvas.width - image.width * baseScale) / 2;
                offsetY = (canvas.height - image.height * baseScale) / 2;
                cropper.hidden = false;
                drawFaviconCrop();
            }, {once: true});
            image.src = String(reader.result || '');
        });
        reader.readAsDataURL(file);
    });

    zoom.addEventListener('input', drawFaviconCrop);
    zoom.addEventListener('change', drawFaviconCrop);
    canvas.addEventListener('pointerdown', (event) => {
        if (!imageLoaded) {
            return;
        }
        dragging = true;
        lastPointerX = event.clientX;
        lastPointerY = event.clientY;
        canvas.setPointerCapture(event.pointerId);
    });
    canvas.addEventListener('pointermove', (event) => {
        if (!dragging) {
            return;
        }
        offsetX += event.clientX - lastPointerX;
        offsetY += event.clientY - lastPointerY;
        lastPointerX = event.clientX;
        lastPointerY = event.clientY;
        drawFaviconCrop();
    });
    canvas.addEventListener('pointerup', () => {
        dragging = false;
    });
    canvas.addEventListener('pointercancel', () => {
        dragging = false;
    });
}
