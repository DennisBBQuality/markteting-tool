"""Verify one server-side mailbox check. No Microsoft credentials or customer data leave Pitboard."""

import datetime as dt
import json
import os
import re
import sys
import time
import urllib.error
import urllib.parse
import urllib.request
from zoneinfo import ZoneInfo

ENDPOINT = "https://planning.bbquality.nl/api/trunkrs/scheduled-sync"
UUID = re.compile(r"[0-9a-f]{8}(?:-[0-9a-f]{4}){3}-[0-9a-f]{12}")


class CheckError(Exception):
    pass


class NoRedirect(urllib.request.HTTPRedirectHandler):
    def redirect_request(self, req, fp, code, msg, headers, newurl):
        # Never forward a bearer token to a redirect target.
        return None


def request_json(url, token, method="GET"):
    req = urllib.request.Request(url, data=b"" if method == "POST" else None,
                                 headers={"Authorization": "Bearer " + token,
                                          "Accept": "application/json"}, method=method)
    try:
        with urllib.request.build_opener(NoRedirect).open(req, timeout=30) as response:
            status = response.status
            data = json.loads(response.read(32768))
    except urllib.error.HTTPError as error:
        raise CheckError("HTTP " + str(error.code) + "; no completed import confirmed") from None
    except (ValueError, OSError):
        raise CheckError("Network or invalid response; no completed import confirmed") from None
    if not isinstance(data, dict):
        raise CheckError("Invalid response; no completed import confirmed")
    return status, data


def identity_token():
    # Supplied only by the GitHub-hosted runner. Never output either token.
    url = os.environ["ACTIONS_ID_TOKEN_REQUEST_URL"]
    separator = "&" if "?" in url else "?"
    _, data = request_json(url + separator + urllib.parse.urlencode({"audience": ENDPOINT}),
                           os.environ["ACTIONS_ID_TOKEN_REQUEST_TOKEN"])
    token = data.get("value")
    if not isinstance(token, str) or not token:
        raise CheckError("GitHub identity unavailable")
    return token


def verify_report(data, check_id):
    if data.get("check_id") != check_id:
        raise CheckError("Status belongs to a different check")
    if data.get("state") in ("queued", "running"):
        return False
    if data.get("state") != "completed" or data.get("result") != "ok" or data.get("mailbox_completed") is not True:
        raise CheckError("Mailbox check did not complete; inspect Pitboard connection and retry status")
    if data.get("report_current") is not True:
        raise CheckError("Mailbox checked, but today's mail with yesterday's delivery date is missing or unconfirmed")
    try:
        requested = dt.datetime.fromisoformat(data["requested_at"])
        started = dt.datetime.fromisoformat(data["started_at"])
        checked = dt.datetime.fromisoformat(data["last_checked_at"])
        received = dt.datetime.fromisoformat(data["report_received_at"])
        imported = dt.datetime.fromisoformat(data["report_imported_at"])
        mail_day = requested.astimezone(ZoneInfo("Europe/Amsterdam")).date()
        expected = mail_day - dt.timedelta(days=1)
        if not all(stamp.tzinfo is not None for stamp in (requested, started, checked, received, imported)):
            raise ValueError()
        if checked < started or started < requested or received.astimezone(ZoneInfo("Europe/Amsterdam")).date() != mail_day:
            raise ValueError()
        if data["report_date"] != expected.isoformat() or data["expected_delivery_date"] != expected.isoformat():
            raise ValueError()
    except (KeyError, ValueError, TypeError):
        raise CheckError("Missing or inconsistent completion evidence") from None
    # Only verified dates/times, never arbitrary response text or shipment data.
    print("Mailbox completed: " + checked.isoformat())
    print("Report received: " + received.isoformat())
    print("Report imported: " + imported.isoformat())
    print("Delivery date: " + expected.isoformat())
    return True


def run():
    now = dt.datetime.now(ZoneInfo("Europe/Amsterdam"))
    if os.environ.get("GITHUB_EVENT_NAME") == "schedule" and now.hour < 6:
        print("Before Dutch morning window; no mailbox check requested")
        return
    print("Trigger: " + ("schedule" if os.environ.get("GITHUB_EVENT_NAME") == "schedule" else "manual verification"))
    code, data = request_json(ENDPOINT, identity_token(), "POST")
    check_id = data.get("check_id")
    if code != 202 or not isinstance(check_id, str) or not UUID.fullmatch(check_id):
        raise CheckError("Server does not support verified checks yet; acceptance is not completion")
    print("Check accepted: " + check_id)
    # Wait beyond the existing 300-second worker timeout. No second POST on an ambiguous timeout.
    deadline = time.monotonic() + 420
    while time.monotonic() < deadline:
        time.sleep(20)
        # A fresh identity avoids expiry while polling; same repo/main/workflow restrictions apply.
        code, data = request_json(ENDPOINT + "/" + check_id, identity_token())
        if code != 200:
            raise CheckError("No readable completion status")
        if verify_report(data, check_id):
            return
    raise CheckError("Timed out waiting for mailbox completion; acceptance is not completion")


if __name__ == "__main__":
    try:
        run()
    except (CheckError, KeyError) as error:
        message = str(error) if isinstance(error, CheckError) else "GitHub identity environment unavailable"
        print("::error::" + message)
        sys.exit(1)
