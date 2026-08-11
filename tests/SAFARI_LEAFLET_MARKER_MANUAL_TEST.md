# Safari Leaflet marker manual regression test

Safari browser automation is not available in the project test environment. Run this check on current macOS Safari after deploying the affected files.

## Test matrix

Use one gallery containing at least one GPS-tagged photo and one gallery or route containing multiple GPS points.

1. Open the normal public map from a single photo.
2. Confirm the map tiles render and the photo marker is visible at the expected location.
3. Open its popup and confirm the popup content and interaction still work.
4. Enter fullscreen lightbox and open the split map.
5. Confirm the active-photo marker is visible and remains aligned while moving between photos.
6. Open a gallery or route map containing multiple markers.
7. Confirm `photo`, `active-photo`, `route`, `route-start`, `route-end`, and `route-via` marker roles retain their intended colors and shapes where those roles are present.
8. Confirm marker popups still open and the map URL, lightbox navigation, fullscreen behavior, and map authorization behavior are unchanged.
9. Repeat the same checks in Chrome and compare the marker positions and role styling.

## Safari Web Inspector checks

With a visible marker selected, inspect `.leaflet-marker-pane`, `.gallery-leaflet-marker`, `.gallery-leaflet-marker-pin`, `.gallery-leaflet-marker-tail`, and `.gallery-leaflet-marker-shadow`.

Expected properties:

- `.leaflet-marker-pane`: `display:block`, `visibility:visible`, `opacity:1`, `z-index:600`; its layout box may be 0 x 0 because Leaflet positions children outside the pane box.
- `.gallery-leaflet-marker`: visible, non-zero explicit dimensions from `L.divIcon`, `position:absolute`, `overflow:visible`.
- `.gallery-leaflet-marker-pin`, `.gallery-leaflet-marker-tail`, `.gallery-leaflet-marker-shadow`: visible non-zero geometry and `transform:none`.
- The outer `.gallery-leaflet-marker` may have a Leaflet-generated transform. That is expected and is the only transform required for marker positioning.
- No marker element should have `display:none`, `visibility:hidden`, `opacity:0`, an unexpected clipping ancestor, or a z-index below the tile pane.

In the JavaScript console, confirm there are no Leaflet or lightbox errors while opening either map mode. If diagnosing payloads, place a breakpoint in `renderLeafletMapPayload()` and verify that normalized points have finite `lat`/`lng` values and each `L.marker(...).addTo(map)` call returns a marker attached to the current map.
