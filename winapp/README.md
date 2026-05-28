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
- Optional checkbox, enabled by default: attach the current Microsoft Flight Simulator camera location to watched-folder uploads when SimConnect is available.
- Optional checkbox: delete watched-folder source files after the gallery confirms that the image was uploaded successfully.

The Flight Simulator camera-location option queries SimConnect immediately before each watched-folder upload. It sends latitude, longitude, and altitude to PHP Gallery as upload metadata and does not modify the image file. If Flight Simulator or SimConnect is unavailable, the upload continues without location metadata.

The app first tries a local `SimConnect.dll` beside the winapp, then the `SIMCONNECT_DLL` environment variable, then a few common MSFS SDK install locations, and finally the normal Windows DLL loader. If you want to override that automatic search, use the optional `SimConnect.dll override` field.

## Manual upload mode

Manual upload lets you choose pictures directly from the file picker. It does not depend on the watched folder.

The checkbox `Generate responsive thumbnails on this PC before upload` controls the faster path:

- Enabled: the app generates PHP Gallery thumbnail variants locally using separate worker processes, uploads originals in parallel upload threads, and sends the generated JPG/WebP thumbnails to the existing gallery upload endpoint.
- Disabled: the app uploads originals in parallel upload threads and asks the gallery server to create thumbnails, matching the previous server-side behavior.

The Manual upload tab has two performance controls:

- Thumbnail processes: CPU-bound local resizing and JPG/WebP encoding workers. `Auto` uses a conservative value based on the CPU core count. On a 32-thread CPU, start with Auto or 12 to 16 before trying higher values.
- Upload threads: network-bound multipart upload workers. `Auto` uses a small shared-hosting-friendly value. Increasing this too much can overload PHP process limits or the uplink.

The app pipelines the work. It does not generate thumbnails for the whole selection first. It keeps a bounded number of thumbnail jobs and upload jobs in flight, uploads images as soon as their thumbnails are ready, and removes temporary thumbnail files after each upload finishes.

Client-side thumbnails require Pillow. Tray support requires `pystray`. `install.bat` installs both from `requirements.txt` into the same Python runtime used by the Start Menu shortcut. This avoids the Windows file-association issue where `.pyw` files can start through a different Python version than the one used from Command Prompt.

You can also install it manually into the Python shown inside the app:

```bat
python -m pip install --user -r requirements.txt
```

If the checkbox is disabled, open the Manual upload tab and check the runtime line. It shows the exact `pythonw.exe` or `python.exe` that is running the app. Use the `Install or repair dependencies` button to install the winapp packages into that exact runtime without guessing.

If Pillow is missing, watch-folder uploads still work and manual uploads can still use server-side thumbnail generation. If `pystray` is missing, the app still opens but tray hiding is disabled until dependencies are installed.

## System tray

The Windows app includes a tray icon so uploads can continue while the main window is hidden.

- Minimizing the window hides it to the system tray when tray support is available.
- Closing the window hides it to the system tray instead of stopping the app.
- If the watcher or a manual upload is running, closing asks whether to hide to tray, exit, or keep the window open.
- The tray menu includes Open, Start watching, Stop watching, and Exit.
- Use Exit from the tray menu when you want to stop background work and fully close the app.

Tray support requires `pystray`, installed by `install.bat` from `requirements.txt`.

## Runtime files

Configuration and upload state are stored under the current Windows user profile, normally:

```text
%APPDATA%\PHPGalleryUploader\
```

By default, the app does not delete local source photos.

If `Delete watched-folder files after a confirmed successful upload` is enabled, watch-folder mode removes only files that the gallery confirms as uploaded successfully. Failed, skipped, and duplicate files are kept locally.

Watch-folder mode marks a file as uploaded only after the gallery returns a successful JSON response.

## Notes

- The API key decides the target gallery.
- The Python app does not create a second authentication flow.
- The PHP endpoint still stores originals through the existing gallery upload pipeline.
- Client-generated thumbnails are accepted only after the corresponding original image is accepted by the gallery.
- Partially copied watched-folder files are ignored until their size and modification time remain stable.
- `gallery_watch_upload.pyw` starts without a console window.

## Optional AI metadata worker

The Windows companion app can also run as a low-priority client-side worker for internal image metadata. This mode is disabled by default and does not change the watched-folder or manual upload workflow.

