# PHP Gallery Uploader Patch Notes

## Version 1.5.3

Restart a stalled website connection directly from the WinApp dialog.

### Highlights

- Use **Connect again** while waiting for browser approval or after an error.
  WinApp closes the old request and opens a fresh approval in the browser.
- The Watcher can continue using its saved upload API key and existing queue
  while the website connection is being set up.

### Technical Details

- Associate responses with the current approval attempt. A response from an
  earlier attempt cannot attach a session to the new request.
- After verified fresh approval, replace a stalled saved sign-in for the
  matching website, administrator and permission scope.

## Version 1.5.2

Restored Watcher startup for older API-key profiles and clarified website
connection in WinApp.

### Highlights

#### Watcher and updates

- Restored Watcher startup and verified update handoff with a saved API key
  on profiles created before browser linking.
- Added **Continue** for eligible pending work and **Interrupt** to pause the
  Watcher with its photographs available for later.

#### Website connection

- Renamed the action to **Connect website** and used the saved API address
  to suggest the installation URL when available. Album selection follows
  website approval.
- Kept the dialog available to retry launching the browser when it fails to
  open.

### Technical Details

- Reused the original target and queue binding across Watcher startup,
  interruption, and the verified updater checkpoint.

### User Impact

- An active Watcher with a verified checkpoint can resume the same folder,
  album and queue after an in-app update. An upload already in progress
  settles before the handoff.

## Version 1.5.1 (Unreleased source)

Added truthful in-gallery loading progress for Photos metadata and visible
thumbnails. This source update does not build or publish an installer.

### Highlights

#### Photos loading progress

- Added a compact themed progress bar inside the gallery. Metadata progress
   reports confirmed pages and the cumulative validated photo count; unknown
   totals remain indeterminate.
- Thumbnail progress becomes determinate only for visible thumbnails with
   confirmed results. The indicator disappears when the first usable viewport
   is resolved, and later reads continue in the background.
- Long waits expose elapsed time and the latest actual progress. Existing
   retry waits show the real server wait/countdown; otherwise the existing
   manual **Retry** action remains available. Cached thumbnails retain
   precedence.
- Added **Photo is OK — upload** for an uncertain head after the user checks
  that the displayed source is correct and not a duplicate. It approves only
  that same head for the ordinary upload queue; normal server duplicate checks
  remain in force. The approval is one-use, the previous unknown attempt stays
  in history, and another unclear outcome requires another decision.

### Technical Details

#### Frontend

- Applied the progress behavior to both Canvas and native gallery rendering.
- Reported the existing internal thumbnail retry cooldown through a worker
  observer, keeping progress notices out of the photograph-result queue.
- Preserved cancellation and session-generation fencing; Tk publication stays
   on the GUI thread.

#### Installer

- Updated the source target to `1.5.1`. No installer was built or published.

### User Impact

#### For WinApp users

- Photos shows confirmed loading activity without estimated percentages for
   unknown server totals. Gallery behavior outside loading feedback is
   unchanged.

#### For administrators

- No PHP Gallery server behavior or configuration changed.
