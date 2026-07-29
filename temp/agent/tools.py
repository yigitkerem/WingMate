"""Agent tool definitions (function-calling schema) and dispatch.

Design principle: prices, rules and availability ALWAYS come from the pricer service.
Write operations (purchases) are two-phase and never run without human approval:
  1. quote_purchase  -> READ:  price breakdown + records the pending intent
  2. commit_purchase -> WRITE: only after the user has explicitly approved
"""

from __future__ import annotations

from typing import Any

from app.pricer import service

# Phrases treated as explicit user approval (used by the purchase guardrail)
AFFIRMATIVE_TOKENS = (
    "evet", "onayl", "tamam", "olur", "kabul", "yes", "confirm", "satin al", "al ", "alalim",
)


def is_affirmative(text: str) -> bool:
    t = (text or "").strip().lower()
    return any(tok in t for tok in AFFIRMATIVE_TOKENS)


TOOLS: list[dict[str, Any]] = [
    {
        "name": "list_airports",
        "description": "Return every airport code and name available in the system.",
        "input_schema": {"type": "object", "properties": {}},
    },
    {
        "name": "search_flights",
        "description": (
            "Search flights and fares for a route and date. Prices, availability and rules"
            " come from the system. seat_passengers = adults + children (infants take no seat)."
            " Pass changeable_only=true to return only changeable fares."
        ),
        "input_schema": {
            "type": "object",
            "properties": {
                "origin": {"type": "string", "description": "Departure airport code, e.g. IST"},
                "destination": {"type": "string", "description": "Arrival airport code, e.g. LHR"},
                "date": {"type": "string", "description": "Date in YYYY-MM-DD format"},
                "adults": {"type": "integer", "default": 1},
                "children": {"type": "integer", "default": 0},
                "babies": {"type": "integer", "default": 0},
                "trip_type": {"type": "string", "enum": ["one_way", "round_trip"], "default": "one_way"},
                "changeable_only": {"type": "boolean", "default": False},
            },
            "required": ["origin", "destination", "date"],
        },
    },
    {
        "name": "quote_purchase",
        "description": (
            "Prepare a purchase QUOTE for a specific fare (does not buy yet). Returns the total"
            " family price including all taxes and waits for approval. This MUST be called"
            " before any purchase and the summary shown to the user."
        ),
        "input_schema": {
            "type": "object",
            "properties": {
                "availability_id": {"type": "integer"},
                "first_name": {"type": "string"},
                "last_name": {"type": "string"},
                "passport_number": {"type": "string"},
                "adults": {"type": "integer", "default": 1},
                "children": {"type": "integer", "default": 0},
                "babies": {"type": "integer", "default": 0},
            },
            "required": ["availability_id", "first_name", "last_name", "passport_number"],
        },
    },
    {
        "name": "commit_purchase",
        "description": (
            "COMMIT the pending purchase quote (write operation). May only be called after the"
            " user has explicitly approved the previous quote. Takes no arguments; the"
            " stored pending intent is used."
        ),
        "input_schema": {"type": "object", "properties": {}},
    },
    {
        "name": "search_knowledge_base",
        "description": (
            "OFFICIAL DOCUMENT SEARCH (RAG). Use for rule, policy and passenger-rights questions:"
            " EcoFly/ExtraFly/PrimeFly/BusinessFly/BusinessPrime package contents, baggage"
            " allowance, seat selection, change (reissue) and refund rights, mileage earning;"
            " passenger rights on cancellation/delay/denied boarding, compensation,"
            " catering and accommodation duties, check-in cut-off times."
            " Sources: the official THY branded-fares announcement and the SHGM SHY-YOLCU"
            " regulation. IMPORTANT: this tool does NOT provide current prices or seat"
            " availability — use search_flights / search_thy_live for those. Cite the source"
            " (document name and page) in your answer."
        ),
        "input_schema": {
            "type": "object",
            "properties": {
                "query": {
                    "type": "string",
                    "description": "The rule/policy question to look up (natural language)",
                },
                "k": {"type": "integer", "description": "How many results to return (default 3)"},
            },
            "required": ["query"],
        },
    },
    {
        "name": "search_thy_live",
        "description": (
            "Search prices on the real Turkish Airlines live MCP (read-only), for comparison"
            " against our own system. Returns a warning if the endpoint is not configured."
        ),
        "input_schema": {
            "type": "object",
            "properties": {
                "origin": {"type": "string"},
                "destination": {"type": "string"},
                "date": {"type": "string", "description": "Outbound date, YYYY-MM-DD"},
                "return_date": {"type": "string", "description": "Return date (required for round_trip)"},
                "adults": {"type": "integer", "default": 1},
                "children": {"type": "integer", "default": 0},
                "babies": {"type": "integer", "default": 0},
                "trip_type": {"type": "string", "enum": ["one_way", "round_trip"], "default": "one_way"},
            },
            "required": ["origin", "destination", "date"],
        },
    },
    {
        "name": "build_dynamic_bundles",
        "description": (
            "Build personalised bundles from the user's travel needs. Combines existing flight,"
            " fare and ancillary products into three options: Economy, Recommended, Comfort."
            " The AI does not create new products or prices; every component comes from the"
            " system. Call this once the user's needs are understood and enough details"
            " have been gathered."
        ),
        "input_schema": {
            "type": "object",
            "properties": {
                "origin": {"type": "string"},
                "destination": {"type": "string"},
                "date": {"type": "string", "description": "YYYY-MM-DD"},
                "adults": {"type": "integer", "default": 1},
                "children": {"type": "integer", "default": 0},
                "babies": {"type": "integer", "default": 0},
                "trip_type": {"type": "string", "enum": ["one_way", "round_trip"], "default": "one_way"},
            },
            "required": ["origin", "destination", "date"],
        },
    },
]


