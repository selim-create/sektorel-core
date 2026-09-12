# KOSGEB DOM charset finding — 2026-09-12

Production diagnostics against `https://www.kosgeb.gov.tr/site/tr/genel/liste/4/haber?Page=1` showed:

- HTTP `Content-Type`: `text/html; charset=utf-8`
- body is valid UTF-8
- `mb_detect_encoding` reports UTF-8
- raw body contains correct Turkish text such as `KOBİ`, `Gücü`, `Eylül`
- raw body does not contain corresponding mojibake sequences
- source HTML includes conflicting legacy charset declarations alongside UTF-8
- plain `DOMDocument::loadHTML()` reproduces mojibake

Conclusion: the HTTP/body encoding is correct; corruption is introduced by libxml HTML charset interpretation.

Adapter response:

1. preserve valid UTF-8 body bytes
2. remove HTML meta charset declarations before DOM parsing because metadata is not needed for extraction
3. remove any XML encoding declaration
4. prepend one explicit `<?xml encoding="UTF-8"?>` declaration
5. construct DOMDocument as UTF-8
6. retain the mojibake fail-closed guard so corrupted text can never be persisted

No candidate migration, AI call, triage, draft creation, publishing, taxonomy enumeration or schedule change is part of this fix.
