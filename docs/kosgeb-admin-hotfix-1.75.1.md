# KOSGEB + Content Admin Hotfix (1.75.1)

## Production finding

The first KOSGEB scan reached the source but returned `custom_source_no_items`; the failure is parsing/URL normalization, not fetch/SSL.

## Adapter hardening

- Treat `www.kosgeb.gov.tr` and `kosgeb.gov.tr` as the same official host.
- Treat root-like `site/...` and `medya/...` hrefs as origin-root paths.
- Add a bounded raw-HTML fallback that only accepts KOSGEB `/site/tr/genel/detay/{id}/...` links on the official allowlist.
- Keep the first-page, low-volume, no-pagination policy.
- Keep Sanayi adapter behavior and TCMB/TOBB RSS scanner untouched.

## Admin UX

Content Engine operational screens stay visually under the `Sektörel Core` parent menu through `parent_file` / `submenu_file` mapping. A proper Core submenu entry for `İçerik Motoru` is registered on content screens. When a single source scan fails, the admin notice now shows the most recent source name, `last_error_code`, and real error message directly.

## Safety

- No AI calls or draft creation.
- No candidate migration/reset.
- No taxonomy enumeration.
- No automatic scheduling.
