# Skill: Monthly piano-competition scraping → WordPress drafts

Populate the "Competition Calendar" on qmorake.com with **upcoming (next ~12 months)**
piano competitions, imported as **draft** posts (never published automatically). Each
entry targets five required facts — dates, location, round breakdown, rules link, contact
email — plus research notes (prior editions, previous-edition streams) used to prioritise
competitions with a historical record.

Run cadence: monthly (or as often as desired). The import is idempotent, so re-running is safe.

---

## 1. Data sources

| Source | What it gives | Notes |
|---|---|---|
| `https://www.musicalchairs.info/piano/competitions` | Upcoming competitions: name, location, date range, application deadline, full description, official website | **Protected by Anubis** (proof-of-work); needs a real browser (Playwright). Contact email is behind login, so it is **not** on this site. |
| `https://en.wikipedia.org/wiki/Category:Piano_competitions` | ~63 *established* competitions with historical record | Good for "prior editions / historical record" cross-reference. Overlap with the musicalchairs 12-month window is small (major competitions are biennial/quadrennial). |
| Each competition's **official website** | Round breakdown, rules link, contact email, streams | The actual source for the required fields. Varies heavily per site — this is the LLM-assisted step. |

## 2. Prerequisites

- A Playwright-capable Chromium + `playwright-core` (the browser solves the Anubis challenge automatically).
- `kubectl` access to the `wordpress` namespace with the `wordpress` pod (KUBECONFIG=~/.kube/eqinilet/main.config).
- The `competition-calendar` plugin deployed on qmorake.com (see `../assets/competition-calendar/`).

## 3. Pipeline

```
mc_list.js          -> /tmp/mc-entries.json        (76 listings: name/location/dates/closing/desc/url)
  (parse.py: date parse + 12-month filter)          -> /tmp/mc-window.json
mc_details.js       -> /tmp/mc-details.json         (+ full description, ISO dates, official URL via /goto-url)
  (build import JSON: clean location, map fields)    -> /tmp/competitions-import.json
import-competitions.php (run inside the pod)        -> draft posts (competition post type)
  enrichment (LLM, per competition)                  -> fill rounds/rules/contact/history/streams
```

The scripts in this directory (`mc_list.js`, `mc_details.js`, `import-competitions.php`)
were used successfully to import the first 70 drafts. `mc_list.js` and `mc_details.js`
hardcode the Playwright/chromium paths — edit them to match the host.

### 3a. Scrape the listing

`mc_list.js` launches Chromium, loads the listing (the browser solves Anubis after a ~9s
wait on the first navigation), and extracts every `<li class="preview" data-post-preview-id>`:

- `.post_item_name` → name
- `.post_item_location` → location
- `.post_item_info` → date range (e.g. `12 - 14 Mar, 2027`, `22 Nov 2026 - 16 Jul 2027`)
- `.post_item_closingdate` → `Closing date: 01 Feb 2027`
- `.post_item_desc` → description snippet
- the `<a href="/competitions/XXXX">` → detail URL

### 3b. Scrape the details

`mc_details.js` loads each detail page and pulls the **JSON-LD `MusicEvent`** block
(`name`, `description`, ISO `startDate`/`endDate`, `location.name`), then follows the
`/goto-url?section=cp&id=XXXX` redirect (via `context.request.get`) to resolve the
**official website URL**. Use the JSON-LD dates as authoritative; fall back to parsing
`.post_item_info`.

### 3c. Parse dates + filter to the 12-month window

The `.post_item_info` formats (parse with the `MONTHS` map, treat `Sep` and `Sept` alike):

- `03 Jul 2027` — single date
- `12 - 14 Mar, 2027` — day range, same month/year
- `02 Sep - 20 Dec, 2026` — day+month to day+month, same year
- `22 Nov 2026 - 16 Jul 2027` — full range

Filter: keep when `end_date >= today` AND `start_date <= today + 365d`. This drops
already-finished competitions and the far-future ones (e.g. 2028/2029).

### 3d. Import as drafts

