"""Security regressions using real PHP CGI headers and isolated temporary storage.

Run: python tests/security.py
Requires php and php-cgi on PATH; no web server, database, or third-party packages.
"""

import json
import os
from pathlib import Path
import shutil
import subprocess
import tempfile
from urllib.parse import urlencode


PROJECT = Path(__file__).resolve().parents[1]
PHP = shutil.which("php")
CGI = shutil.which("php-cgi")
CHECKS = 0


def check(value, message):
    global CHECKS
    if not value:
        raise AssertionError(message)
    CHECKS += 1


def settings(root, **overrides):
    path = root / "storage/extensions/nx_nitro_cache/settings.json"
    path.parent.mkdir(parents=True, exist_ok=True)
    data = {
        "enabled": True, "auto_clear": True, "ttl": 3600,
        "excluded_paths": [], "allowed_hosts": ["example.test"],
        "max_size_mb": 100, "max_files": 1000, "max_page_kb": 2048,
    }
    data.update(overrides)
    path.write_text(json.dumps(data), encoding="utf-8")


def request(root, path="/sample", case="public", method="GET", fields=None, **variables):
    env = os.environ.copy()
    for name in list(env):
        if name.startswith(("HTTP_", "PHP_AUTH_", "REDIRECT_HTTP_")):
            del env[name]
    body = urlencode(fields or {}).encode()
    env.update({
        "NX_CACHE_TEST_ROOT": str(root),
        "REQUEST_METHOD": method,
        "REQUEST_URI": path,
        "QUERY_STRING": path.partition("?")[2],
        "HTTP_HOST": "example.test",
        "SERVER_NAME": "example.test",
        "SERVER_PORT": "80",
        "SERVER_PROTOCOL": "HTTP/1.1",
        "SCRIPT_FILENAME": str(PROJECT / "tests/fixtures/request.php"),
        "REDIRECT_STATUS": "200",
        "HTTP_X_TEST_CASE": case,
        "CONTENT_TYPE": "application/x-www-form-urlencoded",
        "CONTENT_LENGTH": str(len(body)),
    })
    env.update(variables)
    result = subprocess.run(
        [CGI, "-n", "-d", "cgi.force_redirect=0", "-f", env["SCRIPT_FILENAME"]],
        input=body, env=env, capture_output=True, timeout=20,
    )
    if result.returncode or result.stderr:
        raise AssertionError(f"CGI failed: {result.stderr!r} {result.stdout!r}")
    raw = result.stdout.replace(b"\r\n", b"\n")
    head, separator, response_body = raw.partition(b"\n\n")
    if not separator or b"Fatal error" in response_body or b"Warning:" in response_body:
        raise AssertionError(f"Invalid CGI output: {raw!r}")
    headers = {}
    for line in head.decode().splitlines():
        name, _, value = line.partition(":")
        headers.setdefault(name.lower(), []).append(value.strip())
    return headers, response_body


def cache_files(root):
    return list((root / "storage/cache/nx_nitro_cache").glob("*.cache"))


