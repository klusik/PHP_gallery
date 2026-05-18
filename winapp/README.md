# PHP Gallery uploader

This Windows companion app uploads images to one PHP Gallery target gallery through a gallery-scoped API key.

It supports two modes in the same app:

- Watch folder: upload new images that appear after the watcher starts.
- Manual upload: select pictures directly and upload them as a bulk job.

Both modes use the same saved gallery URL or upload endpoint and the same API key.

The shared connection area also includes `Revoke API key`. It is only usable when the watcher is stopped. When you revoke the key, the app asks the gallery to invalidate that token and then clears the saved API key locally.

## Setup

1. Open the target gallery in the PHP Gallery admin editor.
2. Go to the Images tab.
3. Generate an API key in the upload automation panel.
4. Copy the key immediately. The raw key is shown only once.
5. Run `install.bat` from the `winapp` folder, or run `gallery_watch_upload.pyw` directly.
6. Enter the gallery site URL or upload endpoint, paste the API key, and save the configuration.

The upload endpoint is normally:

```text
https://example.com/index.php?page=upload_automation_upload
```

When URL rewriting is enabled, this clean endpoint also works:

```text
https://example.com/api/upload
```

The app accepts either the site root URL or the full endpoint URL. If you enter the site root URL, it appends `index.php?page=upload_automation_upload` automatically.

## Watch folder mode

Existing images in the watched folder are treated as already present when the watcher starts.

That means:

- Files already in the folder are ignored.
- Only files added after pressing Start watching are uploaded.
- Duplicate watched-folder uploads are avoided through the local upload state file.
- Failed watched-folder uploads are retried with backoff.

## Manual upload mode

Manual upload lets you choose pictures directly from the file picker. It does not depend on the watched folder.

The checkbox `Generate responsive thumbnails on this PC before upload` controls the faster path:

- Enabled: the app generates PHP Gallery thumbnail variants locally using separate worker processes, uploads originals in parallel upload threads, and sends the generated JPG/WebP thumbnails to the existing gallery upload endpoint.
- Disabled: the app uploads originals in parallel upload threads and asks the gallery server to create thumbnails, matching the previous server-side behavior.

The Manual upload tab has two performance controls:

- Thumbnail processes: CPU-bound local resizing and JPG/WebP encoding workers. `Auto` uses a conservative value based on the CPU core count. On a 32-thread CPU, start with Auto or 12 to 16 before trying higher values.
- Upload threads: network-bound multipart upload workers. `Auto` uses a small shared-hosting-friendly value. Increasing this too much can overload PHP process limits or the uplink.

The app pipelines the work. It does not generate thumbnails for the whole selection first. It keeps a bounded number of thumbnail jobs and upload jobs in flight, uploads images as soon as their thumbnails are ready, and removes temporary thumbnail files after each upload finishes.

Client-side thumbnails require Pillow. `install.bat` installs it from `requirements.txt` into the same Python runtime used by the Start Menu shortcut. This avoids the Windows file-association issue where `.pyw` files can start through a different Python version than the one used from Command Prompt.

You can also install it manually into the Python shown inside the app:

```bat
python -m pip install --user -r requirements.txt
```

If the checkbox is disabled, open the Manual upload tab and check the runtime line. It shows the exact `pythonw.exe` or `python.exe` that is running the app. Use the `Install or repair Pillow` button to install Pillow into that exact runtime without guessing.

If Pillow is missing, watch-folder uploads still work and manual uploads can still use server-side thumbnail generation.

## Runtime files

Configuration and upload state are stored under the current Windows user profile, normally:

```text
%APPDATA%\PHPGalleryUploader\
```

The app never deletes local source photos. Watch-folder mode marks a file as uploaded only after the gallery returns a successful JSON response.

## Notes

- The API key decides the target gallery.
- The Python app does not create a second authentication flow.
- The PHP endpoint still stores originals through the existing gallery upload pipeline.
- Client-generated thumbnails are accepted only after the corresponding original image is accepted by the gallery.
- Partially copied watched-folder files are ignored until their size and modification time remain stable.
- `gallery_watch_upload.pyw` starts without a console window.
