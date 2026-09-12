# Content Source Audit — KOSGEB + Sanayi ve Teknoloji Bakanlığı

Date: 2026-09-12

## KOSGEB — Haberler

- Source key: `kosgeb_news`
- Authority: Tier A / official
- Primary desk: `kobi-girisimcilik`
- Public listing: `https://www.kosgeb.gov.tr/site/tr/genel/liste/4/haberler?Page=1`
- Detail pattern: `/site/tr/genel/detay/{numeric_id}/{slug}`
- Listing evidence: title, explicit Turkish date, summary, stable detail URL
- Detail evidence: full article body, explicit publication/update date, source image
- Strategy: source-specific HTML adapter, first listing page only, max 20 items by default
- Detail strategy: `detail_page_required`
- Image policy: `source_preferred`
- Pagination: intentionally not crawled in the initial adapter

## T.C. Sanayi ve Teknoloji Bakanlığı — Haberler

- Source key: `sanayi_news`
- Authority: Tier A / official
- Primary desk: `sanayi-uretim`
- Public listing: `https://www.sanayi.gov.tr/medya/haberler`
- Observed detail patterns: `/medya/haber/{slug}`, `/medya/haber-detayi/{id}`, `/medya/haberleri/{slug}`
- Listing evidence: title and explicit `dd.mm.yyyy` date
- Detail evidence: full article body, explicit date, source image
- Strategy: source-specific HTML adapter, news listing only, first page only, max 20 items by default
- Detail strategy: `detail_page_required`
- Image policy: `source_preferred`
- Duyurular are intentionally excluded because the feed mixes tenders, personnel, OSB notices and other non-news material.

## Safety / scope

- No generic HTML heuristic was added to the existing RSS scanner.
- Existing TCMB and TOBB RSS behavior remains unchanged.
- No pagination crawler.
- No AI call or draft creation.
- No automatic publishing.
- No location taxonomy enumeration.
- KAP is deliberately excluded from this batch; its official high-volume data distribution REST service is a separate licensed/authorized integration concern.
