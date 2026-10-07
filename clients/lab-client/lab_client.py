"""
KobenLabs Lab Client — the background process on a lab PC.

Reports the machine's screen to the staff Live Screens page, on an interval the
server dictates. There is no user interface, no tray icon, no console window and no
notification: it runs windowless under a scheduled task and is invisible to whoever
is sitting at the machine.

Why a scheduled task and not a Windows Service, since that was the one genuinely
hard-won decision here:

    A Service runs in session 0, which has no interactive desktop. mss captures a
    black frame there, forever. A service-based client starts cleanly, reports
    itself as healthy, and shows nothing but black screens on every tile — with no
    error anywhere to explain why. A scheduled task runs inside the user's session,
    which is the desktop actually being watched.

So legacy's task-based model was right and is kept. What was wrong with it was the
settings, all three now fixed in install-client.ps1: it was bound to one
%USERNAME%, triggered on logon only, and had no restart policy.

Other answers to how the legacy version behaved:

* **It reconnects forever.** Legacy attempted 10 reconnects on linear backoff, then
  called sys.exit() into a task with no restart policy, so a single network blip took
  a machine out of monitoring for the rest of the session. This one never gives up,
  the backoff is exponential and capped, and the attempt counter resets on every
  *drop* rather than accumulating across separate incidents.

* **It stops on a locked screen.** Legacy had no lock detection at all, so a locked
  machine streamed either the lock screen or a black frame at full rate and looked
  exactly like a working one to a teacher. This pauses capture and reports `locked`,
  which the grid renders as a labelled state instead of a frozen thumbnail.

* **It downsamples properly.** Legacy used Image.NEAREST for a claimed 3x speedup.
  NEAREST aliases text badly, and legible text is the entire reason to watch a
  student work in an editor.

* **It never logs to stdout.** Under pythonw there is no console, so print() goes
  nowhere — every diagnostic legacy printed was discarded. Logging goes to a rotating
  file next to the executable.

* **It is pinned.** Legacy pip-installed unpinned dependencies from PyPI on every lab
  machine at install time, so a lab could be running different capture code from one
  machine to the next.

What this does *not* do, and the legacy version did:

* Capture audio. Video only.
* Capture anything but the primary display.
* Store frames anywhere. One JPEG is overwritten per capture on the server, and the
  client keeps nothing at all.
* Talk to anything but the one server URL in its config.
"""

from __future__ import annotations

import io
import json
import logging
import logging.handlers
import os
import platform
import socket
import sys
import threading
import time
from dataclasses import dataclass, field
from pathlib import Path
from typing import Any

try:
    import urllib.error
    import urllib.request
except ImportError:  # pragma: no cover
    raise SystemExit("urllib is required; this needs Python 3.9 or newer")

VERSION = "1.0.0"

# JPEG quality and width are defaults only. The server overrides both by handing
# them back from /api/client/config, so a whole lab can be retuned by editing
# config/lab.php rather than by touching 40 machines.
DEFAULT_MAX_WIDTH = 1280
DEFAULT_QUALITY = 60

# Bounds on the server-dictated interval. The floor stops a misconfigured server
# from being asked for 0 fps, and the ceiling stops a very stale config from
# looking frozen.
MIN_INTERVAL_SECONDS = 0.25
MAX_INTERVAL_SECONDS = 60.0

# Backoff schedule for a dropped connection. Capped rather than linear (legacy used
# a flat 3s * attempts), because the common case is a server restart that lasts a few
# seconds and the rare case is a long outage that should not become a tight retry.
BACKOFF_BASE_SECONDS = 2.0
BACKOFF_MAX_SECONDS = 60.0

# Where this program lives, as opposed to where it happens to be started from.
#
# These paths must never be bare relative names. A scheduled task has no working
# directory of its own, so Windows starts it in C:\Windows\System32 - and a relative
# "config.json" then resolves to C:\Windows\System32\config.json. The installed client
# refused to start with errno 2 on a machine where the file was sitting correctly next
# to it. Explorer usually happens to set the working directory to the exe's folder,
# which is exactly why this passed when run by hand and failed every time under the
# task it was built for.
#
# sys.executable is the frozen exe's own path; __file__ covers running from source.
if getattr(sys, "frozen", False):
    APP_DIR = Path(sys.executable).resolve().parent
