# PHP Gallery watched-folder uploader

This companion app watches one local folder and uploads image files that appear after the watcher starts.

## Setup

1. Open the target gallery in the PHP Gallery admin editor.
2. Go to the Images tab.
3. Generate an API key in the Watched-folder upload API panel.
4. Copy the key immediately. The raw key is shown only once.
5. Run `gallery_watch_upload.pyw` on Windows with Python 3.11 or newer.
6. Enter the gallery site URL, paste the API key, choose the watched folder, save the configuration, and start the watcher.

The upload endpoint is normally:

```text
https://example.com/index.php?page=upload_automation_upload
```

When URL rewriting is enabled, this clean endpoint also works:

```text
https://example.com/api/upload
```

The app accepts either the site root URL or the full endpoint URL. If you enter the site root URL, it appends `index.php?page=upload_automation_upload` automatically.

## Startup behavior

Existing images in the watched folder are treated as already present when the watcher starts.

That means:

- Files already in the folder are ignored.
- Only files added after pressing Start watching are uploaded.
- Duplicate uploads are still avoided through the local upload state file.
- Failed uploads are retried with backoff.

## Runtime files

Configuration and upload state are stored under the current Windows user profile, normally:

```text
%APPDATA%\PHPGalleryUploader\
```

The app never deletes local source photos. It marks a file as uploaded only after the gallery returns a successful JSON response.

## Notes

- The Python app contains no gallery business rules.
- The gallery API key decides the target gallery.
- The Python app only sends multipart uploads and records local retry state.
- Partially copied files are ignored until their size and modification time remain stable.
- `gallery_watch_upload.pyw` starts without a console window.
