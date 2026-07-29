"""RAG layer: semantic search over the official THY/SHGM rule documents (ChromaDB).

Where this fits in the architecture:
  Live data (prices, seats) comes from tools — search_flights, THY MCP.
  Policy text, however, lives in static documents, so it is served via RAG.
  This lets the agent answer "what is the baggage allowance?" or "what are the
  24-hour refund conditions?" without inventing anything, and cite its source.

  LIVE DATA  -> tool calling  (price, seats, PNR)
  RULE TEXT  -> RAG           (baggage, refunds, passenger rights)

Storage: persistent ChromaDB client under data/chroma/.
Embeddings: ChromaDB's bundled local model — no extra API calls or cost.
"""

from __future__ import annotations

import json
from pathlib import Path

BASE = Path(__file__).resolve().parent.parent.parent
KB_PATH = BASE / "data" / "thy_knowledge_base.json"
CHROMA_DIR = BASE / "data" / "chroma"
COLLECTION = "thy_rules"

_client = None
_collection = None


def _get_collection():
    """Return the ChromaDB collection, creating it on first use."""
    global _client, _collection
    if _collection is not None:
        return _collection

    import chromadb

    CHROMA_DIR.mkdir(parents=True, exist_ok=True)
    _client = chromadb.PersistentClient(path=str(CHROMA_DIR))
    _collection = _client.get_or_create_collection(
        name=COLLECTION, metadata={"hnsw:space": "cosine"}
    )
    return _collection


def load_kb() -> dict:
    return json.loads(KB_PATH.read_text(encoding="utf-8"))


def build_index(force: bool = False) -> dict:
    """Load the knowledge base into ChromaDB. Skips work if already indexed."""
    col = _get_collection()
    if col.count() > 0 and not force:
        return {"status": "already_indexed", "documents": col.count()}

    if force and col.count() > 0:
        _client.delete_collection(COLLECTION)
        globals()["_collection"] = None
        col = _get_collection()

    kb = load_kb()
    chunks = kb.get("chunks", [])
    if not chunks:
        return {"status": "empty_kb", "documents": 0}

    ids, docs, metas = [], [], []
    for i, c in enumerate(chunks):
        text = (c.get("content") or c.get("text") or "").strip()
        if not text:
            continue
        title = c.get("title", "")
        # Prepend the title to the document text to improve retrieval accuracy
        ids.append(str(c.get("id", f"chunk-{i}")))
        docs.append(f"{title}\n\n{text}" if title else text)
        metas.append(
            {
                "title": title,
                "category": c.get("category", ""),
                "source_url": c.get("source_url", ""),
                "source_file": c.get("source_file", ""),
                "page": c.get("page", 0),
            }
        )

    col.add(ids=ids, documents=docs, metadatas=metas)
    return {"status": "indexed", "documents": col.count()}


def search(query: str, k: int = 3) -> dict:
    """Semantic search over the knowledge base; results include their source."""
    try:
        col = _get_collection()
        if col.count() == 0:
            build_index()
            col = _get_collection()

        res = col.query(query_texts=[query], n_results=min(k, max(col.count(), 1)))
        hits = []
        for doc, meta, dist in zip(
            res["documents"][0], res["metadatas"][0], res["distances"][0]
        ):
            hits.append(
                {
                    "title": meta.get("title", ""),
                    "category": meta.get("category", ""),
                    "source_url": meta.get("source_url", ""),
                    "source_file": meta.get("source_file", ""),
                    "page": meta.get("page", 0),
                    "content": doc,
                    "score": round(1 - float(dist), 3),  # cosine similarity
                }
            )
        return {"query": query, "hits": hits, "count": len(hits)}
    except Exception as exc:  # noqa: BLE001 - surface the error to the agent instead of raising
        return {"query": query, "hits": [], "count": 0, "error": str(exc)[:200]}


def stats() -> dict:
    """RAG status for the case-study dashboard."""
    try:
        col = _get_collection()
        kb = load_kb()
        return {
            "indexed": col.count(),
            "source_type": kb.get("metadata", {}).get("source_type", "-"),
            "sources": len(kb.get("urls", [])),
            "store": "ChromaDB",
        }
    except Exception as exc:  # noqa: BLE001
        return {"indexed": 0, "error": str(exc)[:150], "store": "ChromaDB"}