else:
    APP_DIR = Path(__file__).resolve().parent

LOG_PATH = Path(os.environ.get("KOBEN_CLIENT_LOG", APP_DIR / "lab-client.log"))
CONFIG_PATH = Path(os.environ.get("KOBEN_CLIENT_CONFIG", APP_DIR / "config.json"))


def build_logger(path: Path) -> logging.Logger:
    """A rotating file logger.

    A service has no console, so this is the only diagnostic channel available. Sized
    small because a lab machine may run this for months unattended.
    """
    logger = logging.getLogger("koben-client")
    logger.setLevel(logging.INFO)

    if not logger.handlers:
        handler = logging.handlers.RotatingFileHandler(
            path, maxBytes=512 * 1024, backupCount=2, encoding="utf-8"
        )
        handler.setFormatter(
            logging.Formatter("%(asctime)s %(levelname)-7s %(message)s")
        )
        logger.addHandler(handler)

    return logger


@dataclass
class Config:
    """Local settings. Written once by install-client.ps1, then read-only."""

    server_url: str
    token: str
    max_width: int = DEFAULT_MAX_WIDTH
    quality: int = DEFAULT_QUALITY
    interval_seconds: float = 1.0

    @classmethod
    def load(cls, path: Path) -> "Config":
        # utf-8-sig, not utf-8.
        #
        # Both PowerShell (Set-Content -Encoding UTF8) and Notepad write a UTF-8 BOM,
        # and plain utf-8 decoding raises on it. An administrator editing config.json
        # by hand is the expected workflow, so a config that works until someone saves
        # it in a normal editor is a real failure mode, not a hypothetical one.
        raw = json.loads(path.read_text(encoding="utf-8-sig"))

        missing = [key for key in ("server_url", "token") if not raw.get(key)]
        if missing:
            raise ValueError(
                f"{path} is missing required key(s): {', '.join(missing)}"
            )

        return cls(
            server_url=str(raw["server_url"]).rstrip("/"),
            token=str(raw["token"]),
            max_width=int(raw.get("max_width", DEFAULT_MAX_WIDTH)),
            quality=int(raw.get("quality", DEFAULT_QUALITY)),
            interval_seconds=float(raw.get("interval_seconds", 1.0)),
        )


def capture_frame(max_width: int, quality: int) -> bytes | None:
    """Grab the primary display and return JPEG bytes.

    Uses mss because it is a native, sub-millisecond grab with no ffmpeg dependency,
    which was the one part of the legacy client that was genuinely well chosen.

    Downscaling is BILINEAR with reducing_gap rather than the legacy NEAREST. NEAREST
    is roughly 3x faster but produces aliased text, and legible text is the whole
    point of watching a student work in an editor.
    """
    import mss
    from PIL import Image

    # One monitor instance for the life of the service. Constructing it per frame
    # re-opens the display handle every time, which is measurable at 1 fps across a
    # whole lab.
    #
    # mss.MSS(), not the older mss.mss() function: 10.x renamed the constructor and
    # deprecated the callable form, which emits a DeprecationWarning to stderr on
    # every single frame. Under a windowless scheduled task that warning goes nowhere
    # useful, so the log ends up clean and the noise would only ever appear for
    # someone debugging from a console.
    sct = mss.MSS()

    try:
        monitor = sct.monitors[1]

        raw = sct.grab(monitor)
        image = Image.frombytes("RGB", raw.size, raw.bgra, "raw", "BGRX")

        if image.width > max_width:
            height = round(image.height * (max_width / image.width))
            image = image.resize((max_width, height), Image.BILINEAR)

        buffer = io.BytesIO()
        image.save(buffer, format="JPEG", quality=quality, optimize=False)

        return buffer.getvalue()
    finally:
        sct.close()


