# Mobile layout

Most people reach this site on a phone, so the narrow layout is the design and
the desktop one is the enhancement. `AGENTS.md` states the rule; this file is the
record of what the current layout actually measures, so a regression is visible
rather than argued about.

Measured in Chrome at a **375 x 812** viewport (iPhone-class, the narrowest we
design for) against the dev stack.

## What was checked

| Page | Horizontal page overflow | Notes |
| --- | --- | --- |
| `/` (Season 1 framework) | none | every grid resolves to a single column |
| `/v1`, `/v0` | none | v0's decorative blur sits inside an `overflow-hidden` card |
| `/season/{slug}` (leaderboard) | none | table scrolls inside its own container, page does not |
| `/season/{slug}/player/{id}` | none | |
| `/season/{slug}/tournament/{id}` | none | |
| `/registrations` | none | |
| `/admin/payments` | none | |
| `/admin/import` | none | two-column field grid collapses to one |
| `/_styleguide` | none | |

**No page scrolls horizontally at 375px.** That is the cardinal rule: a table may
scroll inside `overflow-x-auto`, the document may not.

## Measurements that matter

| Thing | At 375px | Why it is what it is |
| --- | --- | --- |
| Page gutter | `px-3` (12px), `sm:px-4` | The tightest gutter any page used, applied everywhere. Worth 8px of content on every page. |
| Card padding (`size="md"`) | `p-4`, `sm:p-8` | Padding is width taken from the table inside it. It grows with the viewport, it does not start roomy. |
| Leaderboard scroll container | 349px, bleeding to the card edge | `<twig:DataTable bleed>` cancels the card's mobile padding so the widest table gets the full card width. |
| Form field width | 317px | |
| Form field font size | 16px, pinned in `.field` | Below 16px, iOS Safari zooms the page when the control is focused. Do not make `.field` smaller. |
| Form field height | 46-50px | Above the 44px touch-target guideline. |
| Back link height | 44px, `sm:` tighter | `min-h-11` on the component. |
| Table row link height | fills the cell (~50-68px) | `.data-table td > a:only-child` expands to the row's existing height at no layout cost; the leaderboard's name cell wraps both its lines in the anchor. |
| `h1` | `text-4xl` (36px), `md:text-6xl` | Sized for the phone and grown. The two admin pages step down to `text-3xl` and `text-2xl`. |

## Known, deliberate

- **The leaderboard's `Total` column needs a sideways scroll at 375px.** Six
  columns do not fit; `Last active` is already dropped with `hidden
  sm:table-cell`. Dropping or restacking more is a product decision, not a
  layout bug.
- **The hero header pushes the leaderboard table below the fold.** `py-12` plus
  a `mb-12` header fills the first screen on a phone. Worth revisiting if the
  leaderboard is what people come for.
- **The footer's licence links are ~15px tall.** They are attribution in small
  print rather than a primary action, so they are left as they are.
