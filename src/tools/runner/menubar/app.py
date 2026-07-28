#!/usr/bin/env python3
import subprocess
from pathlib import Path

from AppKit import (
    NSApp,
    NSApplication,
    NSApplicationActivationPolicyAccessory,
    NSMenu,
    NSMenuItem,
    NSStatusBar,
    NSVariableStatusItemLength,
    NSWorkspace,
)
from Foundation import NSObject
from PyObjCTools import AppHelper
from Quartz import NSEventMaskLeftMouseUp, NSEventMaskRightMouseUp, NSEventTypeRightMouseUp


RUNNER_CTL = Path(__file__).resolve().parent / "runnerctl.sh"
KILL_SWITCH = Path.home() / ".config" / "pk-runners.disabled"


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
        menu.addItem_(self.make_menu_item("START RUNNERS", "startRunners:"))
        menu.addItem_(self.make_menu_item("STOP TOUT (Canary inclus)", "stopAll:"))
        menu.addItem_(NSMenuItem.separatorItem())
        menu.addItem_(self.make_menu_item("Ouvrir logs X", "openXLog:"))
        menu.addItem_(self.make_menu_item("Ouvrir logs Medium", "openMediumLog:"))
        menu.addItem_(NSMenuItem.separatorItem())
        menu.addItem_(self.make_menu_item("Quitter le controleur", "quit:"))
        return menu

    def make_menu_item(self, title, action):
        menu_item = NSMenuItem.alloc().initWithTitle_action_keyEquivalent_(title, action, "")
        menu_item.setTarget_(self)
        return menu_item

    def run_ctl(self, action):
        subprocess.run([str(RUNNER_CTL), action], capture_output=True, text=True, timeout=20)

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