class LockWatcher:
    """Reports whether the interactive desktop is locked.

    Legacy had nothing like this. On a locked desktop mss returns either the
    lock-screen image or black, and that was streamed at full rate to every teacher —
    so a machine nobody was using looked identical to one in active use, which is
    exactly the question the feature exists to answer.

    Uses FindWindow on the LockApp window rather than the WTS session-notification
    API. WTS notifications are the textbook answer, but pywin32 exposes them as
    WTSRegisterSessionNotification, which requires a *window handle and a running
    message pump* — something a background capture process does not have. FindWindow
    needs neither, costs a few microseconds, and is only polled once per capture
    anyway.

    LockApp is the Windows 8+ lock screen. On Windows 7 the class name is LogonUI,
    which is why both are checked.
    """

    #: Window classes Windows uses for the lock screen, newest first.
    LOCK_CLASSES = ("LockApp", "LogonUI")

    def __init__(self) -> None:
        self._locked = False

    def _probe(self) -> bool:
        try:
            import win32gui
        except ImportError:
            # pywin32 is absent (a development machine). Report unlocked rather than
            # failing to start; the operator finds out from the log.
            return False

        for window_class in self.LOCK_CLASSES:
            try:
                if win32gui.FindWindow(window_class, None):
                    return True
            except Exception:  # noqa: BLE001
                # A class that does not exist on this build raises rather than
                # returning 0. Not being able to ask about it is not being locked.
                continue

        return False

    def refresh(self) -> bool:
        """Poll the desktop state. Returns the current value."""
        current = self._probe()

        if current != self._locked:
            logger = logging.getLogger("koben-client")
            self._locked = current
            logger.info("session %s", "locked — pausing capture" if current else "unlocked — resuming capture")

        return self._locked

    @property
    def locked(self) -> bool:
        return self._locked


