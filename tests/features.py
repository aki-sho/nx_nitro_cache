"""Functional regressions, including a real local HTTP server for preloading."""

import json
import os
from pathlib import Path
import subprocess
import tempfile
import threading
from http.server import BaseHTTPRequestHandler, ThreadingHTTPServer
from html.parser import HTMLParser

import security as support

CHECKS = 0
CURL = ["-d", "extension=curl"]
if os.name == "nt":
    CURL = ["-d", "extension_dir=" + str(Path(support.PHP).parent / "ext")] + CURL


def check(value, message):
    global CHECKS
    if not value:
        raise AssertionError(message)
    CHECKS += 1


def php(root, action, data=None, curl=False):
    env = os.environ.copy()
    env.update(
        NX_CACHE_TEST_ROOT=str(root), NX_CACHE_FEATURE_ACTION=action,
        NX_CACHE_FEATURE_INPUT=json.dumps(data or {}),
    )
    result = subprocess.run(
        [support.PHP, "-n"] + (CURL if curl else []) +
        [str(support.PROJECT / "tests/fixtures/features.php")],
        env=env, capture_output=True, timeout=30,
    )
    if result.returncode or result.stderr:
        raise AssertionError(f"PHP {action} failed: {result.stdout!r} {result.stderr!r}")
    return json.loads(result.stdout)


def models(root):
    (root / "model-state.json").write_text(json.dumps({
        "posts": [
            {"id": 1, "slug": "old", "category_id": 1, "status": "published"},
            {"id": 2, "slug": "other", "category_id": 1, "status": "published"},
            {"id": 3, "slug": "third", "category_id": 2, "status": "published"},
        ],
        "pages": [{"id": 7, "slug": "about", "status": "published"}],
        "categories": [{"id": 1, "slug": "news"}, {"id": 2, "slug": "tech"}],
    }))


def records(root):
    result = {}
    for file in support.cache_files(root):
        metadata, body = file.read_bytes().split(b"\n", 1)
        record = json.loads(metadata)
        record["body"] = body
        record["file"] = file
        result[record["path"]] = record
    return result


def controller(root, action, fields=None, role="admin", token="valid-test-token"):
    return support.request(
        root, path="/admin/extensions/nx_nitro_cache/" + action,
        case="controller-action", method="POST",
        fields={"_csrf_token": token, **(fields or {})},
        HTTP_X_TEST_AUTH="1", HTTP_X_TEST_ROLE=role, HTTP_X_TEST_ACTION=action,
    )


class DashboardParser(HTMLParser):
    def __init__(self):
        super().__init__()
        self.fields = set()
        self.forms = []

    def handle_starttag(self, tag, attrs):
        attrs = dict(attrs)
        if "name" in attrs:
            self.fields.add(attrs["name"])
        if tag == "form":
            self.forms.append(attrs)


