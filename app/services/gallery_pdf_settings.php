<?php
/**
 * Project: PHP Gallery
 * Repository: https://github.com/klusik/PHP_gallery
 * File: app/services/gallery_pdf_settings.php
 * Module Type: Service
 * Purpose: Own resolution settings for new browser PDF page conversions.
 * Responsibilities: Validate DPI choices independently of image-upload ingestion.
 * Author: Rudolf Klusal
 * License: MIT License (see LICENSE file in repository)
 */
declare(strict_types=1);
namespace Gallery\Services;

/**
 * Validate the administrator's lossless PNG page resolution.
 * @param string|int $value Requested raster resolution in dots per inch.
 * @return int Supported resolution; invalid selections are refused.
 */
function gallery_pdf_render_dpi_validate(string|int $value): int
{
    if (!in_array((string) $value, ['96', '150', '200', '300', '600', '1200', '2400'], true)) {
        throw new \InvalidArgumentException(t('pdf.dpi_invalid', 'Choose 96, 150, 200, 300, 600, 1200 or 2400 DPI.'));
    }
    return (int) $value;
}

/**
 * Read the persisted resolution for newly initiated PDF conversions.
 * @return int Resolution in DPI; absent or invalid storage uses 150 DPI.
 */
function gallery_pdf_render_dpi(): int
{
    // Default raster resolution balances readable PNG pages with browser memory.
    // Type: int. Units: DPI. Scope: new PDF conversions only.
    // Consumers: gallery PDF conversion forms and settings registry.
    // Rationale: 150 DPI is the product default; originals and older children are unchanged.
    $defaultDpi = 150;
    $value = (string) app_setting('gallery_pdf_render_dpi', (string) $defaultDpi);
    return in_array($value, ['96', '150', '200', '300', '600', '1200', '2400'], true) ? (int) $value : $defaultDpi;
}

/**
 * Map a known PDF operation failure to safe, localized administrator guidance.
 *
 * Only recognized domain messages select a specific category. Unknown
 * exceptions, including raw filesystem, database and JSON failures, return a
 * generic message without exposing exception text or local paths.
 *
 * @param \Throwable $error Failure raised while handling an administrator PDF action.
 * @param string|null $language Optional originating UI language; no preference is changed.
 * @return string Localized safe category message; unknown failures use pdf.error.failed.
 */