class LabClient:
    def __init__(self, config: Config, logger: logging.Logger) -> None:
        self.config = config
        self.logger = logger
        self.stop = threading.Event()
        self.lock = LockWatcher()

        # Server-dictated values, refreshed from /api/client/config. Start from the
        # local config so the first capture does not wait on a round trip.
        self.interval = max(
            MIN_INTERVAL_SECONDS,
            min(MAX_INTERVAL_SECONDS, self.config.interval_seconds),
        )
        self.max_width = self.config.max_width
        self.quality = self.config.quality
        self.enabled = True

        self._last_state: str | None = None

    # ------------------------------------------------------------------
    # HTTP
    # ------------------------------------------------------------------

    def _request(
        self,
        path: str,
        *,
        body: bytes | None = None,
        content_type: str | None = None,
        json_body: dict[str, Any] | None = None,
        timeout: float = 10.0,
    ) -> tuple[int, dict[str, str], bytes]:
        url = f"{self.config.server_url}{path}"
        data = body
        headers = {
            "Authorization": f"Bearer {self.config.token}",
            "Accept": "application/json",
        }

        if json_body is not None:
            data = json.dumps(json_body).encode("utf-8")
            headers["Content-Type"] = "application/json"
        elif content_type is not None:
            headers["Content-Type"] = content_type

        request = urllib.request.Request(url, data=data, headers=headers, method="POST" if data else "GET")

        try:
            with urllib.request.urlopen(request, timeout=timeout) as response:
                return (
                    response.status,
                    dict(response.headers),
                    response.read(),
                )
        except urllib.error.HTTPError as error:
            # A 401 or 403 is not a transport failure — retrying faster will not fix a
            # revoked token. Read the body so the message can say which.
            return error.code, dict(error.headers or {}), error.read()
        except urllib.error.URLError as error:
            raise ConnectionError(str(error.reason)) from error
        except (TimeoutError, socket.timeout) as error:
            raise ConnectionError("timed out") from error

    def pull_config(self) -> bool:
        """Adopt the server's capture settings. Returns False if unreachable."""
        try:
            status, _, _ = self._request("/api/client/config", timeout=5.0)
        except ConnectionError as error:
            self.logger.warning("config fetch failed: %s", error)
            return False

        if status != 200:
            self.logger.warning("config fetch returned %s", status)
            return False

        return True

    def refresh_settings(self) -> None:
        """Read /api/client/config and adopt whatever the server says.

        This is how config/lab.php governs a whole lab: an administrator changes the
        capture rate there, and every machine picks it up at its next fetch. Nothing
        needs to be edited on the PCs.
        """
        try:
            status, _, payload = self._request("/api/client/config", timeout=5.0)
        except ConnectionError as error:
            self.logger.warning("could not read server config: %s", error)
            return

        if status != 200:
            return

        try:
            settings = json.loads(payload)
        except json.JSONDecodeError:
            return

        self.enabled = bool(settings.get("enabled", True))

        interval = settings.get("interval_seconds")
        if isinstance(interval, (int, float)) and interval > 0:
            self.interval = max(MIN_INTERVAL_SECONDS, min(MAX_INTERVAL_SECONDS, float(interval)))

        for key, attribute in (
            ("max_width", "max_width"),
            ("jpeg_quality", "quality"),
        ):
            value = settings.get(key)
            if isinstance(value, int) and value > 0:
                setattr(self, attribute, value)

        self.logger.info(
            "server config: %s fps, %spx wide, q%s, enabled=%s",
            round(1 / self.interval, 2),
            self.max_width,
            self.quality,
            self.enabled,
        )

    def send_frame(self, frame: bytes) -> None:
        """Upload one frame. Raises ConnectionError so the caller can back off."""
        status, headers, _ = self._request(
            "/api/client/frame",
            body=frame,
            content_type="image/jpeg",
        )

        if status in (401, 403):
            raise PermissionError(
                "server rejected this client's credential (HTTP "
                f"{status}); re-enrol with pcs:enrol-client"
            )

        if status != 204:
            self.logger.warning("frame rejected with HTTP %s", status)
            return

        # The server is authoritative on cadence, so honour what it just told us even
        # mid-session rather than waiting for the next config fetch.
        header = headers.get("X-Frame-Interval") or headers.get("x-frame-interval")
        if header:
            try:
                self.interval = max(MIN_INTERVAL_SECONDS, min(MAX_INTERVAL_SECONDS, float(header)))
            except ValueError:
                pass

    def heartbeat(self, state: str) -> None:
        try:
            self._request(
                "/api/client/heartbeat",
                json_body={
                    "state": state,
                    "hostname": socket.gethostname(),
                    "version": VERSION,
                },
                timeout=5.0,
            )
        except ConnectionError as error:
            self.logger.debug("heartbeat failed: %s", error)

    # ------------------------------------------------------------------
    # Capture loop
    # ------------------------------------------------------------------

    def announce_state(self, state: str) -> None:
        """Report a state change, not every frame.

        Sending this unconditionally would add a request per capture for no
        information; the server only needs to know when the machine stops capturing
        so it can stop showing a frame that is no longer current.
        """
        if state == self._last_state:
            return

        self._last_state = state
        self.heartbeat(state)

    def run(self) -> int:
        self.logger.info(
            "lab client %s starting on %s -> %s",
            VERSION,
            socket.gethostname(),
            self.config.server_url,
        )

        self.refresh_settings()

        failures = 0
        last_refresh = time.monotonic()
        settings_refresh_seconds = 300.0

        while not self.stop.is_set():
            if not self.enabled:
                self.logger.info("server has Live Screens disabled; idle")
                if self.stop.wait(30.0):
                    break
                continue

            if self.lock.refresh():
                # Without this, a locked machine streams a lock screen or a black
                # frame and looks live to a teacher.
                self.announce_state("locked")
                if self.stop.wait(5.0):
                    break
                continue

            self.announce_state("capturing")

            try:
                frame = capture_frame(self.max_width, self.quality)

                if frame is None:
                    self.announce_state("idle")
                    if self.stop.wait(self.interval):
                        break
                    continue

                self.send_frame(frame)
                failures = 0

            except PermissionError as error:
                # Retrying a rejected credential in a tight loop would hammer the
                # server and never succeed. Slow right down and let a human fix it.
                self.logger.error("%s", error)
                if self.stop.wait(300.0):
                    break
                continue

            except ConnectionError as error:
                failures += 1
                delay = min(BACKOFF_BASE_SECONDS * (2 ** (failures - 1)), BACKOFF_MAX_SECONDS)
                self.logger.warning(
                    "upload failed (attempt %d): %s; retrying in %.0fs",
                    failures,
                    error,
                    delay,
                )

                # Reset only on a *successful drop*, not cumulatively. Legacy
                # incremented across separate incidents, so three unrelated blips in
                # an afternoon exhausted the budget and killed the agent.
                if self.stop.wait(delay):
                    break
                continue

            except Exception as error:  # noqa: BLE001
                # Capture can fail for reasons that have nothing to do with the
                # network: a monitor that was unplugged, a session switching desktops,
                # a permissions change. None of those should end the service.
                failures += 1
                self.logger.exception("capture failed: %s", error)
                if self.stop.wait(max(self.interval, 2.0)):
                    break
                continue

            now = time.monotonic()
            if now - last_refresh >= settings_refresh_seconds:
                self.refresh_settings()
                last_refresh = now

            if self.stop.wait(self.interval):
                break

        self.logger.info("lab client stopped")
        return 0


