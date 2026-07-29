"""RAG bilgi tabani olusturucu — SADECE resmi kaynak PDF'lerinden.

Onceki surumden fark: bu script metni ELLE YAZMAZ, data/sources/ altindaki
gercek PDF dosyalarini pypdf ile okur ve parcalar (chunk). Her chunk'ta
kaynak dosya + sayfa numarasi tutulur; boylece RAG'in her cevabi
"su dokumanin su sayfasindan geliyor" diye izlenebilir.

Kaynaklar (data/sources/, ayrica SOURCES.md'de belgelenmistir):
  01-thy-branded-fares-2022.pdf   — THY'nin resmi acente duyurusu (branded fares)
  02-shy-yolcu-yonetmeligi.pdf    — SHGM resmi yonetmeligi (yolcu haklari)

Kullanim:
  python data/build_kb.py
Cikti:
  data/thy_knowledge_base.json  (RAG indexleme icin, app/agent/rag.py okur)
"""

from __future__ import annotations

import json
import re
from pathlib import Path

import pypdf

HERE = Path(__file__).resolve().parent
SOURCES_DIR = HERE / "sources"
OUT_PATH = HERE / "thy_knowledge_base.json"

# Her kaynak PDF icin: (dosya adi, goruntu adi, orijinal URL, konu etiketleri)
SOURCES = [
    {
        "file": "01-thy-branded-fares-2022.pdf",
        "name": "Turkish Airlines & AnadoluJet Branded Fares — Resmi Acente Duyurusu",
        "url": "https://www.aviateworld.com/media/4310/turkish-airlines-announcement-_-branded-fares.pdf",
        "category": "fare_packages",
        "date": "2022-05-11",
    },
    {
        "file": "02-shy-yolcu-yonetmeligi.pdf",
        "name": "Havayolu ile Seyahat Eden Yolcuların Haklarına Dair Yönetmelik (SHY-YOLCU)",
        "url": "https://web.shgm.gov.tr/doc4/shy-yolcu.pdf",
        "category": "passenger_rights",
        "date": None,  # yonetmelik guncel surumu; PDF'de revizyon tarihi yok
    },
]

MIN_CHUNK_CHARS = 200  # bu kadar kisa parcalar (bos sayfa vb.) atlanir


def _clean(text: str) -> str:
    text = re.sub(r"[ \t]+", " ", text)
    text = re.sub(r"\n{3,}", "\n\n", text)
    return text.strip()


def extract_source(src: dict) -> list[dict]:
    path = SOURCES_DIR / src["file"]
    if not path.exists():
        raise FileNotFoundError(f"Kaynak PDF bulunamadi: {path}")

    reader = pypdf.PdfReader(str(path))
    chunks = []
    for page_no, page in enumerate(reader.pages, start=1):
        text = _clean(page.extract_text() or "")
        if len(text) < MIN_CHUNK_CHARS:
            continue
        chunks.append(
            {
                "id": f"{src['file']}#p{page_no}",
                "category": src["category"],
                "title": f"{src['name']} — Sayfa {page_no}",
                "source_file": src["file"],
                "source_url": src["url"],
                "source_date": src["date"],
                "page": page_no,
                "content": text,
            }
        )
    return chunks


def build() -> dict:
    all_chunks: list[dict] = []
    for src in SOURCES:
        all_chunks.extend(extract_source(src))

    kb = {
        "metadata": {
            "title": "THY / SHGM Resmi Kaynak Bilgi Tabani",
            "description": (
                "Bu bilgi tabani, asagida listelenen resmi PDF dokumanlarindan "
                "pypdf ile DOGRUDAN metin cikarilarak olusturulmustur. "
                "Hicbir icerik elle yazilmamis veya LLM tarafindan uretilmemistir. "
                "turkishairlines.com bot korumasi nedeniyle otomatik taranamadigi "
                "icin (bkz. data/SOURCES.md), yerine bu resmi PDF dokumanlar kullanilmistir."
            ),
            "source_type": "official_pdf_extraction",
            "extraction_method": "pypdf",
            "built_at": "2026-07-25",
            "total_chunks": len(all_chunks),
            "sources": SOURCES,
        },
        "urls": [
            {"name": s["name"], "url": s["url"], "category": s["category"]} for s in SOURCES
        ],
        "chunks": all_chunks,
    }
    return kb


if __name__ == "__main__":
    kb = build()
    OUT_PATH.write_text(json.dumps(kb, ensure_ascii=False, indent=2), encoding="utf-8")
    print(f"OK: {len(kb['chunks'])} chunk yazildi -> {OUT_PATH}")
    for c in kb["chunks"]:
        print(f"  [{c['source_file']} s.{c['page']}] {len(c['content'])} kar — {c['title']}")
