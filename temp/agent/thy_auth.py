"""Turkish Airlines MCP OAuth 2.0 flow (dynamic registration + PKCE).

On first run a browser opens for Miles&Smiles sign-in; the tokens are stored in
data/thy_tokens.json and reused/refreshed automatically afterwards (the server
supports refresh_token).

One-time sign-in:  python -m app.agent.thy_auth
"""

from __future__ import annotations

import asyncio
import json
import os
import threading
import webbrowser
from http.server import BaseHTTPRequestHandler, HTTPServer
from pathlib import Path
from urllib.parse import parse_qs, urlparse

from mcp.client.auth import OAuthClientProvider, TokenStorage
from mcp.shared.auth import OAuthClientInformationFull, OAuthClientMetadata, OAuthToken

TOKEN_FILE = Path(__file__).resolve().parent.parent.parent / "data" / "thy_tokens.json"
CALLBACK_PORT = 8765
REDIRECT_URI = f"http://localhost:{CALLBACK_PORT}/callback"


class FileTokenStorage(TokenStorage):
    """Persist tokens and client registration to disk (sufficient for a demo)."""

    def _read(self) -> dict:
        if TOKEN_FILE.exists():
            return json.loads(TOKEN_FILE.read_text())
        return {}

    def _write(self, data: dict) -> None:
        TOKEN_FILE.parent.mkdir(parents=True, exist_ok=True)
        TOKEN_FILE.write_text(json.dumps(data, indent=2))

    async def get_tokens(self) -> OAuthToken | None:
        data = self._read().get("tokens")
        return OAuthToken.model_validate(data) if data else None

    async def set_tokens(self, tokens: OAuthToken) -> None:
        data = self._read()
        data["tokens"] = tokens.model_dump(exclude_none=True)
        self._write(data)

    async def get_client_info(self) -> OAuthClientInformationFull | None:
        data = self._read().get("client_info")
        return OAuthClientInformationFull.model_validate(data) if data else None

    async def set_client_info(self, client_info: OAuthClientInformationFull) -> None:
        data = self._read()
        data["client_info"] = client_info.model_dump(exclude_none=True, mode="json")
        self._write(data)


class _CallbackHandler(BaseHTTPRequestHandler):
    """Tiny local HTTP server that captures the OAuth redirect."""

    result: dict = {}

    def do_GET(self):  # noqa: N802
        q = parse_qs(urlparse(self.path).query)
        _CallbackHandler.result = {
            "code": q.get("code", [None])[0],
            "state": q.get("state", [None])[0],
            "error": q.get("error", [None])[0],
        }
        self.send_response(200)
        self.send_header("Content-Type", "text/html; charset=utf-8")
        self.end_headers()
        self.wfile.write(
            "<html><body style='font-family:sans-serif;text-align:center;padding-top:120px'>"
            "<h2>&#10004; Signed in</h2><p>You can close this window and return to the app.</p>"
            "</body></html>".encode()
        )

    def log_message(self, *args):  # silence request logging
        pass


def build_provider() -> OAuthClientProvider:
    server_url = os.getenv("THY_MCP_URL", "https://mcp.turkishtechlab.com/mcp")
    done = threading.Event()

    async def redirect_handler(auth_url: str) -> None:
        print(f"\nSign in using the address opened in your browser:\n{auth_url}\n")
        webbrowser.open(auth_url)

    async def callback_handler() -> tuple[str, str | None]:
        server = HTTPServer(("localhost", CALLBACK_PORT), _CallbackHandler)

        def serve():
            while not _CallbackHandler.result:
                server.handle_request()
            done.set()

        t = threading.Thread(target=serve, daemon=True)
        t.start()
        # Wait for the user to finish signing in via the browser (5 min)
        await asyncio.get_event_loop().run_in_executor(None, done.wait, 300)
        server.server_close()
        res = _CallbackHandler.result
        _CallbackHandler.result = {}
        if not res or res.get("error") or not res.get("code"):
            raise RuntimeError(f"OAuth sign-in did not complete: {res}")
        return res["code"], res.get("state")

    return OAuthClientProvider(
        server_url=server_url,
        client_metadata=OAuthClientMetadata(
            redirect_uris=[REDIRECT_URI],
            token_endpoint_auth_method="none",  # PKCE public client
            grant_types=["authorization_code", "refresh_token"],
            response_types=["code"],
            client_name="Pricing Chatbot MVP",
        ),
        storage=FileTokenStorage(),
        redirect_handler=redirect_handler,
        callback_handler=callback_handler,
    )


def has_stored_tokens() -> bool:
    try:
        return bool(json.loads(TOKEN_FILE.read_text()).get("tokens"))
    except Exception:  # noqa: BLE001
        return False


async def _login_and_verify() -> None:
    from mcp import ClientSession
    from mcp.client.streamable_http import streamablehttp_client

    url = os.getenv("THY_MCP_URL", "https://mcp.turkishtechlab.com/mcp")
    async with streamablehttp_client(url, auth=build_provider()) as (r, w, _):
        async with ClientSession(r, w) as s:
            await s.initialize()
            tools = await s.list_tools()
            print(f"CONNECTED - {len(tools.tools)} tools available:")
            for t in tools.tools:
                print("  -", t.name)


if __name__ == "__main__":
    from dotenv import load_dotenv

    load_dotenv()
    asyncio.run(_login_and_verify())
