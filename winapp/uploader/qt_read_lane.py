# Project: PHP Gallery
# Repository: https://github.com/klusik/PHP_gallery
# File: winapp/uploader/qt_read_lane.py
# Module Type: Qt worker bridge
# Purpose: Run cancellable reads away from the main thread.
# Responsibilities: Bound in-flight results and deliver current events through
#   a queued Qt signal with ordered lifecycle completion.
# Author: Rudolf Klusal
# License: MIT License (see LICENSE file in repository)

"""Deliver bounded, generation-owned data to Qt views."""

from dataclasses import dataclass
from queue import Empty, Full, Queue
from threading import Event, Thread
from typing import Callable, Generic, TypeVar

from PySide6.QtCore import QObject, Qt, Signal, Slot

ReadValue = TypeVar("ReadValue")

# Purpose: Bound replaceable visual results waiting on the Qt event loop.
# Type: int. Units: events. Scope: one read lane.
# Consumers: QtReadLane.
# Rationale: Four matches the existing Tk thumbnail publication backpressure.
RESULT_LIMIT = 4
# Purpose: Recheck cancellation when a GUI publication queue stays full.
# Type: float. Units: seconds. Scope: a worker's interruptible queue wait.
# Consumers: QtReadLane._publish.
# Rationale: 50 ms bounds cancellation without spinning in a held UI frame.
RESULT_WAIT_SECONDS = 0.05


class ReadCancelled(RuntimeError):
    """Stop publication when a read loses its owning view or generation."""


@dataclass(frozen=True)
class _ReadEvent(Generic[ReadValue]):
    """Transfer one typed value or ordered terminal result across the lane."""

    generation: int
    value: ReadValue | None
    terminal: bool = False
    failure: str = ""


class QtReadLane(QObject, Generic[ReadValue]):
    """Serialize reads and retain only the latest replacement request."""

    wake = Signal()

    def __init__(
        self, receive: Callable[[ReadValue], None],
        finish: Callable[[str], None], parent: QObject, *,
        dispose: Callable[[ReadValue], None] | None = None,
    ) -> None:
        """Bind a view's GUI-thread receivers and prepare its result queue.

        Args:
            receive (Callable[[ReadValue], None]): Applies one current value.
            finish (Callable[[str], None]): Receives a safe error class or
                empty success from the current read after its last value.
            parent (QObject): Qt owner whose lifetime bounds presentation.
            dispose (Callable[[ReadValue], None] | None): Optional cleanup
                for a payload whose generation was withdrawn before delivery.
        Returns:
            None: Creates a lane with no admitted operation.
        """
        super().__init__(parent)
        self._receive = receive
        self._finish = finish
        self._dispose = dispose
        self._events: Queue[_ReadEvent[ReadValue]] = Queue(maxsize=RESULT_LIMIT)
        self._closed = Event()
        self._cancelled = Event()
        self._generation = 0
        self._active = False
        self._pending: Callable[[Event, Callable[[ReadValue], None]], None] | None = None
        self.wake.connect(self._deliver, Qt.ConnectionType.QueuedConnection)

    def busy(self) -> bool:
        """Report foreground demand from the owning Qt thread.

        Returns:
            bool: True while a live or replacement command still owns the lane.
        """
        return self._active or self._pending is not None

    def submit(
        self, task: Callable[[Event, Callable[[ReadValue], None]], None],
    ) -> None:
        """Replace prior work with the newest view-owned read.

        Args:
            task (Callable[[Event, Callable[[ReadValue], None]], None]): Worker
                procedure which sees cancellation and publishes typed values.
        Returns:
            None: Starts immediately or replaces one pending operation.
        """
        if self._closed.is_set():
            return
        self.cancel()
        self._pending = task
        self._start_pending()

    def cancel(self) -> None:
        """Fence earlier results even if their I/O has yet to finish.

        Returns:
            None: Cancels active input and discards the pending replacement.
        """
        self._generation += 1
        self._cancelled.set()
        self._pending = None

    def close(self) -> None:
        """Withdraw the view while any cancelled read winds down independently.

        Returns:
            None: Stops new results and clears queued callbacks for this view.
        """
        self._closed.set()
        self.cancel()
        while True:
            try:
                event = self._events.get_nowait()
            except Empty:
                break
            if event.value is not None and self._dispose is not None:
                self._dispose(event.value)

    def _start_pending(self) -> None:
        """Run the latest pending task after the prior terminal event.

        Returns:
            None: At most one worker has permission to perform this lane's I/O.
        """
        task = self._pending
        if task is None or self._active or self._closed.is_set():
            return
        self._pending = None
        self._active = True
        self._cancelled = Event()
        thread = Thread(
            target=self._run, args=(self._generation, self._cancelled, task),
            daemon=True, name="PHPGalleryQtRead",
        )
        thread.start()

    def _run(
        self, generation: int, cancelled: Event,
        task: Callable[[Event, Callable[[ReadValue], None]], None],
    ) -> None:
        """Execute a read and enqueue its terminal event after data delivery.

        Args:
            generation (int): Exact view request identity.
            cancelled (Event): Cancellation fence shared with the read owner.
            task (Callable[[Event, Callable[[ReadValue], None]], None]): Read
                command producing worker-owned results.
        Returns:
            None: Transfers current data and one ordered completion event.
        """
        failure = ""
        try:
            task(cancelled, lambda value: self._publish(
                _ReadEvent(generation, value), cancelled,
            ))
        except ReadCancelled:
            pass
        except Exception as error:
            failure = type(error).__name__
        try:
            self._publish(_ReadEvent(generation, None, True, failure), None)
        except ReadCancelled:
            pass

    def _publish(self, event: _ReadEvent[ReadValue], cancelled: Event | None) -> None:
        """Backpressure the worker until the GUI admits an event.

        Args:
            event (_ReadEvent[ReadValue]): One immutable result or completion.
            cancelled (Event | None): Optional cancellation for data events.
                A live lane's terminal event retains priority after cancellation.
        Returns:
            None: A queued wake targets the receiver's Qt thread.
        Raises:
            ReadCancelled: The view or read no longer accepts the result.
        """
        while not self._closed.is_set() and (cancelled is None or not cancelled.is_set()):
            try:
                self._events.put(event, timeout=RESULT_WAIT_SECONDS)
            except Full:
                continue
            if not self._closed.is_set():
                try:
                    self.wake.emit()
                except RuntimeError as error:
                    raise ReadCancelled from error
            return
        raise ReadCancelled

    @Slot()
    def _deliver(self) -> None:
        """Deliver one current result and advance cancelled or completed work.

        Returns:
            None: GUI callbacks run only on the owning event-loop thread.
        """
        try:
            event = self._events.get_nowait()
        except Empty:
            return
        if event.terminal:
            self._active = False
            if event.generation == self._generation and not self._closed.is_set():
                self._finish(event.failure)
            self._start_pending()
        elif (event.generation == self._generation and event.value is not None
                and not self._closed.is_set()):
            self._receive(event.value)
        elif event.value is not None and self._dispose is not None:
            self._dispose(event.value)
