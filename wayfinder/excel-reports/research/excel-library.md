# Research: เลือก library สร้าง Excel (.xlsx) บน Laravel 13 / PHP 8.3

- **Ticket:** [`tickets/01-excel-library-choice.md`](../tickets/01-excel-library-choice.md)
- **Date:** 2026-09-22
- **Status:** RESOLVED — แนะนำ **phpoffice/phpspreadsheet 5.x** ใช้ตรง ๆ ไม่ห่อ wrapper (รอ owner sign-off ใน ticket 03)
- **Scope checked (read-only):** composer.json + เว็บ (packagist/GitHub/docs)

---

## 1. Repo facts (verified from the repo)

| Constraint | Value |
|---|---|
| PHP | `^8.3` |
| laravel/framework | `^13.0` |
| laravel/sanctum | `^4.0` |
| laravel/tinker | `^3.0` |
| require-dev | fakerphp/faker `^1.23`, laravel/pail `^1.2.5`, laravel/pint `^1.27`, mockery/mockery `^1.6`, nunomaduro/collision `^8.6`, phpunit/phpunit `^12.5.12` |
| Excel/PDF libs | **None present** |
| stability | `minimum-stability: stable`, `prefer-stable: true` |

Environment notes: API-only REST (no Blade), PostgreSQL prod / SQLite in-memory tests, Windows dev machine — meaning any library's PHP extension requirements (e.g., `gd`, `zip`) must be enabled in the local `php.ini`.

## 2. Candidate comparison table (versions as of 2026-09)