Architecture:

- PHP Gallery remains the source of truth for work allocation.
- The companion app never scans the gallery and never decides global ownership by itself.
- The app asks the existing upload automation endpoint for one `ai_next_job` claim.
- The server atomically leases one queued job to that worker and returns a claim token.
- The worker downloads only the claimed asset through the authenticated API endpoint.
- The worker analyzes the image locally and reports `ai_complete` with either success metadata or a retryable failure.
- Heartbeats extend the lease while long processing continues.
- If the worker disappears, the lease expires and the server can assign the job again later.

Server requirements:

1. Deploy the updated PHP Gallery files.
2. Run pending database migrations from the admin maintenance flow or `php scripts/migrate.php`.
3. Generate or reuse a gallery-scoped upload automation API key.
4. Use that same Gallery URL and API key in the Windows app.

Client configuration:

1. Open the app and keep the normal shared connection settings filled in.
2. Open the `AI metadata` tab.
3. Enable `AI metadata worker on this PC`.
4. Keep the default model name and version for the built-in Pillow analyzer, or set your own values when using an external local model.
5. Optionally set an external analyzer command.
6. Start the worker from the tab or from the tray menu.

External analyzer command:

The command receives placeholders expanded by the app:

- `{image_path}` for the downloaded local image path
- `{filename}` for the gallery filename
- `{job_id}` for the server job id

The command must write a JSON object to stdout. Recommended shape:

```json
{
  "metadata": {
    "internal_description": "night airport ramp with parked aircraft",
    "labels": ["airport", "night", "aircraft", "ramp"]
  },
  "searchable_text": "night airport ramp parked aircraft apron lights"
}
```

The metadata is stored as internal data in `image_ai_metadata`. It is searchable, but it is not shown as a public photo description and it does not overwrite user-written titles or descriptions.

### Semantic object labels

The built-in Pillow analyzer can describe geometry, brightness, contrast, colors, and other technical image properties. It cannot identify semantic objects such as people, bridges, guitars, houses, cars, animals, aircraft, or food because Pillow is not an object-recognition model.

For semantic search metadata, use the `Vision backend` field on the AI metadata tab:

- `auto`: tries the external command if configured, then the in-process Transformers backend, then local Ollama, then Pillow as the safe fallback.
- `transformers`: runs Hugging Face Transformers and PyTorch directly inside this Python app process. It does not require a separate local server.
- `ollama`: optional fallback for users who intentionally run a locally installed Ollama service with a vision-capable model.
- `external`: uses the existing external analyzer command field.
- `pillow`: uses only dependency-light visual and technical metadata.

Recommended non-server setup on the Windows worker machine:

1. Open the `AI metadata` tab.
2. Press `Install local AI module`.
3. Restart the app after the installation finishes.
4. Set `Vision backend` to `transformers` or leave it on `auto`.
5. Keep the default caption model `Salesforce/blip-image-captioning-base` and detector model `google/owlvit-base-patch32`, or replace them with local Hugging Face model paths.
6. Keep the default object label list, or add gallery-specific labels that matter for your photos.

The optional local AI module installs large packages, mainly `torch`, `torchvision`, and `transformers`. They are not part of `requirements.txt` because normal uploading and the Pillow fallback should stay lightweight. First semantic run may download model files into the normal local Hugging Face cache for the current Windows user.

The Transformers backend stores internal fields such as `caption`, `objects`, `detections`, `labels`, and `internal_description`. These fields are added to the searchable text. They still do not replace the user-written photo title or description.

Ollama remains available if you prefer it, but it does need a locally running Ollama service. For a no-server workflow, use `transformers`.

### Reprocessing already analyzed gallery photos

If existing images were already processed with the previous Pillow-only metadata, you now have two options:

- Change the AI metadata tab's model version, for example from `1` to `semantic-1`, before starting the worker. The server treats a model/version change as a new generation target.
- Open the gallery admin editor, go to the `API` tab, and press `Force AI metadata regeneration`. This removes stored internal AI metadata and old queue rows for images in that gallery branch, then immediately queues fresh jobs for the same known model generation. The next AI worker poll will claim them.

The force-regeneration action only resets server rows and prepares queue jobs. Heavy analysis still runs on the Windows app. The AI tab now keeps normal controls visible and places tuning fields under `Show advanced AI settings`.
