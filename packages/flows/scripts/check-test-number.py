#!/usr/bin/env python3
"""Refuse to use a WhatsApp number for testing unless it does not exist.

Usage:
    WAHA_API_KEY=... python3 scripts/check-test-number.py 5511000000001

Exits 0 when WhatsApp reports `numberExists: false`, 1 when the number exists
(never contact it), and 2 on a lookup error.
"""

from __future__ import annotations

import json
import os
import sys
import urllib.error
import urllib.parse
import urllib.request

WAHA_URL = os.environ.get("WAHA_URL", "https://waha.vaif.com.br").rstrip("/")
WAHA_API_KEY = os.environ.get("WAHA_API_KEY", "")
SESSION = os.environ.get("WAHA_SESSION", "teste")


def main() -> int:
    if len(sys.argv) != 2:
        print(__doc__.strip(), file=sys.stderr)
        return 2

    phone = "".join(ch for ch in sys.argv[1] if ch.isdigit())
    if not phone:
        print("missing phone number", file=sys.stderr)
        return 2
    if not WAHA_API_KEY:
        print("WAHA_API_KEY is not set", file=sys.stderr)
        return 2

    query = urllib.parse.urlencode({"phone": phone, "session": SESSION})
    request = urllib.request.Request(
        f"{WAHA_URL}/api/contacts/check-exists?{query}",
        headers={"X-Api-Key": WAHA_API_KEY},
    )

    try:
        with urllib.request.urlopen(request, timeout=20) as response:
            data = json.load(response)
    except (urllib.error.URLError, json.JSONDecodeError) as exc:
        print(f"lookup failed: {exc}", file=sys.stderr)
        return 2

    if data.get("numberExists"):
        print(f"REFUSE: +{phone} exists on WhatsApp ({data.get('chatId')})")
        return 1

    print(f"OK: +{phone} does not exist on WhatsApp")
    return 0


if __name__ == "__main__":
    raise SystemExit(main())
