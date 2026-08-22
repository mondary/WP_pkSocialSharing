#!/usr/bin/env python3
import html
import json
import subprocess
import urllib.error
import urllib.request
from pathlib import Path

from AppKit import (
    NSAlert,
    NSAlertFirstButtonReturn,
    NSApp,
    NSApplication,
    NSApplicationActivationPolicyAccessory,
    NSMenu,
    NSMenuItem,
    NSScrollView,
    NSStatusBar,
    NSTextView,
    NSVariableStatusItemLength,
    NSWorkspace,
)
from Foundation import NSMakeRect, NSObject
from PyObjCTools import AppHelper
from Quartz import NSEventMaskLeftMouseUp, NSEventMaskRightMouseUp, NSEventTypeRightMouseUp


RUNNER_CTL = Path(__file__).resolve().parent / "runnerctl.sh"
KILL_SWITCH = Path.home() / ".config" / "pk-runners.disabled"
X_RUNNER_CONFIG = Path.home() / ".config" / "pk-x-runner.json"
MEDIUM_RUNNER_CONFIG = Path.home() / ".config" / "pk-medium-runner.json"
NAMESPACE = "pksocialsharing/v1"


class PKRunnerController(NSObject):
    def applicationDidFinishLaunching_(self, _):
        self.status_item = NSStatusBar.systemStatusBar().statusItemWithLength_(NSVariableStatusItemLength)
        self.button = self.status_item.button()
        self.button.setTarget_(self)
        self.button.setAction_("buttonClicked:")
        self.button.sendActionOn_(NSEventMaskLeftMouseUp | NSEventMaskRightMouseUp)
        self.menu = self.make_menu()
        self.refresh_icon()

    def make_menu(self):
        menu = NSMenu.alloc().init()
        menu.addItem_(self.make_menu_item("VOIR LA QUEUE X", "showXQueue:"))
        menu.addItem_(self.make_menu_item("TRAITER LA QUEUE X", "runXQueue:"))
        menu.addItem_(self.make_menu_item("VIDER LA QUEUE X...", "clearXQueue:"))
        menu.addItem_(self.make_menu_item("VOIR LES LOGS X", "openXLog:"))
        menu.addItem_(NSMenuItem.separatorItem())
        menu.addItem_(self.make_menu_item("VOIR LA QUEUE MEDIUM", "showMediumQueue:"))
        menu.addItem_(self.make_menu_item("TRAITER LA QUEUE MEDIUM", "runMediumQueue:"))
        menu.addItem_(self.make_menu_item("VIDER LA QUEUE MEDIUM...", "clearMediumQueue:"))
        menu.addItem_(self.make_menu_item("VOIR LES LOGS MEDIUM", "openMediumLog:"))
        menu.addItem_(NSMenuItem.separatorItem())
        menu.addItem_(self.make_menu_item("START RUNNERS", "startRunners:"))
        menu.addItem_(self.make_menu_item("STOP TOUT", "stopAll:"))
        menu.addItem_(NSMenuItem.separatorItem())
        menu.addItem_(self.make_menu_item("Quitter le controleur", "quit:"))
        return menu

    def make_menu_item(self, title, action):
        menu_item = NSMenuItem.alloc().initWithTitle_action_keyEquivalent_(title, action, "")
        menu_item.setTarget_(self)
        return menu_item

    def run_ctl(self, action):
        return subprocess.run([str(RUNNER_CTL), action], capture_output=True, text=True, timeout=20)

    def refresh_icon(self):
        enabled = not KILL_SWITCH.exists()
        self.button.setTitle_("🟢" if enabled else "🔴")
        self.button.setToolTip_("PK runners actifs" if enabled else "PK runners arretes")

    def buttonClicked_(self, _):
        event = NSApp.currentEvent()
        if event.type() == NSEventTypeRightMouseUp:
            self.menu.popUpMenuPositioningItem_atLocation_inView_(None, event.locationInWindow(), self.button)
            return
        if KILL_SWITCH.exists():
            self.run_ctl("start")
        else:
            self.run_ctl("stop")
        self.refresh_icon()

    def startRunners_(self, _):
        self.run_ctl("start")
        self.refresh_icon()

    def stopAll_(self, _):
        self.run_ctl("stop")
        self.refresh_icon()

    def runXQueue_(self, _):
        try:
            status = self.x_runner_call("GET", "x-browser/status")
        except RuntimeError as error:
            self.show_alert("Queue X inaccessible", str(error))
            return
        if status.get("shared_today", 0) >= status.get("daily_cap", 0):
            self.show_alert(
                "Plafond X atteint",
                f"{status.get('shared_today')} / {status.get('daily_cap')} publications ont déjà été envoyées aujourd'hui. "
                "Augmente le plafond dans WordPress ou attends demain.",
            )
            return
        result = self.run_ctl("run-x")
        if result.returncode == 0:
            self.show_alert("Queue X lancée", "Le runner X traite maintenant le prochain article en attente.")
            return
        message = result.stderr.strip() or result.stdout.strip() or "Impossible de déclencher le runner X."
        self.show_alert("Queue X non lancée", message)

    def runMediumQueue_(self, _):
        try:
            status = self.medium_runner_call("GET", "medium-browser/status")
        except RuntimeError as error:
            self.show_alert("Queue Medium inaccessible", str(error))
            return
        if status.get("shared_today", 0) >= status.get("daily_cap", 0):
            self.show_alert(
                "Plafond Medium atteint",
                f"{status.get('shared_today')} / {status.get('daily_cap')} publications ont déjà été envoyées aujourd'hui.",
            )
            return
        result = self.run_ctl("run-medium")
        if result.returncode == 0:
            self.show_alert("Queue Medium lancée", "Le runner Medium traite maintenant le prochain article en attente.")
            return
        message = result.stderr.strip() or result.stdout.strip() or "Impossible de déclencher le runner Medium."
        self.show_alert("Queue Medium non lancée", message)

    def show_alert(self, title, message):
        alert = NSAlert.alloc().init()
        alert.setMessageText_(title)
        alert.setInformativeText_(message)
        alert.addButtonWithTitle_("OK")
        alert.runModal()

    def runner_call(self, config_path, network, method, route):
        if not config_path.exists():
            raise RuntimeError(f"Configuration {network} absente : {config_path}")
        try:
            config = json.loads(config_path.read_text())
        except (OSError, json.JSONDecodeError) as error:
            raise RuntimeError(f"Configuration {network} invalide : {error}") from error
        wp_url = config.get("wp_url", "").rstrip("/")
        token = config.get("runner_token", "")
        if not wp_url or not token:
            raise RuntimeError(f"wp_url ou runner_token manquant dans la configuration {network}")
        request = urllib.request.Request(
            f"{wp_url}/wp-json/{NAMESPACE}/{route}",
            data=b"{}" if method == "POST" else None,
            headers={"X-PK-Runner-Token": token, "Accept": "application/json"},
            method=method,
        )
        try:
            with urllib.request.urlopen(request, timeout=15) as response:
                return json.loads(response.read().decode("utf-8"))
        except (urllib.error.URLError, urllib.error.HTTPError, json.JSONDecodeError) as error:
            raise RuntimeError(f"WordPress indisponible : {error}") from error

    def x_runner_call(self, method, route):
        return self.runner_call(X_RUNNER_CONFIG, "X", method, route)

    def medium_runner_call(self, method, route):
        return self.runner_call(MEDIUM_RUNNER_CONFIG, "Medium", method, route)

    def showXQueue_(self, _):
        try:
            items = self.x_runner_call("GET", "x-browser/queue").get("items", [])
        except RuntimeError as error:
            self.show_alert("Queue X inaccessible", str(error))
            return
        self.show_queue("X", items)

    def showMediumQueue_(self, _):
        try:
            items = self.medium_runner_call("GET", "medium-browser/queue").get("items", [])
        except RuntimeError as error:
            self.show_alert("Queue Medium inaccessible", str(error))
            return
        self.show_queue("Medium", items)

    def show_queue(self, network, items):
        if not items:
            self.show_alert(f"Queue {network}", "Aucun article en attente.")
            return
        lines = []
        for item in items:
            title = html.unescape(item.get("title") or "(Sans titre)").replace("\n", " ").strip()
            claimed = "  [publication en cours]" if item.get("claimed") else ""
            lines.append(f"#{item.get('id')}  {title}{claimed}")
        count = f"{len(items)} article{'s' if len(items) > 1 else ''}"
        alert = NSAlert.alloc().init()
        alert.setMessageText_(f"Queue {network}: {count}")
        alert.setInformativeText_("Articles en attente de publication par le runner.")
        scroll = NSScrollView.alloc().initWithFrame_(NSMakeRect(0, 0, 720, 420))
        scroll.setHasVerticalScroller_(True)
        text = NSTextView.alloc().initWithFrame_(scroll.bounds())
        text.setEditable_(False)
        text.setSelectable_(True)
        text.setString_("\n".join(lines))
        scroll.setDocumentView_(text)
        alert.setAccessoryView_(scroll)
        alert.addButtonWithTitle_("Fermer")
        alert.runModal()

    def clearXQueue_(self, _):
        self.clear_queue("X", "x-browser/clear-queue", self.x_runner_call)

    def clearMediumQueue_(self, _):
        self.clear_queue("Medium", "medium-browser/clear-queue", self.medium_runner_call)

    def clear_queue(self, network, route, runner_call):
        confirm = NSAlert.alloc().init()
        confirm.setMessageText_(f"Vider la queue {network} ?")
        confirm.setInformativeText_(f"Les articles retirés ne seront pas publiés par le runner {network}. Tu peux les remettre un par un depuis WordPress.")
        confirm.addButtonWithTitle_("Vider la queue")
        confirm.addButtonWithTitle_("Annuler")
        if confirm.runModal() != NSAlertFirstButtonReturn:
            return
        try:
            cleared = runner_call("POST", route).get("cleared", 0)
        except RuntimeError as error:
            self.show_alert("Purge impossible", str(error))
            return
        suffix = "s" if cleared > 1 else ""
        self.show_alert(f"Queue {network} vidée", f"{cleared} article{suffix} retiré{suffix} de la queue.")

    def open_log(self, filename):
        log_path = Path.home() / ".local" / "log" / filename
        log_path.parent.mkdir(parents=True, exist_ok=True)
        log_path.touch(exist_ok=True)
        NSWorkspace.sharedWorkspace().openFile_(str(log_path))

    def openXLog_(self, _):
        self.open_log("pk-x-runner.log")

    def openMediumLog_(self, _):
        self.open_log("pk-medium-runner.log")

    def quit_(self, _):
        NSApp.terminate_(None)


if __name__ == "__main__":
    app = NSApplication.sharedApplication()
    app.setActivationPolicy_(NSApplicationActivationPolicyAccessory)
    controller = PKRunnerController.alloc().init()
    app.setDelegate_(controller)
    AppHelper.runEventLoop()