| | phpoffice/phpspreadsheet | openspout/openspout | maatwebsite/excel | shuchkin/simplexlsxgen |
|---|---|---|---|---|
| **Latest version** | 5.10.0 (2026-09-17) | v5.11.3 (2026-09-02) — **requires PHP `~8.4.0 \|\| ~8.5.0`**; on PHP 8.3 composer resolves to **v4.32.0 (2025-09-03, php `~8.3.0 \|\| ~8.4.0 \|\| ~8.5.0`)** | 4.0.3 (2026-09-14) | 1.5.17 (2026-05-03) |
| **Merged cells** | Yes — `mergeCells()`/`unmergeCells()` with content-merge flags (verified in v5 docs) | Yes for XLSX only, via `$options->mergeCells(...)` (docs + closed issues #75/#98; ODS merge fix #384 merged May 2026); known limitation: borders on merged cells issue closed "not planned" | Yes (inherits PhpSpreadsheet) | Yes — `mergeCells('A20:B20')` |
| **Number formats** | Yes — `setFormatCode('#,##0.00')`, built-in constants | Yes — `Style::withFormat()` (XLSX only) | Yes (inherits) | Yes (`nf` attribute, currency/percent/date) |
| **Freeze panes / print titles** | Both — `freezePane($coordinate)` verified in source; `getPageSetup()->setRowsToRepeatAtTopByStartAndEnd(1,5)` in docs | **Freeze panes: not documented. Print titles/page setup: not documented** (absence unverified as officially unsupported, but no API found in docs) | Both (inherits) | `freezePanes('B2')` yes; **page setup (A4/orientation/margins): not documented** |
| **Page setup A4/landscape** | Yes — `setPaperSize(PAPERSIZE_A4)`, `setOrientation(ORIENTATION_LANDSCAPE)` | Not found in docs | Yes (inherits) | Not documented |
| **Perf/memory profile** | In-memory DOM-style model; docs state ~1k per cell (1.6k on 64-bit PHP); optional PSR-16 cell cache | Streaming, README claims <3 MB memory — best for very large files | Inherited from PhpSpreadsheet + query chunking/queued exports | Lightweight, string-driven |
| **PHP 8.3 / Laravel 13** | PHP `^8.2` — yes / framework-agnostic | PHP 8.3 only via v4 line (v4 last release 2025-09; v5 development requires 8.4+) / framework-agnostic | PHP `^8.3`, Laravel `^12.0 \|\| ^13.0` — **both officially supported** (Laravel 13 since 3.1.68, Mar 2026; 4.0.0 released 2026-08-13) | PHP `>=5.4` / framework-agnostic |
| **Maintenance status** | Very active (release Sep 2026; 345M installs, ~14k stars, 98 open issues). Requires ext: gd, zip, mbstring, dom, xml*, etc. | Active (release Sep 2026, 2 open issues, 8 open PRs) — but active line is PHP 8.4+ only | Active (release Sep 2026); note 3.1.70 patched CVE-2026-34084 — stay on latest | Solo maintainer, last release May 2026, 2.67M installs |
| **Test-friendliness** | Excellent — `IOFactory::load()` reads the generated xlsx back; can assert values, merges, formats, freeze panes | Good — its own fast streaming reader reads files back | Good — `Excel::fake()` (referenced in 4.0.2 release notes); cell-level assertions still done by loading with PhpSpreadsheet. Historical testing docs page 404'd during fetch (partially unverified) | Weaker — no reader included (sibling `shuchkin/simplexlsx` needed) |
| **Extras for free** | Formulas, charts, borders, autofilter, headers/footers, read/write xls/ods/csv | Streaming reads too | Imports, queued/chunked exports, S3 temp disk, `WithExportTemplate` (new in 4.0) | Formulas, hyperlinks, comments, RTL |

## 3. Trade-off analysis for this use case

**Requirement coverage is the deciding axis.** The 15 templates need merged section-header rows, bold Thai UTF-8 headers, `#,##0` number formats, freeze panes *and* print-title rows, and A4 landscape page setup.

- **PhpSpreadsheet** covers 100% of these natively with first-class API methods, all verified above in current v5 docs/source. It is also the only candidate where print titles + page setup are documented.
- **OpenSpout** is architecturally a streaming writer: it gained XLSX merge support, but its documented feature set does not include freeze panes, print titles, or page setup — exactly the print-related requirements here. Worse, this project's `php ^8.3` constraint pins it to the **v4 line whose last release was Sep 2025**, while all current development (v5.x) requires PHP 8.4+. It is the right tool for 100k+-row exports on modern PHP — not this project.
- **maatwebsite/excel 4.x** now officially supports Laravel 13 (`illuminate/support ^12.0 || ^13.0`, PHP `^8.3`) and sits on PhpSpreadsheet `^5.9`, so it is *not* lagging anymore. However, its added value is mainly imports, queued/chunked `FromQuery` exports, and Laravel-facade ergonomics — none of which are required (no imports; row counts are "moderate"). It adds a dependency layer and its own config/temp-file machinery between the code and the styling API. The `Excel::fake()` helper is convenient but cell assertions go through PhpSpreadsheet anyway.
- **Memory math for PhpSpreadsheet** (the usual objection): at ~1.6 KB/cell on 64-bit PHP, a worst-case monthly report of 5,000 rows × 30 columns ≈ 150k cells ≈ ~240 MB. That is manageable per export request (or in a queued job with a raised `memory_limit`), and the docs offer a PSR-16 cell cache as an escape hatch. Only if annual exports reach 50k–100k+ rows does this become a real concern.

## 4. Recommendation

**Recommendation: use `phpoffice/phpspreadsheet` (5.x) directly — no wrapper.**

Reasons:
1. It is the only candidate whose documented API covers *every* template requirement: `mergeCells()`, `NumberFormat::setFormatCode('#,##0')`, `freezePane()`, `PageSetup::setRowsToRepeatAtTopByStartAndEnd()`, `PAPERSIZE_A4` + `ORIENTATION_LANDSCAPE`, plus UTF-8/Thai-safe bold styling.
2. Maximum freshness and maintenance headroom: 5.10.0 released 2026-09-17, PHP `^8.2` (so it also survives a future PHP 8.4 upgrade, unlike OpenSpout v4).
3. Best test-friendliness for the stated PHPUnit goal: `IOFactory::load($path)` reads the generated file back so feature tests can assert header text (Thai included), merged ranges, format codes, and freeze panes.
4. Framework-agnostic — zero coupling to Laravel release cadence.
5. Setup note: it requires `gd`, `zip`, `mbstring`, `dom`, `xmlwriter`, etc. — on the Windows dev box, enable `extension=gd`/`extension=zip` in `php.ini`; on prod ensure the PHP image includes them.

**Runner-up: `maatwebsite/excel` 4.x.** Choose it instead if the team wants queued/chunked exports out of the box (S3 temp disk, chained jobs, `FromQuery` chunking) or the new `WithExportTemplate` concern for template-style exports. Since it delegates to PhpSpreadsheet `^5.9` anyway, migrating to it later is cheap — starting bare keeps the dependency surface minimal.

**What would change the decision:**
- Exports routinely exceed ~50k–100k rows or hit memory/time limits → adopt OpenSpout-style streaming; but that requires first upgrading the runtime to **PHP 8.4+** to get OpenSpout v5 (on PHP 8.3 you are frozen on v4, Sep 2025) *and* accepting the loss of print titles/page setup (would need a post-processing pass or dropping those requirements).
- Multiple reports want view-based templates and background queueing with storage uploads → maatwebsite/excel 4.x becomes worth its weight.
- `shuchkin/simplexlsxgen` is a fine micro-library for quick unattended scripts but lacks documented print setup and a reader, so it fails the template and testing requirements.

**Unverified items (flagged):** OpenSpout's *official* stance on freeze panes/print titles (absent from docs, but no explicit "unsupported" statement found); the maatwebsite testing-docs page returned 404 during fetch — `Excel::fake()`'s existence is corroborated only by the 4.0.2 release notes; OpenSpout v5.0.0's exact PHP constraint was not in the p2 metadata (v5.11.3's `~8.4.0 || ~8.5.0` was verified).