def main():
    global CHECKS
    if not PHP or not CGI:
        raise SystemExit("php and php-cgi must be on PATH")
    with tempfile.TemporaryDirectory(prefix="nx-nitro-security-") as temporary:
        base = Path(temporary)
        root = base / "headers"
        settings(root)
        miss, body = request(root)
        hit, cached_body = request(root)
        check(miss.get("x-nx-nitro-cache") == ["MISS"], "public request MISS")
        check(hit.get("x-nx-nitro-cache") == ["HIT"], "second public request HIT")
        check(body == cached_body, "cached body matches")
        check("x-origin-rendered" not in hit, "HIT skips rendering")
        for name in [
            "content-type", "content-security-policy", "x-frame-options",
            "x-content-type-options", "referrer-policy", "permissions-policy",
            "cross-origin-opener-policy", "cache-control",
        ]:
            check(hit.get(name) == miss.get(name), f"{name} preserved on HIT")
        check("set-cookie" not in hit, "no cookie replay on HIT")
        check("age" in hit, "HIT includes Age for downstream caches")
        current, _ = request(root, case="current-csp")
        check(current.get("content-security-policy") == ["default-src 'none'"],
              "current CSP takes precedence")

        # Warm-cache bypass is as important as refusing to store the first response.
        for label, variables in [
            ("authorization", {"HTTP_AUTHORIZATION": "Bearer private"}),
            ("redirect-authorization", {"REDIRECT_HTTP_AUTHORIZATION": "Bearer private"}),
            ("basic-auth", {"PHP_AUTH_USER": "member", "PHP_AUTH_PW": "secret"}),
            ("cookie", {"HTTP_COOKIE": "member=private"}),
            ("unknown-host", {"HTTP_HOST": "evil.test", "SERVER_NAME": "evil.test"}),
            ("range", {"HTTP_RANGE": "bytes=0-10"}),
            ("origin", {"HTTP_ORIGIN": "https://private.test"}),
            ("request-no-cache", {"HTTP_CACHE_CONTROL": "no-cache"}),
            ("request-no-store", {"HTTP_CACHE_CONTROL": "no-store"}),
        ]:
            headers, _ = request(root, **variables)
            check(headers.get("x-nx-nitro-cache") != ["HIT"], f"{label} bypasses warm cache")
        for case in ["session", "early-private"]:
            headers, _ = request(root, case=case)
            check(headers.get("x-nx-nitro-cache") != ["HIT"], f"{case} bypasses warm cache")

        for case in [
            "set-cookie", "vary", "vary-star", "private", "no-store", "no-cache",
            "zero-age", "pragma", "nonce", "encoding", "cors", "non-html",
            "redirect", "error", "late-session", "invalidate", "partial", "clean-buffer",
        ]:
            root = base / ("response-" + case)
            settings(root)
            request(root, case=case)
            check(not cache_files(root), f"{case} response never stored")

        for label, path, variables in [
            ("query", "/sample?preview=1", {}),
            ("admin", "/admin/posts", {}),
            ("login", "/login", {}),
            ("install", "/install", {}),
            ("malformed-escape", "/bad%xy", {}),
            ("absolute-target", "http://example.test/sample", {}),
            ("wrong-port", "/sample", {"HTTP_HOST": "example.test:8080"}),
            ("post", "/sample", {"method": "POST"}),
        ]:
            root = base / ("request-" + label)
            settings(root)
            request(root, path=path, **variables)
            check(not cache_files(root), f"{label} request never stored")

        root = base / "empty-hosts"
        settings(root, allowed_hosts=[])
        request(root)
        check(not cache_files(root), "no configured trusted host fails closed")
        root = base / "scheme"
        settings(root)
        request(root)
        headers, _ = request(root, HTTPS="on", SERVER_PORT="443")
        check(headers.get("x-nx-nitro-cache") == ["MISS"], "HTTP and HTTPS use separate keys")
        root = base / "path-encoding"
        settings(root)
        request(root, path="/a%2Fb")
        headers, _ = request(root, path="/a/b")
        check(headers.get("x-nx-nitro-cache") == ["MISS"], "encoded separator does not alias another URL")

        for label, auth, mutate, status, fields, extra, expected in [
            ("anonymous-redirect", False, False, 302, {}, {}, False),
            ("invalid-csrf", True, True, 302, {"_csrf_token": "invalid"}, {}, False),
            ("validation-200", True, False, 200, {"_csrf_token": "valid-test-token"}, {}, False),
            ("unchanged-302", True, False, 302, {"_csrf_token": "valid-test-token"}, {}, False),
            ("failed-update", True, True, 500, {"_csrf_token": "valid-test-token"}, {}, False),
            ("successful-update", True, True, 302, {"_csrf_token": "valid-test-token"}, {}, True),
            ("legacy-no-token", True, True, 302, {}, {}, True),
            ("success-event", True, False, 200, {}, {"HTTP_X_TEST_EVENT": "page_saved"}, True),
            ("disabled-auto-clear", True, True, 302, {}, {"auto_clear": False}, False),
        ]:
            root = base / ("cleaner-" + label)
            extra = dict(extra)
            auto_clear = extra.pop("auto_clear", True)
            settings(root, auto_clear=auto_clear)
            (root / "database-state.json").write_text('{"id":1,"revision":"before"}')
            request(root)
            check(bool(cache_files(root)), f"{label} has warm fixture")
            request(
                root, path="/admin/posts/update/1", case="cleaner", method="POST",
                fields=fields, HTTP_X_TEST_AUTH="1" if auth else "",
                HTTP_X_TEST_MUTATE="1" if mutate else "",
                HTTP_X_TEST_STATUS=str(status), **extra,
            )
            check((not cache_files(root)) == expected, f"{label} invalidation decision")

        root = base / "config-change"
        settings(root)
        (root / "config").mkdir()
        (root / "config/theme.php").write_text("original theme")
        request(root)
        request(root, path="/admin/themes/update", case="cleaner", method="POST",
                HTTP_X_TEST_AUTH="1", HTTP_X_TEST_CONFIG_MUTATE="1")
        check(not cache_files(root), "legacy theme update clears only after actual config changes")

        for label, auth, role, token, status, cleared in [
            ("anonymous", "", "admin", "valid-test-token", "302", False),
            ("author", "1", "author", "valid-test-token", "403", False),
            ("admin-invalid-csrf", "1", "admin", "invalid", "419", False),
            ("admin", "1", "admin", "valid-test-token", "302", True),
        ]:
            root = base / ("controller-" + label)
            settings(root)
            request(root)
            headers, _ = request(
                root, path="/admin/extensions/nx_nitro_cache/clear-cache",
                case="controller-clear", method="POST", fields={"_csrf_token": token},
                HTTP_X_TEST_AUTH=auth, HTTP_X_TEST_ROLE=role,
            )
            check(headers.get("status", ["200 OK"])[0].split()[0] == status,
                  f"{label} controller authorization status")
            check((not cache_files(root)) == cleared, f"{label} controller mutation permission")

        root = base / "storage"
        root.mkdir()
        env = os.environ.copy()
        env["NX_CACHE_TEST_ROOT"] = str(root)
        result = subprocess.run(
            [PHP, "-n", str(PROJECT / "tests/fixtures/storage.php")],
            env=env, capture_output=True, timeout=20,
        )
        if result.returncode or result.stderr:
            raise AssertionError(f"Storage suite failed: {result.stdout!r} {result.stderr!r}")
        CHECKS += json.loads(result.stdout)["checks"]

        root = base / "parallel"
        settings(root, max_size_mb=1, max_files=4)
        # Create shared lock/generation before running independent writers.
        request(root)
        processes = []
        for worker in range(8):
            env = os.environ.copy()
            env.update(NX_CACHE_TEST_ROOT=str(root), NX_CACHE_WORKER=str(worker))
            processes.append(subprocess.Popen(
                [PHP, "-n", str(PROJECT / "tests/fixtures/parallel.php")],
                env=env, stdout=subprocess.PIPE, stderr=subprocess.PIPE,
            ))
        for process in processes:
            out, err = process.communicate(timeout=20)
            check(process.returncode == 0 and not out and not err, "parallel worker finishes cleanly")
        files = cache_files(root)
        check(0 < len(files) <= 4, "concurrent writes respect count limit")
        check(sum(file.stat().st_size for file in files) <= 1048576,
              "concurrent writes respect byte limit")
        records = []
        for file in files:
            metadata, body = file.read_bytes().split(b"\n", 1)
            record = json.loads(metadata)
            record["html"] = body.decode()
            records.append(record)
        check(all(record["html"] and "X-Frame-Options: DENY" in record["headers"]
                  for record in records), "concurrent writes publish complete body/header records")
        check(any(record["html"] == "a" * 200000 for record in records),
              "parallel workload publishes new entries")
    print(f"PASS: {CHECKS} security regression checks (PHP CLI + CGI)")


if __name__ == "__main__":
    main()
