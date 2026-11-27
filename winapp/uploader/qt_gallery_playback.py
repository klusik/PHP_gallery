# Project: PHP Gallery
# Repository: https://github.com/klusik/PHP_gallery
# File: winapp/uploader/qt_gallery_playback.py
# Module Type: Qt gallery presentation controls
# Purpose: Use the current approved playlist and slideshow timing in Qt Photos.
# Responsibilities: Fence metadata and track reads by gallery, stop native output
#   on grant loss, and manage verified locally prepared audio.
# Author: Rudolf Klusal
# License: MIT License (see LICENSE file in repository)

"""Display approved gallery presentation and own one local native audio player."""

from hashlib import sha256
from pathlib import Path
import sys
from tempfile import NamedTemporaryFile
from threading import Event
from typing import Callable

from PySide6.QtCore import QPoint, Qt, QTimer, Signal
from PySide6.QtWidgets import QComboBox, QHBoxLayout, QLabel, QMenu, QVBoxLayout, QWidget, QWidgetAction

from .app_linking import LinkedPresentation, LinkingError, LinkingSession
from .flight_report_translations import tr
from .gallery_audio import MEDIA_AUDIO_POLL_MS, NativeGalleryAudio, remove_audio_file
from .gallery_image_policy import MEDIA_SLIDESHOW_DEFAULT_SECONDS
from .qt_read_lane import QtReadLane, ReadCancelled
from .qt_theme import ActionButton, qt_icon

# Purpose: Fit a readable track title or status beside the Photos trigger.
# Type: int. Units: logical pixels. Scope: the native Music popup.
# Consumers: GalleryPlayback.__init__. Rationale: 280 matches the compact Tk card.
MUSIC_PANEL_WIDTH = 280


