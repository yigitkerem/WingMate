# RAG Bilgi Tabanı — Kaynakça

Bu dizindeki `thy_knowledge_base.json`, aşağıda listelenen **resmi PDF dokümanlarından
`pypdf` ile doğrudan metin çıkarılarak** oluşturulmuştur (`build_kb.py`). Hiçbir
içerik elle yazılmamış veya bir dil modeli tarafından üretilmemiştir — her chunk,
kaynak dosya + sayfa numarasıyla izlenebilir.

## Neden web scraping değil, PDF?

`turkishairlines.com`'un canlı sayfalarını doğrudan taramayı denedik
(`urllib`, `WebFetch`) — sayfa her seferinde zaman aşımına uğradı (bot/WAF koruması).
Bunun yerine, aynı bilgiyi içeren **resmi, indirilebilir PDF dokümanlarını** kaynak
olarak kullandık. Bu dokümanlar THY'nin kendisi veya T.C. Sivil Havacılık Genel
Müdürlüğü (SHGM) tarafından yayımlanmıştır.

## Kaynak listesi

| # | Dosya | Başlık | Yayımlayan | Tarih | Orijinal URL |
|---|---|---|---|---|---|
| 1 | `sources/01-thy-branded-fares-2022.pdf` | Turkish Airlines & AnadoluJet Branded Fares — Resmi Acente Duyurusu | Turkish Airlines | 2022-05-11 | [aviateworld.com](https://www.aviateworld.com/media/4310/turkish-airlines-announcement-_-branded-fares.pdf) |
| 2 | `sources/02-shy-yolcu-yonetmeligi.pdf` | Havayolu ile Seyahat Eden Yolcuların Haklarına Dair Yönetmelik (SHY-YOLCU) | T.C. Sivil Havacılık Genel Müdürlüğü (SHGM) | güncel yürürlükteki sürüm | [web.shgm.gov.tr](https://web.shgm.gov.tr/doc4/shy-yolcu.pdf) |

## Kaynak 1 içeriği — Branded Fares (EcoFly / ExtraFly / PrimeFly / Business)

THY'nin 2022'de acentelere gönderdiği resmi duyuru. İçerik:
- Ekonomi sınıfı paketleri: **EcoFly, ExtraFly, PrimeFly** — bagaj, koltuk seçimi,
  mil kazanımı, değişiklik/iade hakları karşılaştırması
- Business sınıfı: **BusinessFly, BusinessPrime**
- AnadoluJet paketleri: **Standard, ExtraJet, KonforJet**
- Branded fares'in uygulandığı ülke/rota listesi
- Tam özellik karşılaştırma tablosu (sayfa 5-6)

⚠️ **Not:** Bu doküman 2022 tarihli. Case çalışmasındaki fiyat/kural detayları
(2026 sezonu) ile küçük farklar olabilir — paket **yapısı** (4 kademe, hangi
özellik hangi pakette) değişmemiştir ama **kesin ücret tutarları** güncel
değildir. Kesin fiyat için her zaman canlı sistem (THY MCP / bizim pricer)
kullanılır; bu doküman yalnızca **kural/yapı** açıklaması içindir.

## Kaynak 2 içeriği — SHY-YOLCU Yönetmeliği

T.C. Sivil Havacılık Genel Müdürlüğü'nün resmi yönetmeliği (4 sayfa, ~22.500
karakter — bilgi tabanının en büyük ve en yetkili parçası). İçerik:
- Uçuşa kabul edilmeme (denied boarding) durumunda yolcu hakları
- Uçuş iptali ve erteleme durumlarında bilgilendirme yükümlülüğü
- Tazminat hakkı ve istisnalar (olağanüstü haller)
- Gecikme sürelerine göre asgari haklar (ikram, iletişim, konaklama)
- Check-in kapanış süreleri

Bu doküman, case çalışmasının **Bölüm B — Sefer İptali** kısmının hukuki
dayanağıdır (Yılmaz ailesinin TK198 iptali senaryosu).

## Kapsam dışı bırakılanlar (bilinçli sınırlama)

Önceki (silinen) bilgi tabanında bulunan şu konular, **doğrulanabilir resmi
kaynak bulunamadığı için** bu sürüme dahil edilmemiştir:
- Evcil hayvanlarla seyahat kuralları
- Hamile yolcu / özel seyahat ihtiyaçları
- Miles&Smiles sadakat programı detayları
- Kabin bagajı ölçü/ağırlık tam tablosu

Bu konular gerekirse ayrı resmi kaynaklarla (THY T&C sayfaları, ilgili PDF'ler)
ayrı bir güncellemede eklenebilir. Boş bırakmak, uydurmaktan iyidir.

## Yeniden oluşturma

```bash
python data/build_kb.py
```

Yeni bir kaynak eklemek için: PDF'i `data/sources/` altına koyun, `build_kb.py`
içindeki `SOURCES` listesine dosya adı + orijinal URL + kategori bilgisiyle
ekleyin, script'i tekrar çalıştırın.
