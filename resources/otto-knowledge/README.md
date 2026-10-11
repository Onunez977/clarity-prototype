# Otto knowledge base

This directory is Otto's customer-facing knowledge base (DEV-2323). Otto uses it to
answer product, how-to, and license-plate-recognition (LPR) questions in Clarity.

**To change what Otto knows, edit the markdown files here.** No migration, no
re-indexing, no deploy step beyond shipping the files — the knowledge base is read
from disk, so an edit takes effect immediately.

## How it works (so edits do the right thing)

- The whole knowledge base is **never** dumped into Otto's prompt. A compact,
  permission-scoped **index** (titles + one-line summaries) is composed into the
  system prompt, and Otto retrieves full article bodies **on demand** via
  `KnowledgeBaseSearchTool`.
- Articles are **scoped to the user's permissions and features**. A user never even
  sees that an article exists if they can't access the underlying feature.
- When more than one article matches, **app/area-specific** knowledge takes
  precedence over **global** LPR knowledge.

## File format

Each article is a `.md` file with YAML frontmatter, then a markdown body. Files may
live in any subdirectory (e.g. `global/`, `app/`); the directory is organizational
only — scoping is driven by the frontmatter. The filename (without `.md`) is the
article's slug, so keep filenames unique. `README.md` is ignored by the loader.

```markdown
---
title: Searching scans with Otto      # shown in the in-prompt index
scope: app                            # "global" (LPR domain) or "app" (Clarity-specific)
areas: [scans, search]                # free-form tags; improve search ranking
surfaces: [chat]                      # limit to Otto surfaces; omit = everywhere
permissions: [use-otto]               # Spatie permission names the user must hold
features: [SomeFeature]               # App\Features\* class basenames the user/org must have
summary: One-line summary for the index.
keywords: [scan, search, plate]       # synonyms to improve recall
---
# Body

Markdown content Otto reads to answer the question.
```

All frontmatter keys are optional, but a `title` and `summary` are strongly
encouraged. **Access fails closed:** if `permissions`/`features` are listed and a
user doesn't satisfy them, the article is hidden from both the index and search.

### Scope and access guidance

- `scope: global` — LPR-domain knowledge true everywhere; usually no permissions.
- `scope: app` — Clarity feature/area knowledge; gate it with the same
  `permissions`/`features` that gate the feature itself, so docs never leak to users
  who can't use the feature.

## Writing good articles

- Keep them short and focused — one topic per file. Otto answers in its own words
  from what it retrieves, so write the facts plainly.
- Add `keywords` for terms users might use that don't appear in the title.
- Don't put data values (specific plates, names) here — this is product knowledge.
- Use **Clarity** product language only. Do not copy Guardian/Investigate/convoy/
  officer content.

## Customer-safe content (required)

Never mention infrastructure or internals in articles, including:

- ClickHouse, Postgres/PostgreSQL, CDC, Debezium, PeerDB
- Impersonation / admin impersonation
- RDN integration plumbing (API keys, SOAP clients, jobs, or queues). The customer name RDN — Recovery Database Network — belongs in articles.

If a topic needs that depth, it belongs in engineering docs (`okf/`), not here.

## Tuning

See `config/otto.php` (`knowledge` block) for the directory path, how many articles
search returns, the per-article length cap, and the index summary length.
