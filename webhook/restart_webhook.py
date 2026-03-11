#!/usr/bin/env python3
"""
Openclaw Gateway Restart Webhook
监听 0.0.0.0:8087，接受 POST /restart 请求，执行 systemctl restart openclaw-gateway
需要设置环境变量 RESTART_TOKEN
"""

import http.server
import json
import os
import subprocess
import sys

PORT = 8087
TOKEN = os.environ.get("RESTART_TOKEN", "")

if not TOKEN:
    print("ERROR: RESTART_TOKEN environment variable is not set", file=sys.stderr)
    sys.exit(1)


class WebhookHandler(http.server.BaseHTTPRequestHandler):
    def log_message(self, format, *args):
        print(f"[webhook] {self.address_string()} - {format % args}", flush=True)

    def send_json(self, status: int, payload: dict):
        body = json.dumps(payload).encode()
        self.send_response(status)
        self.send_header("Content-Type", "application/json")
        self.send_header("Content-Length", str(len(body)))
        self.end_headers()
        self.wfile.write(body)

    def _user_env(self):
        uid = os.getuid()
        env = os.environ.copy()
        env["XDG_RUNTIME_DIR"] = f"/run/user/{uid}"
        env["DBUS_SESSION_BUS_ADDRESS"] = f"unix:path=/run/user/{uid}/bus"
        return env

    def _check_token(self):
        token = self.headers.get("X-Token", "")
        if token != TOKEN:
            self.send_json(403, {"ok": False, "msg": "forbidden"})
            return False
        return True

    def do_POST(self):
        if not self._check_token():
            return

        if self.path == "/restart":
            self._handle_restart()
        elif self.path == "/logs":
            self._handle_logs()
        else:
            self.send_json(404, {"ok": False, "msg": "not found"})

    def _handle_restart(self):
        try:
            result = subprocess.run(
                ["systemctl", "--user", "restart", "openclaw-gateway"],
                capture_output=True,
                text=True,
                timeout=30,
                env=self._user_env(),
            )
            if result.returncode == 0:
                self.send_json(200, {"ok": True, "msg": "openclaw-gateway restarted"})
            else:
                err = result.stderr.strip() or result.stdout.strip() or "unknown error"
                self.send_json(500, {"ok": False, "msg": err})
        except subprocess.TimeoutExpired:
            self.send_json(500, {"ok": False, "msg": "systemctl timed out"})
        except Exception as e:
            self.send_json(500, {"ok": False, "msg": str(e)})

    def _handle_logs(self):
        try:
            n = os.environ.get("LOG_LINES", "50")
            n = str(int(n)) if n.isdigit() else "50"
            result = subprocess.run(
                ["journalctl", "--user", "-u", "openclaw-gateway",
                 "-n", n, "--no-pager", "--output=short"],
                capture_output=True,
                text=True,
                timeout=10,
                env=self._user_env(),
            )
            lines = result.stdout.splitlines()
            self.send_json(200, {"ok": True, "lines": lines})
        except subprocess.TimeoutExpired:
            self.send_json(500, {"ok": False, "lines": [], "msg": "journalctl timed out"})
        except Exception as e:
            self.send_json(500, {"ok": False, "lines": [], "msg": str(e)})

    def do_GET(self):
        self.send_json(405, {"ok": False, "msg": "method not allowed"})


if __name__ == "__main__":
    server = http.server.HTTPServer(("0.0.0.0", PORT), WebhookHandler)
    print(f"[webhook] Listening on 0.0.0.0:{PORT}", flush=True)
    server.serve_forever()