def dispatch(session: "Any", name: str, tool_input: dict) -> dict:
    """Observability wrapper: log every tool call with its duration and outcome."""
    import time

    started = time.time()
    try:
        result = _dispatch_impl(session, name, tool_input)
        ok = not (isinstance(result, dict) and (result.get("error") or result.get("success") is False))
        return result
    except Exception as exc:  # noqa: BLE001
        ok = False
        result = {"error": "tool_exception", "message": str(exc)}
        return result
    finally:
        record_call(name, tool_input, ok, int((time.time() - started) * 1000))


# --- Observability: son tool cagrilarinin bellek ici kaydi ---
CALL_LOG: list[dict] = []
MAX_LOG = 60


def record_call(name: str, tool_input: dict, ok: bool, duration_ms: int) -> None:
    from datetime import datetime

    CALL_LOG.append(
        {
            "ts": datetime.now().strftime("%H:%M:%S"),
            "tool": name,
            "ok": ok,
            "ms": duration_ms,
            "input": {k: v for k, v in list((tool_input or {}).items())[:4]},
        }
    )
    if len(CALL_LOG) > MAX_LOG:
        del CALL_LOG[0 : len(CALL_LOG) - MAX_LOG]


def log_stats() -> dict:
    total = len(CALL_LOG)
    ok = sum(1 for c in CALL_LOG if c["ok"])
    avg = int(sum(c["ms"] for c in CALL_LOG) / total) if total else 0
    by_tool: dict[str, int] = {}
    for c in CALL_LOG:
        by_tool[c["tool"]] = by_tool.get(c["tool"], 0) + 1
    return {
        "total_calls": total,
        "success": ok,
        "failed": total - ok,
        "avg_ms": avg,
        "by_tool": by_tool,
        "recent": list(reversed(CALL_LOG[-12:])),
    }


def _dispatch_impl(session: "Any", name: str, tool_input: dict) -> dict:
    """Bir tool cagrisini yurutur. `session` guardrail durumunu tasir."""
    if name == "list_airports":
        return {"airports": service.list_airports()}

    if name == "search_flights":
        return service.search_flights(
            origin=tool_input["origin"],
            destination=tool_input["destination"],
            date=tool_input["date"],
            adults=int(tool_input.get("adults", 1)),
            children=int(tool_input.get("children", 0)),
            babies=int(tool_input.get("babies", 0)),
            trip_type=tool_input.get("trip_type", "one_way"),
            changeable_only=bool(tool_input.get("changeable_only", False)),
        )

    if name == "quote_purchase":
        avail = service.get_availability(int(tool_input["availability_id"]))
        if avail is None:
            return {"error": "not_found", "message": "Fare not found."}
        adults = int(tool_input.get("adults", 1))
        children = int(tool_input.get("children", 0))
        babies = int(tool_input.get("babies", 0))
        seat_passengers = adults + children
        from app.pricer import domain

        breakdown = domain.price_breakdown(avail["base_price_usd"], adults, children, babies)
        # Guardrail: store the pending intent only; no WRITE happens here.
        session.pending_purchase = {
            "availability_id": avail["id"],
            "first_name": tool_input["first_name"],
            "last_name": tool_input["last_name"],
            "passport_number": tool_input["passport_number"],
            "adults": adults,
            "children": children,
            "babies": babies,
        }
        return {
            "status": "confirmation_required",
            "message": "This requires APPROVAL. Show the summary to the user and wait for explicit consent.",
            "flight_number": avail["flight_number"],
            "date": avail["date"],
            "class": avail["class"],
            "seats_to_book": seat_passengers,
            "seats_left": avail["count_available"],
            "price_breakdown": breakdown,
        }

    if name == "commit_purchase":
        pending = getattr(session, "pending_purchase", None)
        if not pending:
            return {
                "success": False,
                "error": "no_pending_quote",
                "message": "A quote must be prepared with quote_purchase first.",
            }
        # Guardrail: the user must have explicitly approved in this turn.
        if not getattr(session, "user_confirmed", False):
            return {
                "success": False,
                "error": "confirmation_missing",
                "message": "Cannot purchase without explicit user approval. Ask for confirmation.",
            }
        result = service.purchase_ticket(**pending)
        if result.get("success"):
            session.pending_purchase = None
            session.user_confirmed = False
        return result

    if name == "search_knowledge_base":
        from app.agent import rag

        return rag.search(
            query=tool_input["query"],
            k=int(tool_input.get("k", 3)),
        )

    if name == "search_thy_live":
        from app.agent import thy_mcp

        return thy_mcp.search(tool_input)

    if name == "build_dynamic_bundles":
        return service.build_bundles(
            origin=tool_input["origin"],
            destination=tool_input["destination"],
            date=tool_input["date"],
            adults=int(tool_input.get("adults", 1)),
            children=int(tool_input.get("children", 0)),
            babies=int(tool_input.get("babies", 0)),
            trip_type=tool_input.get("trip_type", "one_way"),
        )

    return {"error": "unknown_tool", "name": name}
