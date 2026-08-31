---
name: design-system
description: How this site's UI is built — design tokens, the single page shell, Twig components and their traps, the one-script rule, and mobile-first sizing. Use when editing anything under templates/, assets/styles/app.css, assets/app.js or src/Twig/Components/, when adding or renaming a component, when a Twig component behaves oddly, or when checking a page at 375px.
---

# The design system

There is no JavaScript framework here, and a design system does not need one.
It is three things:

1. **Tokens**, in `assets/styles/app.css`. A Tailwind v4 `@theme` block names every
   colour, radius and glow after the job it does — `bg-surface`, `text-ink-muted`,
   `rounded-card`, `shadow-brand-glow` — aliasing Tailwind's own scale underneath,
   so the current look is exact and a repaint is a change in one file. Templates
   use the token names; `slate-800` should not reappear in one.
2. **`templates/base.html.twig`**, the one page shell. Every route extends it and
   overrides `title`, `column`, `accent_bar`, `body_classes` or `html_classes` as
   needed. No template declares `<!DOCTYPE>` any more.
3. **Components**, in `templates/components/`, used as `<twig:Badge tone="flame">`
   through `symfony/ux-twig-component`. `make console ARGS="debug:twig-component"`
   lists them.
4. **One script**, `assets/app.js`, loaded on every page by `importmap('app')`
   in `base.html.twig`. See below — it is small on purpose and it is the only
   one.

### The site ships exactly one script, and it may not be load-bearing

There used to be none, and the rule that replaced "none" is narrower than it
looks: **anything the script does must already work without it.**

`ExpandableTable` is the whole of it. A table renders every row it has and the
"Show more" control is `hidden` in the markup; the script counts the rows,
hides the ones past `initialRows` and reveals the control. With JavaScript off,
or before the module runs, the reader gets the full table and no button — which
is more than the enhancement leaves them, never less.

That is the test to apply to the next one. A control that is the *only* way to
reach something, a value computed in the browser that the server also computes,
or a form that will not submit without it, all fail it — and the two rules that
turn on this being true are still in force and are recorded in the
`challonge-import` skill: the import screen's radio-and-dropdown pair, and its
`Update` submit re-deriving the preview on the server. Neither may be replaced
with a script.

`assets/app.js` does not import the stylesheet. `base.html.twig` links
`styles/app.css` directly, and importing it from the entrypoint as well makes
`importmap()` emit a second `<link>` for the same file.

A component gets a PHP class in `src/Twig/Components/` when it has a variant
vocabulary worth typing or something to derive — `Badge`, `Card`, `Button`,
`Alert`, `RankMedal`, `BonusPoints`, `PointsMatrix`, `Flashes`. It is an
anonymous template with `{% props %}` when it is only markup — `PageHeader`,
`DataTable`, `Field`, `LinkCard`, `FeatureTile`, `Disclosure`, `EmptyState`,
`SectionHeading`, `BackLink`, `KpiRow`, `TotalsList`, `ArtifactList`,
`LedgerLine`.

**Tailwind class strings belong in the component's template, never in its PHP
class.** Tailwind scans `templates/` and not `src/`, so a class named in PHP is
never compiled — it only appears to work while some template happens to use the
same utility. The PHP class names the variant; the template maps the variant to
classes. Form field styling used to break this rule and is now `.field`, applied
by the form theme in `templates/form/theme.html.twig`, so the form types in
`src/Form/` carry no presentation at all.

**Component props are plain strings, not HTML.** `title="Points &amp; standings"`
renders a literal `&amp;`, because Twig escapes the value again on output. Write
the character. Entities are only correct inside a component's *content*, which is
markup: `<twig:Button>Verify &amp; process</twig:Button>` is right.

`/_styleguide` renders every component in every variant. It is registered in dev
and test only, through `config/routes/styleguide.yaml`, and has no controller
because it shows no data. `PageRendersTest` requests it, so a component that
breaks fails the suite rather than a page.

## Component traps

- Inside a component's content, **`this` and the component's own props are
  rebound to that component**. `{% for tone in tones %}<twig:Badge tone="{{ tone }}">{{ tone }}</twig:Badge>`
  prints the badge's tone enum, not the loop's string, and `this.rows` inside a
  nested `<twig:DataTable>` resolves against the table. Resolve what you need
  before opening the child, and name loop variables away from the child's props.
- A component's root element **cannot be another component with `attributes`
  forwarded into it** — `<twig:Card {{ attributes }}>` is a parse error — and
  nesting one defines `content` twice unless the outer component's own content is
  captured into a variable first. `templates/components/EmptyState.html.twig`
  shows both workarounds.
- Renaming or adding a component sometimes needs a **container restart**, not just
  `make console ARGS="cache:clear --env=dev"`: FrankenPHP runs in worker mode and
  holds compiled Twig templates in memory, so a stale error will keep pointing at
  a line number that no longer exists.

## Mobile-first sizing

**Most people reach this site on a phone.** The narrow layout is the design; the
desktop one is the enhancement. This governs every UI change here.

In practice that means the unprefixed Tailwind utility describes the *phone*, and
`sm:` / `md:` / `lg:` only ever grow it — a breakpoint is never a patch for
something authored at desktop width. Concretely:

- Horizontal room at 375px is the scarcest resource on the site. Padding added to
  a card is width taken from a table. The leaderboard is six columns on a phone
  and is the first thing to break.
- Type scales up, not down: a heading is sized for the phone and given `md:` to
  grow. `text-4xl md:text-6xl`, never `text-6xl` with a `sm:` shrink.
- Columns start at one. `grid-cols-1 sm:grid-cols-2` — never the reverse.
- A column that is dropped on small screens uses `hidden sm:table-cell`, so the
  phone gets the shorter table and the desktop the fuller one.
- Do not set `maximum-scale` or `user-scalable=no` on the viewport meta. Pinch
  zoom is how people read a dense table on a phone.

**Check it before calling UI work done.** A 375px viewport, the leaderboard and
whichever page you touched. `docs/MOBILE.md` records the measurements the current
layout was verified against, page by page — the cardinal rule being that a table
may scroll inside `overflow-x-auto` and the document may not.

## Related

`design-proposal` covers the format for proposing a layout before it is built.
