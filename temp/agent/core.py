"""AI Agent core: Azure OpenAI tool-calling loop with guardrails.

Each Session keeps the conversation history and any pending purchase intent.
The web layer calls `Session.send(user_text, source, lang)` per user message.

Design rule: the LLM never invents prices, availability or policy. Those always
come from tools (grounding). Purchases are two-phase and require explicit user
confirmation (guardrail).
"""

from __future__ import annotations

import json
import os
from datetime import date

from openai import OpenAI

from . import tools

MODEL = os.getenv("AGENT_MODEL", "gpt-5.4-mini")
# Azure OpenAI v1 endpoint (compatible with the OpenAI SDK), e.g.
#   https://<resource>.openai.azure.com/openai/v1/
BASE_URL = os.getenv("AZURE_OPENAI_BASE_URL", "https://mavi-resource.openai.azure.com/openai/v1/")
MAX_TOOL_ITERS = 8

SYSTEM_PROMPT = """You are Wingo, an airline pricing and booking assistant (AI agent).
Your job: understand the user's natural-language request, call the right tools in the
right order, and present a clear, explainable offer.

SCOPE (strict): you only handle flight search, fares, booking, baggage, passenger
rights and other airline/travel topics covered by your tools and knowledge base.
For anything unrelated (recipes, general trivia, coding help, personal advice, etc.)
politely decline in one short sentence and steer back to travel — e.g. "I can only
help with flights and travel here — want help finding a flight?" Do NOT answer the
off-topic question itself, even partially, even if you know the answer.

HARD RULES (guardrails):
- NEVER invent prices, availability or fare rules. They come only from tool results.
  Every number you state must originate from a tool response.
- Purchases (writes) are two-phase: first call `quote_purchase` and show the user the
  total including taxes; do NOT call `commit_purchase` until the user explicitly agrees.
- If information is missing (date, passenger count, route), ask short follow-up
  questions first. Do not assume.
- If an airport code is ambiguous, verify with list_airports. A city is not an airport;
  when the user names a city, confirm the intended airport code.
- Child fare is 75% of the base fare, infant 10% and infants occupy no seat; tax is $240
  per person and infants are exempt. This breakdown comes from tools; you only explain it.
- The primary inventory is our own system (search_flights). `search_thy_live` is real
  Turkish Airlines data; use it when the user explicitly asks for real THY prices or a
  comparison, or when our inventory lacks the route. When you present figures from
  both, distinguish them in plain, natural language (e.g. "our fare is $X, while
  Turkish Airlines' live price is $Y") — never use bracket tags like [Our system] or
  [THY live] in the reply text; those are internal labels, not something to print.
  Purchases are NOT possible on THY live (read-only) — booking happens only in our
  system.

CHOOSING THE INFORMATION SOURCE (critical):
- PRICE, SEATS, AVAILABILITY, PURCHASE -> live tools (search_flights, search_thy_live).
  Never answer these from documents; documents go stale.
- RULES, POLICY, RIGHTS, OBLIGATIONS -> search_knowledge_base (official document search).
  Examples: fare package contents, baggage allowance, change/refund rights, mileage
  earning, passenger rights on cancellation, compensation, catering/accommodation
  duties, check-in cut-off times.
- When answering a policy question, cite the SOURCE: document name + page,
  e.g. "(Source: THY Branded Fares announcement, p.5)".
- If the documents contain no answer, do not invent one; say the official sources do not
  cover it and it should be confirmed with THY customer service.
- The branded fares document is from 2022: the package STRUCTURE is still valid, but for
  EXACT fees use the live system and tell the user so.

SPEECH-INPUT TOLERANCE: the message may have been dictated; speech recognition often
mangles English brand names. Infer the intended term, e.g.
"ekstra play / extra flight / exterfly / ekstra sila" -> ExtraFly;
"sılai / prime flight" -> PrimeFly; "john flai / eko fly" -> EcoFly;
"fleks flay" -> FlexFly. If unsure, confirm briefly instead of assuming.

LANGUAGE (very important): reply in the SAME language the user wrote their last message
in. If they wrote in English, answer fully in English; if in Turkish, fully in Turkish.
The interface language is only a hint — the user's own language always wins.
NEVER repeat your answer; do not output the same text twice.

STYLE — KEEP IT SHORT (this matters):
- Answer in at most ~120 words unless the user explicitly asks for detail.
- Lead with the direct answer in one sentence, then at most 3-5 short bullets.
- Do not restate the question, do not add closing offers like "shall I also...".
- Give the single most relevant citation, not every page you found.
- Never dump a whole document; summarise only what answers the question.
- Formatting: plain sentences and "- " bullets. Use **bold** sparingly (at most a
  few key terms per answer). Do not use markdown headings.
State prices openly with their rationale. Say when something is uncertain.
Today's date: {today}. If no date is given, ask the user."""


