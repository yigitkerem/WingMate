"""Client for the real Turkish Airlines live MCP server.

Returns a 'not configured' status when THY_MCP_URL is unset. Once configured, real
searches run through a streamable-HTTP MCP client, so the dual-source architecture
starts working as soon as the endpoint is available.
"""

from __future__ import annotations

import asyncio
import os


def is_configured() -> bool:
    return bool(os.getenv("THY_MCP_URL"))


def search(tool_input: dict) -> dict:
    if not is_configured():
        return {
            "status": "not_configured",
            "message": (
                "The live THY MCP endpoint is not configured. Add THY_MCP_URL to .env and"
                " this tool will return real Turkish Airlines prices."
            ),
        }
    from . import thy_auth

    if not thy_auth.has_stored_tokens():
        return {
            "status": "auth_required",
            "message": (
                "Not signed in to the THY MCP. Run this in a terminal and sign in with"
                " Miles&Smiles: python -m app.agent.thy_auth"
            ),
        }
    try:
        return asyncio.run(_search_async(tool_input))
    except Exception as exc:  # noqa: BLE001 - surface the error transparently to the user
        return {"status": "error", "message": f"THY MCP connection error: {exc}"}


# Minimal IATA -> country-code map (airports covered by this MVP)
AIRPORT_COUNTRY: dict[str, str] = {
    "IST": "TR", "SAW": "TR", "ESB": "TR", "ADB": "TR", "AYT": "TR",
    "LHR": "GB", "LGW": "GB", "STN": "GB",
    "CDG": "FR", "ORY": "FR",
    "AMS": "NL", "FRA": "DE", "MUC": "DE",
    "DXB": "AE", "JFK": "US", "EWR": "US", "SIN": "SG",
    "HND": "JP", "NRT": "JP", "KIX": "JP",
}


def _thy_date(iso_date: str, hour: str = "00:00") -> str:
    """Convert YYYY-MM-DD to the 'DD-MM-YYYY HH:mm' format the THY MCP expects."""
    y, m, d = iso_date.split("-")
    return f"{d}-{m}-{y} {hour}"


def _ensure_ready() -> dict | None:
    """Check configuration and auth; return an error dict when not ready."""
    if not is_configured():
        return {"status": "not_configured", "message": "THY_MCP_URL is not set."}
    from . import thy_auth

    if not thy_auth.has_stored_tokens():
        return {
            "status": "auth_required",
            "message": "Not signed in to the THY MCP. Run: python -m app.agent.thy_auth",
        }
    return None


async def _call_async(tool_name: str, args: dict) -> dict:
    """Call any tool on the THY MCP directly (no LLM involved)."""
    from mcp import ClientSession
    from mcp.client.streamable_http import streamablehttp_client

    from . import thy_auth

    url = os.environ["THY_MCP_URL"]
    async with streamablehttp_client(url, auth=thy_auth.build_provider()) as (read, write, _):
        async with ClientSession(read, write) as sess:
            await sess.initialize()
            result = await sess.call_tool(tool_name, args)
            text = "".join(getattr(c, "text", "") for c in result.content)
            return {"status": "ok", "text": text}


def call_tool(tool_name: str, args: dict) -> dict:
    """Synchronous wrapper around a direct THY MCP tool call."""
    err = _ensure_ready()
    if err:
        return err
    try:
        return asyncio.run(_call_async(tool_name, args))
    except Exception as exc:  # noqa: BLE001
        return {"status": "error", "message": f"THY MCP error: {exc}"}


def flight_status_by_route(from_airport: str, to_airport: str, flight_date: str) -> dict:
    return call_tool(
        "get_flight_status_by_route",
        {
            "fromAirport": from_airport.upper(),
            "toAirport": to_airport.upper(),
            "flightDate": flight_date,
            "language": "TR",
        },
    )


def booking_details(booking_reference: str, surname: str) -> dict:
    return call_tool(
        "get_booking_details",
        {"bookingReference": booking_reference.upper(), "surname": surname, "language": "TR"},
    )


def booking_baggage(booking_reference: str, surname: str) -> dict:
    return call_tool(
        "get_booking_baggage_allowance",
        {"bookingReference": booking_reference.upper(), "surname": surname},
    )


async def _search_async(tool_input: dict) -> dict:
    from mcp import ClientSession
    from mcp.client.streamable_http import streamablehttp_client

    from . import thy_auth

    url = os.environ["THY_MCP_URL"]
    origin = tool_input["origin"].upper()
    dest = tool_input["destination"].upper()
    origin_cc = AIRPORT_COUNTRY.get(origin)
    dest_cc = AIRPORT_COUNTRY.get(dest)
    if not origin_cc or not dest_cc:
        return {
            "status": "error",
            "message": f"Unknown country code for {origin} or {dest}."
            f" Supported: {sorted(AIRPORT_COUNTRY)}",
        }

    trip_type = "round" if tool_input.get("trip_type") == "round_trip" else "one_way"
    segments = [
        {
            "departureDateTime": {"departureDate": _thy_date(tool_input["date"])},
            "originAirportCode": origin,
            "originCountryCode": origin_cc,
            "destinationAirportCode": dest,
            "destinationCountryCode": dest_cc,
        }
    ]
    if trip_type == "round":
        return_date = tool_input.get("return_date")
        if not return_date:
            return {"status": "error", "message": "return_date is required for round trips."}
        segments.append(
            {
                "departureDateTime": {"departureDate": _thy_date(return_date)},
                "originAirportCode": dest,
                "originCountryCode": dest_cc,
                "destinationAirportCode": origin,
                "destinationCountryCode": origin_cc,
            }
        )

    passengers = [{"passengerType": "ADT", "quantity": int(tool_input.get("adults", 1))}]
    if int(tool_input.get("children", 0)):
        passengers.append({"passengerType": "CHD", "quantity": int(tool_input["children"])})
    if int(tool_input.get("babies", 0)):
        passengers.append({"passengerType": "INF", "quantity": int(tool_input["babies"])})

    async with streamablehttp_client(url, auth=thy_auth.build_provider()) as (read, write, _):
        async with ClientSession(read, write) as sess:
            await sess.initialize()
            result = await sess.call_tool(
                "search_flights",
                {
                    "originDestinations": segments,
                    "passengers": passengers,
                    "tripType": trip_type,
                },
            )
            return {"status": "ok", "raw": [c.model_dump() for c in result.content]}