`import-competitions.php` runs **inside the pod** (`kubectl exec -i deploy/wordpress -- php < import-competitions.php`)
and calls `ccomp_upsert_competition($record, 'draft')` for each record. It reads
`/tmp/competitions-import.json` (copy the JSON into the pod first with `kubectl cp`).

Dedupe key: `_competition_source_url` (the musicalchairs detail URL), then the post slug.

## 4. Data model (competition post type)

| Meta key | Field |
|---|---|
| `_competition_start`, `_competition_end` | dates (Y-m-d) |
| `_competition_deadline` | application deadline (Y-m-d) |
| `_competition_location` | location |
| `_competition_url` | official website |
| `_competition_rules_url` | **rules/regulations link** |
| `_competition_rounds` | **breakdown of rounds** |
| `_competition_contact_name`, `_competition_contact_email` | **contact person** |
| `_competition_description` | source description text |
| `_competition_prior_editions` | historical record / prior editions |
| `_competition_streams` | previous-edition stream links |
| `_competition_source`, `_competition_source_url` | provenance |

`ccomp_upsert_competition()` (in the plugin) also writes a human-readable summary into
`post_content` so drafts are easy to review in wp-admin.

## 5. Enrichment (the per-competition step)

musicalchairs gives dates/location/description/official-URL but **not** rounds, rules or
contact email. Fill those per competition:

1. Visit the `official_url` → find the **rules/regulations** page (often "Rules",
   "Regulations", "Conditions", a PDF) and the **contact email**.
2. Extract the **round breakdown** (e.g. "video preliminary → semifinal → final with orchestra").
3. For **historical record**: the edition number in the title is a strong heuristic
   ("23rd …", "9th …", "Second Edition"). Cross-reference Wikipedia for the established ones.
4. For **streams**: look for a YouTube/Vimeo channel with past finals.
5. Update the draft's meta (re-run the import with the enriched JSON, or edit in wp-admin).

**Parallelise with subagents.** The enrichment fans out well: split the queue (each entry is
`{"t": title, "u": official_url}`) into ~13-item slices, and give each subagent a slice with a
fixed output contract (a JSON array of `{title, rounds, rules_url, contact_name, contact_email,
prior_editions, streams}` written to a per-slice file). Merge the result files back onto the
full import records (match by normalised title) and re-run `import-competitions.php`. Budget
~3 `web_fetch` calls per competition.

**Data-availability reality** (observed on ~65 competitions): a large share of these sites are
Wix/Squarespace/JS-only or Cloudflare-protected, so plain `web_fetch` returns an empty body.
Workarounds that recovered data: reading HTML `<meta>`/embedded JSON, and third-party listings
(WFIMC, aggregators, the musicalchairs description). Some competitions simply publish no public
email. Expect ~2/3 email coverage and ~3/4 rounds coverage on a typical pass, and leave the rest
as empty strings rather than guessing.

## 6. Pitfalls

- **Anubis**: musicalchairs returns a challenge page to curl/plain HTTP. Always use the
  browser; the first navigation needs ~9s for the proof-of-work + redirect.
- **Database load**: importing ~74 posts in one process can overwhelm the small MariaDB
  (128 MB buffer pool) and trip its 5s healthcheck → pod restarts → "Error establishing a
  database connection". Throttle (`usleep` between records), import in batches, and re-run
  (idempotent) after the pod stabilises.
- **Contact email**: not public on musicalchairs ("log in to send a message") — get it from
  the official site.
- **Duplicate listings**: musicalchairs sometimes lists the same competition twice (and
  "Golden Classical Music Awards: Carnegie Hall" appears with multiple date ranges). Slug
  dedupe merges exact-name duplicates; review the drafts for anything that should be split.
- **Location quirks**: some location fields start with a URL — strip the `https://…/` prefix.
- **Only publish what you mean to**: drafts do not appear on the calendar or in the ICS feed
  until published.

## 7. Where things live

- Plugin (data model + calendar + ICS): `../assets/competition-calendar/`
- Scrapers + this skill: `scrapers/`
- One-off/debug artifacts: `tmp/` (gitignored)