def _openai_tools() -> list[dict]:
    """Convert tools.TOOLS (input_schema form) into OpenAI function-calling format."""
    out = []
    for t in tools.TOOLS:
        params = t["input_schema"] or {"type": "object", "properties": {}}
        if "properties" not in params:
            params = {"type": "object", "properties": {}}
        out.append(
            {
                "type": "function",
                "function": {
                    "name": t["name"],
                    "description": t["description"],
                    "parameters": params,
                },
            }
        )
    return out


class Session:
    """A single chat session: conversation history plus guardrail state."""

    # Injected before the user message to steer which data source the agent may use.
    SOURCE_DIRECTIVES = {
        "ours": "(Source preference: use ONLY our own system; do NOT call search_thy_live.)",
        "thy": (
            "(Source preference: for price lookups use ONLY search_thy_live; do not search"
            " our inventory. Purchases are still only possible in our own system.)"
        ),
        "both": "(Source preference: use both sources and compare them where relevant.)",
    }

    def __init__(self) -> None:
        api_key = os.getenv("AZURE_OPENAI_API_KEY") or os.getenv("OPENAI_API_KEY") or "missing"
        self.client = OpenAI(base_url=BASE_URL, api_key=api_key)
        self.messages: list[dict] = []
        self.pending_purchase: dict | None = None
        self.user_confirmed: bool = False

    def send(self, user_text: str, source: str = "both", lang: str = "tr") -> dict:
        """Process one user message; return the reply and the tool calls made."""
        self.user_confirmed = tools.is_affirmative(user_text)
        directive = self.SOURCE_DIRECTIVES.get(source, self.SOURCE_DIRECTIVES["both"])
        directive += (
            " (Interface language: English — if the user writes in English, reply in English.)"
            if lang == "en"
            else " (Interface language: Turkish.)"
        )

        if not self.messages:
            self.messages.append(
                {"role": "system", "content": SYSTEM_PROMPT.format(today=date.today().isoformat())}
            )
        self.messages.append({"role": "user", "content": f"{directive}\n{user_text}"})

        tool_trace: list[dict] = []
        oai_tools = _openai_tools()

        for _ in range(MAX_TOOL_ITERS):
            resp = self.client.chat.completions.create(
                model=MODEL,
                messages=self.messages,
                tools=oai_tools,
                tool_choice="auto",
            )
            msg = resp.choices[0].message
            tool_calls = msg.tool_calls or []

            # Append the assistant turn (including any tool calls) to the history
            self.messages.append(
                {
                    "role": "assistant",
                    "content": msg.content,
                    "tool_calls": [
                        {
                            "id": tc.id,
                            "type": "function",
                            "function": {"name": tc.function.name, "arguments": tc.function.arguments},
                        }
                        for tc in tool_calls
                    ]
                    or None,
                }
            )

            if not tool_calls:
                return {"reply": msg.content or "", "tool_trace": tool_trace}

            for tc in tool_calls:
                try:
                    args = json.loads(tc.function.arguments or "{}")
                except json.JSONDecodeError:
                    args = {}
                result = tools.dispatch(self, tc.function.name, args)
                tool_trace.append({"tool": tc.function.name, "input": args, "result": result})
                self.messages.append(
                    {
                        "role": "tool",
                        "tool_call_id": tc.id,
                        "content": json.dumps(result, ensure_ascii=False, default=str),
                    }
                )

        return {
            "reply": "This request required too many steps. Please try again.",
            "tool_trace": tool_trace,
        }