def main():
    with tempfile.TemporaryDirectory(prefix="nx-nitro-features-") as temporary:
        base = Path(temporary)
        root = base / "partial"
        support.settings(root, warmup_enabled=True, warm_base_url="http://example.test")
        models(root)
        for path in ["/", "/posts", "/post/old", "/post/other", "/post/third",
                     "/category/news", "/category/tech", "/page/about", "/custom"]:
            support.request(root, path=path)
        check(records(root)["/post/old"]["tags"] == ["post:1", "category:1"], "post dependency metadata")
        token = php(root, "token")
        support.request(
            root, path="/admin/posts/update/1", case="cleaner", method="POST",
            fields={"_csrf_token": "valid-test-token"}, HTTP_X_TEST_AUTH="1",
            HTTP_X_TEST_MODEL_MUTATE="1", HTTP_X_TEST_EVENT="post_saved",
            HTTP_X_TEST_EVENT_ID="1",
        )
        remaining = records(root)
        for path in ["/", "/posts", "/post/old", "/category/news", "/category/tech", "/custom"]:
            check(path not in remaining, f"related invalidation removes {path}")
        for path in ["/post/other", "/post/third", "/page/about"]:
            check(path in remaining, f"unrelated cache preserves {path}")
            headers, _ = support.request(root, path=path)
            check(headers.get("x-nx-nitro-cache") == ["HIT"], f"unrelated {path} still HITs")
        check(not php(root, "put", {"name": "inflight", "path": "/post/old", "token": token}),
              "partial invalidation blocks inflight old generation")
        queued = php(root, "inspect")["queue"]["jobs"].values()
        check(any(job["path"] == "/post/renamed" for job in queued), "new slug automatically queued")
        controller(root, "delete", {"paths": "/page/about"})
        check("/page/about" not in records(root) and "/post/other" in records(root), "manual URL deletion is selective")

        # The core's compatibility callback must not undo partial invalidation.
        root = base / "compatibility"
        support.settings(root)
        models(root)
        support.request(root, path="/post/other")
        support.request(root, path="/page/about")
        support.request(
            root, path="/admin/pages/update/7", case="cleaner", method="POST",
            fields={"_csrf_token": "valid-test-token"}, HTTP_X_TEST_AUTH="1",
            HTTP_X_TEST_EVENT="page_saved", HTTP_X_TEST_EVENT_ID="7", HTTP_X_TEST_COMPAT_SHIM="1",
        )
        check("/page/about" not in records(root), "page event invalidates target")
        check("/post/other" in records(root), "core legacy bridge respects selective handler")
        support.request(root, case="controller-clear", method="POST",
                        fields={"_csrf_token": "valid-test-token"}, HTTP_X_TEST_AUTH="1")
        check(not records(root), "manual full clear still works after event scope ends")

        for label, options, expected in [
            ("all-mode", {"invalidation_mode": "all"}, set()),
            ("disabled-auto", {"auto_clear": False}, {"/post/other", "/page/about"}),
        ]:
            root = base / label
            support.settings(root, **options)
            models(root)
            support.request(root, path="/post/other")
            support.request(root, path="/page/about")
            support.request(
                root, path="/admin/pages/update/7", case="cleaner", method="POST",
                fields={"_csrf_token": "valid-test-token"}, HTTP_X_TEST_AUTH="1",
                HTTP_X_TEST_EVENT="page_saved", HTTP_X_TEST_EVENT_ID="7", HTTP_X_TEST_COMPAT_SHIM="1",
            )
            check(set(records(root)) == expected, f"{label} honored with legacy core bridge")

        root = base / "ttl"
        support.settings(root, ttl=60)
        config = php(root, "configure", {
            "enabled": "1", "auto_clear": "1", "metrics_enabled": "1", "ttl": 60,
            "allowed_hosts": ["example.test"],
            "ttl_rules": "/ 300\n/post/* 600\n/post/old 120\n/post/long 3600",
        })
        check(len(config["ttl_rules"]) == 4, "TTL text rules saved")
        for path, expected in [("/", 300), ("/post/old", 120), ("/post/other", 600), ("/poster", 60)]:
            check(php(root, "ttl", {"path": path}) == expected, f"TTL specificity for {path}")
        for path in ["/", "/post/old", "/post/other", "/post/long", "/custom"]:
            support.request(root, path=path)
        for record in records(root).values():
            os.utime(record["file"], (record["file"].stat().st_atime, record["file"].stat().st_mtime - 200))
        for path, expected in [("/", "HIT"), ("/post/old", "MISS"), ("/post/other", "HIT"),
                               ("/post/long", "HIT"), ("/custom", "MISS")]:
            headers, _ = support.request(root, path=path)
            check(headers.get("x-nx-nitro-cache") == [expected], f"stored TTL and cleanup for {path}")
        settings_path = root / "storage/extensions/nx_nitro_cache/settings.json"
        previous = settings_path.read_bytes()
        for overrides in [
            {"ttl_rules": "/post/* 0"}, {"ttl_rules": "/post/* 604801"},
            {"ttl_rules": "/post/* nope"}, {"warm_base_url": "file:///etc/passwd"},
            {"warm_base_url": "http://user:pass@example.test"},
            {"warm_base_url": "http://evil.test"},
        ]:
            result = php(root, "validate", {"enabled": "1", "allowed_hosts": ["example.test"], **overrides})
            check(not result["valid"] and settings_path.read_bytes() == previous,
                  "invalid TTL or warmup origin rejected before changing settings")

        root = base / "metrics"
        support.settings(root)
        support.request(root)
        support.request(root)
        support.request(root, HTTP_COOKIE="private=do-not-record")
        support.request(root, path="/private", case="private")
        measured = php(root, "inspect")["metrics"]
        for name, expected in [("hit", 1), ("miss", 2), ("bypass", 1), ("stored", 1), ("not_stored", 1)]:
            check(measured[name] == expected, f"{name} counter")
        check(measured["hit_rate"] == 33.3, "HIT denominator excludes bypasses")
        check(measured["reasons"]["cookie"] == 1 and measured["reasons"]["response_private"] == 1,
              "request and response bypass reasons separated")
        check("do-not-record" not in json.dumps(measured), "telemetry contains no cookie values")
        controller(root, "reset", role="author")
        check(php(root, "inspect")["metrics"]["hit"] == 1, "author cannot reset metrics")
        controller(root, "reset")
        check(php(root, "inspect")["metrics"].get("hit", 0) == 0, "administrator resets metrics")
        # The actual dashboard should render every control without PHP warnings.
        _, html = support.request(root, case="dashboard", HTTP_X_TEST_AUTH="1")
        parser = DashboardParser()
        parser.feed(html.decode())
        check({"ttl_rules", "invalidation_mode", "warm_base_url", "warmup_paths", "paths"} <= parser.fields,
              "dashboard exposes all new feature controls")
        check(all(form.get("method") == "post" for form in parser.forms), "dashboard mutations use POST")

        for action in ["delete", "queue", "run", "cancel", "reset"]:
            headers, _ = controller(root, action, {"paths": "/sample"}, role="author")
            check(headers.get("status", ["200"])[0].startswith("403"), f"{action} requires extension permission")
            headers, _ = controller(root, action, {"paths": "/sample"}, token="bad")
            check(headers.get("status", ["200"])[0].startswith("419"), f"{action} requires valid CSRF")

        root = base / "queue"
        support.settings(root, warmup_enabled=True, warm_base_url="http://example.test")
        check(php(root, "queue", {"paths": ["/post/old", "/post/old", "/admin", "/a/../admin", "//evil.test"]}) == 1,
              "queue deduplicates and rejects reserved/ambiguous paths")
        missing_curl = php(root, "run")
        check(missing_curl["pending"] == 0 and missing_curl["skipped"] == 1,
              "missing optional cURL extension is reported without HTTP request")
        check(php(root, "queue", {"paths": ["/page/" + str(i) for i in range(250)]}) == 200, "queue bounded at 200")
        controller(root, "cancel")
        check(php(root, "inspect")["queue"]["pending"] == 0, "queue cancellation")

        # Real preloading: an HTTP worker invokes CGI without cookies or auth.
        root = base / "http"
        root.mkdir()
        observed = []

        class Handler(BaseHTTPRequestHandler):
            def do_GET(self):
                observed.append((self.path, dict(self.headers)))
                case = {"/private": "no-store", "/redirect": "redirect", "/missing": "error"}.get(self.path, "public")
                headers, body = support.request(
                    root, path=self.path, case=case,
                    HTTP_HOST=f"127.0.0.1:{self.server.server_port}", SERVER_PORT=str(self.server.server_port),
                )
                code = int(headers.get("status", ["200 OK"])[0].split()[0])
                self.send_response(code)
                for name, values in headers.items():
                    if name == "status":
                        continue
                    for value in values:
                        self.send_header(name, value)
                self.end_headers()
                self.wfile.write(body)

            def log_message(self, *_args):
                pass

        server = ThreadingHTTPServer(("127.0.0.1", 0), Handler)
        thread = threading.Thread(target=server.serve_forever, daemon=True)
        thread.start()
        try:
            authority = f"127.0.0.1:{server.server_port}"
            support.settings(root, allowed_hosts=[authority], warmup_enabled=True,
                             warm_base_url="http://" + authority, warm_allow_private=True)
            check(php(root, "queue", {"paths": ["/", "/article", "/private", "/redirect", "/missing"]}) == 5,
                  "HTTP warmup jobs queued")
            result = php(root, "run", {"limit": 10}, curl=True)
            check(result["pending"] == 0 and result["completed"] == 2 and result["skipped"] == 3,
                  "real HTTP worker stores public pages and skips no-store/redirect/error")
            check(set(records(root)) == {"/", "/article"}, "only eligible public pages stored")
            check(not any(path == "/admin/login" for path, _ in observed), "warmer never follows redirects")
            check(all("Cookie" not in headers and "Authorization" not in headers for _, headers in observed),
                  "warmer sends no credentials")
            # A private address is not contacted unless explicitly enabled.
            before = len(observed)
            support.settings(root, allowed_hosts=[authority], warmup_enabled=True,
                             warm_base_url="http://" + authority, warm_allow_private=False)
            php(root, "queue", {"paths": ["/blocked"]})
            result = php(root, "run", curl=True)
            check(len(observed) == before and result["last_result"] == "内部アドレスへの接続を除外",
                  "default private-address policy prevents network access")
            support.settings(root, allowed_hosts=[authority], warmup_enabled=True,
                             warm_base_url="http://" + authority, warm_allow_private=True)
            php(root, "queue", {"paths": ["/parallel-first", "/parallel-second"]})
            workers = []
            for _ in range(2):
                env = os.environ.copy()
                env.update(NX_CACHE_TEST_ROOT=str(root), NX_CACHE_FEATURE_ACTION="run",
                           NX_CACHE_FEATURE_INPUT='{"limit":1}')
                workers.append(subprocess.Popen(
                    [support.PHP, "-n"] + CURL + [str(support.PROJECT / "tests/fixtures/features.php")],
                    env=env, stdout=subprocess.PIPE, stderr=subprocess.PIPE,
                ))
            for worker in workers:
                out, err = worker.communicate(timeout=20)
                check(worker.returncode == 0 and not err and "pending" in json.loads(out),
                      "parallel warmup worker completes")
            for path in ["/parallel-first", "/parallel-second"]:
                check(sum(seen == path for seen, _ in observed) == 1, "warmup lease prevents duplicate HTTP requests")
            # A cache write may yield to another origin request; advance retry backoff
            # without sleeping, then verify the queued retry completes the missing page.
            queue_file = root / "storage/cache/nx_nitro_cache/.warmup.json"
            state = json.loads(queue_file.read_text(encoding="utf-8"))
            for job in (state.get("jobs") or {}).values():
                job["after"] = 0
            queue_file.write_text(json.dumps(state), encoding="utf-8")
            retried = php(root, "run", {"limit": 10}, curl=True)
            check(retried["pending"] == 0 and
                  {"/parallel-first", "/parallel-second"} <= set(records(root)),
                  "contended warmup writes remain queued and finish on retry")
            # The shipped CLI worker uses the same queue and settings.
            php(root, "queue", {"paths": ["/cli"]})
            env = os.environ.copy()
            completed = subprocess.run(
                [support.PHP, "-n"] + CURL +
                [str(support.PROJECT / "warm-cache.php"), "--root=" + str(root), "--limit=1"],
                env=env, capture_output=True, timeout=20,
            )
            check(completed.returncode == 0 and not completed.stderr and
                  json.loads(completed.stdout)["pending"] == 0, "standalone CLI worker runs")
            check("/cli" in records(root), "standalone CLI worker generates queued page")
        finally:
            server.shutdown()
            server.server_close()
            thread.join(timeout=5)
    print(f"PASS: {CHECKS} feature regression checks (including real local HTTP warmup)")


if __name__ == "__main__":
    main()