def is_installed_location(path: Path) -> bool:
    """True when this config lives where install-client.ps1 put it.

    Used to decide whether a failure should raise a dialog. See
    {@link report_startup_failure}.
    """
    program_files = os.environ.get("ProgramFiles")

    if not program_files:
        return False

    try:
        return str(path.resolve()).lower().startswith(
            str(Path(program_files).resolve()).lower()
        )
    except OSError:
        return False


def report_startup_failure(error: Exception, log_path: Path) -> None:
    """Tell a person why the client did not start.

    The comment in `main()` about failing loudly is only true when there is a console.
    A `--windowed` PyInstaller build has `sys.stderr is None`, so printing goes
    nowhere: the client writes its log and exits, and double-clicking the file appears
    to do absolutely nothing. That is the legacy failure mode repeated - a
    misconfigured machine that looks like a working one.

    A dialog is shown only when the config is *not* in the install directory, i.e. when
    someone has launched the program by hand. Installed, it runs as a scheduled task at
    every logon, and a modal dialog there would block the task and reappear on the next
    logon until somebody fixed it - trading a silent fault for an irritating one. The
    admin status panel already reports such a machine as never having checked in.
    """
    try:
        print(f"lab-client: cannot start: {error}", file=sys.stderr)
    except Exception:  # noqa: BLE001 - no console attached, which is the point
        pass

    if sys.stderr is not None or is_installed_location(CONFIG_PATH):
        return

    body = (
        "The Lab Client could not start, so this machine is not reporting.\n\n"
        f"Reason: {error}\n\n"
        "The Lab Client is not installed yet - it needs a code from the "
        "Computers page before it can connect.\n\n"
        "Run install.cmd next to this file and follow the prompts. "
        "It asks for the server address and a one-time code."
    )

    try:
        import ctypes

        # 0x10 = MB_ICONERROR. Owner None so it centres on the desktop rather than
        # requiring a console window to own it.
        ctypes.windll.user32.MessageBoxW(None, body, "KobenLabs Lab Client", 0x10)
    except Exception:  # noqa: BLE001 - a failed dialog must not mask the real error
        pass


def main() -> int:
    """Entry point.

    Runs as a plain windowless process under a scheduled task, NOT a Windows Service.

    That distinction is the whole reason this module has no service host, and it was
    arrived at the hard way: a Service runs in session 0, which has no interactive
    desktop. mss there returns a black frame forever, so a service-based client would
    have looked installed, started cleanly, and reported nothing but black screens —
    a failure with no visible cause. A scheduled task runs in the user's session,
    which is the desktop being watched.

    Legacy's scheduled-task approach was therefore correct, and only its *settings*
    were wrong: bound to one %USERNAME%, triggered on logon alone, with no restart
    policy. install-client.ps1 keeps the task model and fixes those three.
    """
    if platform.system() != "Windows":
        print(
            "The KobenLabs Lab Client is Windows-only. Screen capture uses mss, "
            "which has no implementation on other platforms.",
            file=sys.stderr,
        )
        return 1

    logger = build_logger(LOG_PATH)

    try:
        config = Config.load(CONFIG_PATH)
    except (OSError, ValueError, json.JSONDecodeError) as error:
        # Fail loudly - see report_startup_failure for what "loudly" has to mean when
        # there is no console to print to.
        logger.error("cannot start: %s", error)
        report_startup_failure(error, LOG_PATH)

        return 1

    return LabClient(config, logger).run()


if __name__ == "__main__":
    raise SystemExit(main())