## 5. Sources

- [Packagist: phpoffice/phpspreadsheet](https://packagist.org/packages/phpoffice/phpspreadsheet)
- [PHPOffice/PhpSpreadsheet (GitHub)](https://github.com/PHPOffice/PhpSpreadsheet)
- [PhpSpreadsheet docs: Recipes (mergeCells, print repeat rows, paper size/orientation, number formats)](https://phpspreadsheet.readthedocs.io/en/latest/topics/recipes/)
- [PhpSpreadsheet source: Worksheet.php (freezePane)](https://github.com/PHPOffice/PhpSpreadsheet/blob/master/src/PhpSpreadsheet/Worksheet/Worksheet.php)
- [PhpSpreadsheet docs: Memory saving (~1k/cell, PSR-16 cache)](https://phpspreadsheet.readthedocs.io/en/latest/topics/memory_saving/)
- [Packagist: openspout/openspout](https://packagist.org/packages/openspout/openspout)
- [OpenSpout (GitHub)](https://github.com/openspout/openspout)
- [OpenSpout 5.x documentation (StyleBuilder, mergeCells on Options, format)](https://github.com/openspout/openspout/blob/5.x/docs/documentation.md)
- [OpenSpout issues: merged cells (#75, #98, #267, #384)](https://github.com/openspout/openspout/issues?q=merge%20cells)
- [OpenSpout v4.32.0 composer.json (PHP ~8.3 constraint)](https://raw.githubusercontent.com/openspout/openspout/v4.32.0/composer.json)
- [Packagist: maatwebsite/excel](https://packagist.org/packages/maatwebsite/excel)
- [SpartnerNL/Laravel-Excel (GitHub, version table: 4.0 supports Laravel 12–13, PHP ^8.3)](https://github.com/SpartnerNL/Laravel-Excel)
- [Laravel-Excel releases (3.1.68 Laravel 13 support; 4.0.0 notes; CVE-2026-34084 in 3.1.70)](https://github.com/SpartnerNL/Laravel-Excel/releases)
- [Laravel Excel docs: Queued exports](https://docs.laravel-excel.com/3.1/exports/queued.html)
- [Packagist: shuchkin/simplexlsxgen](https://packagist.org/packages/shuchkin/simplexlsxgen)