function gallery_pdf_operation_error_message(\Throwable $error, ?string $language = null): string
{
    $message = trim($error->getMessage());
    $categories = [
        'size' => '/^(?:The PDF must be between 16 bytes and 25 MiB\.|Select a PDF of up to 25 MiB and check PHP upload limits\.)$/i',
        'confirmation' => '/^(?:Replacing an existing PDF requires explicit confirmation\.|Deleting a PDF document requires explicit confirmation\.|A conversion with different render settings already exists\. Explicitly confirm a new conversion before continuing\.|The source PDF page count or target changed\. Confirm a new conversion before replacing its lifecycle\.)$/i',
        'invalid_pdf' => '/^(?:Select a readable PDF file\.|The uploaded PDF cannot be read\.|The uploaded PDF is incomplete\.|The file is not a valid PDF document\.)$/i',
        'edited' => '/^(?:The source PDF changed or failed integrity validation\.|The incomplete child was edited\..*|A previously acknowledged page was edited\..*|A PDF page changed after its upload acknowledgment\.)$/i',
        'batch' => '/^(?:The prepared page batch .*|The prepared PDF page package is unavailable\.|The PDF page batch contains .*|The ordinary image upload service (?:did not acknowledge .*|acknowledged an unrelated filename\.)|A registered page lacks the ordinary uploader acknowledgment\.|An acknowledged conversion page is absent or is not a PNG\.)$/i',
        'incomplete' => '/^(?:Not every PDF page has been uploaded and registered\..*|A PDF page is missing from the registered page sequence\.|A registered PDF page is missing or is no longer a valid PNG\.)$/i',
        'session' => '/^(?:The PDF conversion session token is malformed\.|The PDF conversion token is invalid\.|The PDF conversion identity is malformed\.|Resume requires the original conversion session token\.|The PDF conversion token does not own this child gallery\.|The PDF conversion is no longer accepting page uploads\.|The active conversion token is unavailable; start a new conversion\.)$/i',
        'browser' => '/^Enable JavaScript to convert PDF pages in this browser\.$/i',
        'settings' => '/^(?:Choose 96, 150, 200, 300, 600, 1200 or 2400 DPI\.|A valid source PDF, DPI and page count are required\.|The source PDF page count or target changed\..*)$/i',
        'source' => '/^(?:The source gallery changed\. Reload the editor\.|The selected gallery folder is unavailable\.|The SimBrief OFP PDF source is unavailable\.|The selected source PDF is unavailable\.|The selected PDF document id is invalid\.|The PDF document no longer exists\..*)$/i',
        'target' => '/^(?:The PDF page gallery is unavailable\.|The existing conversion target is unavailable or no longer private\.|The reserved PDF page gallery exists without a lifecycle marker\..*|The reserved PDF page folder exists outside the gallery catalog\..*)$/i',
        'storage' => '/^(?:PDF document storage .*|PDF document index .*|The PDF document could not be staged completely\.|The existing PDF could not be protected for replacement\.|The existing PDF index could not be backed up\.|The PDF document could not be installed\.|The PDF index could not be installed;.*|Updated PDF document index could not be staged\.|The original PDF could not be protected for deletion\.|The updated PDF index could not be installed;.*|Could not prepare private PDF conversion state storage\.|PDF conversion state directory escapes the source gallery\.|Could not persist PDF conversion state\.|Could not guarantee a private physical PDF page gallery\.)$/i',
    ];
    foreach ($categories as $category => $pattern) {
        if (preg_match($pattern, $message) === 1) {
            if ($language !== null && translation_language_allowed($language)) {
                $strings = translation_load_language($language);
                return (string) ($strings['pdf.error.' . $category] ?? gallery_pdf_operation_error_fallback($category));
            }
            return t('pdf.error.' . $category, gallery_pdf_operation_error_fallback($category));
        }
    }
    if ($language !== null && translation_language_allowed($language)) {
        $strings = translation_load_language($language);
        return (string) ($strings['pdf.error.failed'] ?? gallery_pdf_operation_error_fallback('failed'));
    }
    return t('pdf.error.failed', gallery_pdf_operation_error_fallback('failed'));
}

/**
 * Return the English fallback for a recognized PDF error category.
 * @param string $category Known safe PDF error category.
 * @return string English fallback text without untrusted exception details.
 */
function gallery_pdf_operation_error_fallback(string $category): string
{
    return match ($category) {
        'source' => 'The selected gallery or PDF is no longer available. Reload the gallery and select the document again.',
        'target' => 'The conversion destination is unavailable or was changed. The original PDF is preserved. Reload and confirm a new result to recreate a missing empty subgallery, or choose another destination.',
        'storage' => 'PDF storage could not be read or safely updated. Check gallery file permissions and retry.',
        'confirmation' => 'Confirm the requested replacement, deletion or new conversion before continuing.',
        'invalid_pdf' => 'The selected file is not a readable, valid PDF. Choose another PDF.',
        'size' => 'The PDF is outside the allowed size range. Choose a PDF between 16 bytes and 25 MiB.',
        'settings' => 'Choose a supported DPI and destination, then retry with a valid PDF page count.',
        'session' => 'This conversion session is invalid or expired. Reload the gallery and start or resume the conversion.',
        'edited' => 'The source PDF or acknowledged pages changed. Existing files were preserved; reload and start a new conversion.',
        'incomplete' => 'Not all PDF pages are acknowledged and registered yet. Retry to resume the conversion.',
        'batch' => 'A prepared page batch is invalid or was not fully acknowledged. Retry the batch.',
        'browser' => 'PDF page conversion requires JavaScript and a supported browser. Reload the gallery and try again.',
        default => 'The PDF operation failed. Check the selected file and gallery storage, then retry.',
    };
}
