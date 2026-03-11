#!/usr/bin/env python3
"""
Openclaw Gateway Restart Webhook
监听 0.0.0.0:8087，接受 POST /restart /logs /backups /restore 请求
需要设置环境变量 RESTART_TOKEN
"""

import http.server
import json
import os
import shutil
import subprocess
import sys

PORT = 8087
TOKEN = os.environ.get("RESTART_TOKEN", "")
OPENCLAW_DIR = os.environ.get("OPENCLAW_DIR", "/home/falcon/.openclaw")
ACTIVE_CONFIG = "openclaw.json"

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
        elif self.path == "/backups":
            self._handle_backups()
        elif self.path == "/restore":
            self._handle_restore()
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

    def _handle_backups(self):
        try:
            files = []
            for name in os.listdir(OPENCLAW_DIR):
                if not name.startswith("openclaw"):
                    continue
                if name == ACTIVE_CONFIG:
                    continue
                path = os.path.join(OPENCLAW_DIR, name)
                if not os.path.isfile(path):
                    continue
                stat = os.stat(path)
                files.append({
                    "name": name,
                    "mtime": int(stat.st_mtime),
                    "size": stat.st_size,
                })
            files.sort(key=lambda f: f["mtime"], reverse=True)
            self.send_json(200, {"ok": True, "files": files})
        except Exception as e:
            self.send_json(500, {"ok": False, "files": [], "msg": str(e)})

    def _handle_restore(self):
        try:
            length = int(self.headers.get("Content-Length", 0))
            body = self.rfile.read(length)
            data = json.loads(body)
            filename = data.get("filename", "")
        except Exception:
            self.send_json(400, {"ok": False, "msg": "invalid request body"})
            return

        # 安全校验：文件名不含路径分隔符，必须以 openclaw 开头，不能是当前配置文件
        if "/" in filename or "\\" in filename or not filename.startswith("openclaw"):
            self.send_json(400, {"ok": False, "msg": "invalid filename"})
            return
        if filename == ACTIVE_CONFIG:
            self.send_json(400, {"ok": False, "msg": "cannot restore active config to itself"})
            return

        src = os.path.join(OPENCLAW_DIR, filename)
        dst = os.path.join(OPENCLAW_DIR, ACTIVE_CONFIG)

        if not os.path.isfile(src):
            self.send_json(404, {"ok": False, "msg": f"{filename} not found"})
            return

        try:
            # 还原前先备份当前配置
            backup_dst = dst + ".bak"
            shutil.copy2(dst, backup_dst)
            shutil.copy2(src, dst)
        except Exception as e:
            self.send_json(500, {"ok": False, "msg": f"copy failed: {e}"})
            return

        # 还原成功后重启服务
        try:
            result = subprocess.run(
                ["systemctl", "--user", "restart", "openclaw-gateway"],
                capture_output=True,
                text=True,
                timeout=30,
                env=self._user_env(),
            )
            if result.returncode == 0:
                self.send_json(200, {"ok": True, "msg": f"restored {filename} and restarted"})
            else:
                err = result.stderr.strip() or result.stdout.strip() or "unknown error"
                self.send_json(500, {"ok": False, "msg": f"restored but restart failed: {err}"})
        except subprocess.TimeoutExpired:
            self.send_json(500, {"ok": False, "msg": "restored but systemctl timed out"})

    def do_GET(self):
        self.send_json(405, {"ok": False, "msg": "method not allowed"})


if __name__ == "__main__":
    server = http.server.HTTPServer(("0.0.0.0", PORT), WebhookHandler)
    print(f"[webhook] Listening on 0.0.0.0:{PORT}", flush=True)
    server.serve_forever()