class GalleryPlayback(QWidget):
    """Present only the current grant's ordered native music playlist."""

    interval_changed = Signal(int)

    def __init__(self, language: str) -> None:
        """Prepare empty playback controls and two cancellable worker lanes.

        Args:
            language (str): Interface language selected for the current launch.
        Returns:
            None: No audio device or network session is opened.
        """
        super().__init__()
        self.language = language
        self._session: LinkingSession | None = None
        self._gallery: int | None = None
        self._settings: LinkedPresentation | None = None
        self._platform_playback = sys.platform in ('win32', 'darwin')
        self._player = NativeGalleryAudio()
        self._current: int | None = None
        self._loading = False
        self._paused = False
        self._lightbox_buttons: tuple[ActionButton, ...] = ()
        self._settings_lane = QtReadLane[LinkedPresentation](
            self._apply_settings, self._settings_finished, self,
        )
        self._tracks = QtReadLane[Path](
            self._start_track, self._track_finished, self, dispose=remove_audio_file,
        )
        self._observer = QTimer(self)
        self._observer.setInterval(MEDIA_AUDIO_POLL_MS)
        self._observer.timeout.connect(self._observe_track)
        layout = QHBoxLayout(self)
        layout.setContentsMargins(0, 0, 0, 0)
        self.music_button = ActionButton(
            self._text('presentation'), self._toggle_panel, icon='music', icon_only=True,
        )
        self.music_button.setObjectName('photosMusicButton')
        layout.addWidget(self.music_button)
        self._panel = QMenu(self.music_button)
        self._panel.setObjectName('photosMusicPanel')
        panel_action = QWidgetAction(self._panel)
        panel = QWidget()
        panel.setFixedWidth(MUSIC_PANEL_WIDTH)
        panel_layout = QVBoxLayout(panel)
        heading = QLabel(self._text('presentation'))
        heading.setObjectName('status')
        panel_layout.addWidget(heading)
        self._feedback = QLabel()
        self._feedback.setTextFormat(Qt.TextFormat.PlainText)
        self._feedback.setWordWrap(True)
        panel_layout.addWidget(self._feedback)
        self._playback_controls = QWidget()
        controls = QVBoxLayout(self._playback_controls)
        controls.setContentsMargins(0, 0, 0, 0)
        self.choice = QComboBox()
        self.choice.setAccessibleName(self._text('presentation'))
        self.choice.currentIndexChanged.connect(self._change_track)
        controls.addWidget(self.choice)
        actions = QHBoxLayout()
        actions.addStretch()
        self.play = ActionButton(self._text('play'), self._toggle, icon='play', icon_only=True)
        actions.addWidget(self.play)
        self.stop_button = ActionButton(self._text('stop'), self.stop, icon='stop', icon_only=True)
        self.stop_button.setEnabled(False)
        actions.addWidget(self.stop_button)
        controls.addLayout(actions)
        self.status = QLabel()
        self.status.setTextFormat(Qt.TextFormat.PlainText)
        self.status.setWordWrap(True)
        controls.addWidget(self.status)
        panel_layout.addWidget(self._playback_controls)
        panel_action.setDefaultWidget(panel)
        self._panel.addAction(panel_action)
        self.hide()

    def lightbox_controls(self) -> tuple[ActionButton, ActionButton, ActionButton]:
        """Build the track actions for the common compact Lightbox rail.

        Returns:
            tuple[ActionButton, ActionButton, ActionButton]: Previous track,
                play/pause and next track using the same playback owner.
        """
        buttons = (
            ActionButton(self._text('previous'), lambda: self._move_track(-1),
                         icon='music_previous', icon_only=True),
            ActionButton(self._text('play'), self._toggle, icon='play', icon_only=True),
            ActionButton(self._text('next'), lambda: self._move_track(1),
                         icon='music_next', icon_only=True),
        )
        for button, name in zip(buttons, ('lightboxTrackPrevious', 'lightboxTrackPlay', 'lightboxTrackNext')):
            button.setObjectName(name)
        self._lightbox_buttons = buttons
        self._set_buttons()
        return buttons

    def _toggle_panel(self) -> None:
        """Show the compact localized chooser directly above its trigger.

        Returns:
            None: Opening the panel exposes state without starting any track.
        """
        if self._panel.isVisible():
            self.close_panel()
        else:
            self._sync_panel_state()
            anchor = self.music_button.mapToGlobal(QPoint(0, -self._panel.sizeHint().height()))
            self._panel.popup(anchor)

    def close_panel(self) -> None:
        """Discard the popup when its gallery or workspace is hidden.

        Returns:
            None: Playback continues under the existing explicit owner.
        """
        self._panel.hide()

    def _sync_panel_state(self) -> None:
        """Explain the exact availability of the current gallery's music.

        Returns:
            None: Only a currently approved, usable playlist enables commands.
        """
        session, settings = self._session, self._settings
        if session is None or not session.can_read_presentation():
            feedback = 'audio_consent'
        elif settings is None or not settings.enabled or not settings.music or not settings.tracks:
            feedback = 'audio_empty'
        elif not self._platform_playback:
            feedback = 'audio_unavailable'
        else:
            feedback = ''
        self._feedback.setVisible(bool(feedback))
        self._feedback.setText(self._text(feedback) if feedback else '')
        self._playback_controls.setVisible(not feedback)
        self._set_buttons()

    def _text(self, key: str) -> str:
        """Resolve the existing localized gallery playback labels.

        Args:
            key (str): Suffix of a linked-media interface message.
        Returns:
            str: Existing localized control or status text.
        """
        return tr(self.language, f'linking_media_{key}')

    def bind(self, session: LinkingSession | None, gallery: int | None) -> None:
        """Refresh the settings for a currently approved physical gallery.

        Args:
            session (LinkingSession | None): Active presentation grant, if any.
            gallery (int | None): Exact gallery owned by that grant.
        Returns:
            None: Cancels stale playback on a change of session or gallery.
        """
        if session is not self._session or gallery != self._gallery:
            self._clear()
        self._session, self._gallery = session, gallery
        self.setVisible(gallery is not None and session is not None)
        self._settings_lane.cancel()
        if session is None or gallery is None or not session.can_read_presentation():
            self._clear()
            return
        self._settings_lane.submit(lambda cancel, publish: publish(
            session.media_presentation(gallery, cancel),
        ))

    def _clear(self) -> None:
        """Retire local settings and any borrowed authorized audio.

        Returns:
            None: Restores the regular manual slideshow interval.
        """
        self._settings = None
        self.stop()
        self.choice.blockSignals(True)
        self.choice.clear()
        self.choice.blockSignals(False)
        self.close_panel()
        self._sync_panel_state()
        self.interval_changed.emit(MEDIA_SLIDESHOW_DEFAULT_SECONDS)

    def _apply_settings(self, settings: LinkedPresentation) -> None:
        """Publish the current authorized interval and ordered playlist.

        Args:
            settings (LinkedPresentation): Validated settings for the current gallery.
        Returns:
            None: Identical refresh retains the same native playback slot.
        """
        session = self._session
        if session is None or not session.can_read_presentation():
            self._clear()
            return
        old = self._settings
        self._settings = settings
        if old is None or old.tracks != settings.tracks or old.music != settings.music:
            self.stop()
            self.choice.blockSignals(True)
            self.choice.clear()
            for track in settings.tracks:
                self.choice.addItem(track.title)
            self.choice.blockSignals(False)
        self._sync_panel_state()
        self.interval_changed.emit(settings.interval_seconds if settings.enabled else MEDIA_SLIDESHOW_DEFAULT_SECONDS)

    def _settings_finished(self, failure: str) -> None:
        """Clear presentation authority when its verification fails.

        Args:
            failure (str): Sanitized settings read failure or empty success.
        Returns:
            None: A failed read leaves manual slide navigation usable.
        """
        if failure:
            self._clear()

    def check_authority(self) -> None:
        """Stop native audio immediately when its live grant is no longer valid.

        Returns:
            None: Existing captured media cannot continue to play with lost scope.
        """
        if (self._settings is not None and self._session is not None
                and not self._session.can_read_presentation()):
            self._settings_lane.cancel()
            self._clear()

    def start_slideshow_music(self) -> bool:
        """Play the approved selection after the user explicitly starts a slideshow.

        Returns:
            bool: True when the slideshow acquired its own playback request.
        """
        return self._current is None and self._request_track(self.choice.currentIndex())

    def _toggle(self) -> None:
        """Start the selected track or toggle native pause on the current one.

        Returns:
            None: A pending network read remains under its original owner.
        """
        self.check_authority()
        if self._current is None:
            self._request_track(self.choice.currentIndex())
        elif not self._loading:
            try:
                self._paused = not self._paused
                self._player.pause(self._paused)
                if self._paused:
                    self._observer.stop()
                else:
                    self._observer.start()
                self._set_buttons()
            except OSError:
                self._playback_failed()

    def _move_track(self, shift: int) -> None:
        """Cycle the explicit selection without starting an idle player.

        Args:
            shift (int): Relative playlist movement in entries.
        Returns:
            None: Wraps within the exact playlist and keeps a live playlist active.
        """
        self.check_authority()
        if self.choice.count():
            self.choice.setCurrentIndex((self.choice.currentIndex() + shift) % self.choice.count())

    def _change_track(self, index: int) -> None:
        """Replace the active track only for an explicit changed selection.

        Args:
            index (int): Newly selected row in the current approved playlist.
        Returns:
            None: Idle selection does not start playback.
        """
        if self._current is not None:
            self._request_track(index)

    def _request_track(self, index: int) -> bool:
        """Fetch and verify one exact track using the existing media transport.

        Args:
            index (int): Playlist row selected by the current command.
        Returns:
            bool: True when the current session authorized the playback request.
        """
        session, gallery, settings = self._session, self._gallery, self._settings
        if (not self._platform_playback or session is None or gallery is None or settings is None
                or not settings.enabled or not settings.music or not 0 <= index < len(settings.tracks)
                or not session.can_read_presentation()):
            return False
        self.stop()
        self._current, self._loading = index, True
        self.status.setText(self._text('audio_loading'))
        self._set_buttons()
        track = settings.tracks[index]

        def read(cancelled: Event, publish: Callable[[Path], None]) -> None:
            """Prepare the verified rendition off Qt and transfer exact ownership.

            Args:
                cancelled (Event): Current playlist selection lifetime.
                publish (Callable[[Path], None]): Owned prepared file delivery.
            Returns:
                None: Transfers one verified file or cleans up before returning.
            """
            body = session.media_track(gallery, track, cancelled)
            if len(body) != track.size or sha256(body).hexdigest() != track.sha256:
                raise LinkingError('Track integrity check failed')
            if cancelled.is_set():
                raise ReadCancelled
            path = None
            try:
                suffix = '.mp3' if track.mime == 'audio/mpeg' else '.wav'
                with NamedTemporaryFile(prefix='php-gallery-track-', suffix=suffix, delete=False) as stream:
                    path = Path(stream.name)
                    stream.write(body)
                publish(path)
                path = None
            finally:
                if path is not None:
                    remove_audio_file(path)

        self._tracks.submit(read)
        return True

    def _start_track(self, path: Path) -> None:
        """Start only the prepared file that still has current authority.

        Args:
            path (Path): Verified file transferred by the active lane.
        Returns:
            None: Native playback owns the file or it is immediately cleaned up.
        """
        if self._session is None or not self._session.can_read_presentation():
            remove_audio_file(path)
            self.stop()
            return
        try:
            self._player.start(path)
        except (OSError, ValueError):
            self._playback_failed()
            return
        self._loading = False
        self.status.clear()
        self._set_buttons()
        self._observer.start()

    def _track_finished(self, failure: str) -> None:
        """Surface a failed preparation without a stale filename or URL.

        Args:
            failure (str): Sanitized exception class or empty success.
        Returns:
            None: A failure releases the old playback slot.
        """
        if failure:
            self._playback_failed()

    def _observe_track(self) -> None:
        """Advance in the validated playlist after native completion.

        Returns:
            None: Stops at the last track, grant loss or native failure.
        """
        self.check_authority()
        index = self._current
        if index is None or self._paused or self._loading:
            return
        try:
            if self._player.running():
                return
        except OSError:
            self._playback_failed()
            return
        self.stop()
        if index + 1 < self.choice.count():
            self.choice.setCurrentIndex(index + 1)
            self._request_track(index + 1)

    def _playback_failed(self) -> None:
        """End a failed track and show the shared localized playback message.

        Returns:
            None: Releases temporary bytes and native output.
        """
        self.stop()
        self.status.setText(self._text('audio_error'))

    def _set_buttons(self) -> None:
        """Keep play, pause and stop names in sync with current ownership.

        Returns:
            None: Keyboard actions reflect the live player state.
        """
        key = 'play' if self._current is None else 'resume' if self._paused else 'pause'
        settings = self._settings
        available = bool(
            self._platform_playback and settings is not None and settings.enabled
            and settings.music and settings.tracks,
        )
        buttons = [self.play]
        if self._lightbox_buttons:
            previous, play, following = self._lightbox_buttons
            previous.setEnabled(available and not self._loading)
            following.setEnabled(available and not self._loading)
            buttons.append(play)
        for button in buttons:
            caption = self._text(key)
            button.setEnabled(available and not self._loading)
            button.setAccessibleName(caption)
            button.setToolTip(caption)
            button.setIcon(qt_icon('pause' if key == 'pause' else 'play'))
        self.stop_button.setEnabled(self._current is not None)

    def stop(self) -> None:
        """Stop only this playlist and withdraw pending audio publication.

        Returns:
            None: No stale prepared file can restart playback.
        """
        self._observer.stop()
        self._tracks.cancel()
        self._player.stop()
        self._current = None
        self._loading = self._paused = False
        self.status.clear()
        self._set_buttons()

    def shutdown(self) -> None:
        """Release native playback and both read lanes before Qt exits.

        Returns:
            None: No widget receiver continues after shutdown.
        """
        self.bind(None, None)
        self._settings_lane.close()
        self._tracks.close()
