# Design proposals

When a change needs a layout decision, we do not draw one screen and argue
about it. We produce a **proposal**: a document that names every reusable
block, then shows two or three genuinely different arrangements of them per
page, at both the widths that matter.

This file is the format. It exists so a layout decision can be made once,
communicated in three words, and — crucially — **deferred until the ticket that
builds it actually starts**, without the proposal going stale in the meantime.

`docs/MOBILE.md` is the measurement record and `AGENTS.md` holds the design
system rules. This is the process that sits on top of both.

## Why the format is what it is

| Rule | Why |
| --- | --- |
| Component catalogue comes first | Without shared names, feedback is "the win-rate thing from the third one", which nobody can act on. |
| The catalogue starts from what already exists | A proposal that invents a component the repo already has is proposing a duplicate, and nobody notices until it is built. |
| Two or three options per page | One option is a fait accompli. Four is a survey. Three forces each one to have a reason to exist. |
| Options differ in *purpose*, not styling | Two restyles of the same layout is one option with extra steps. |
| Every mockup at 375px **and** wide | Most people reach this site on a phone. An option that only works on a laptop has not been designed. |
| Real data, always | Invented scorelines hide the cases that break a layout — long names, ties, byes, a 22-row table. |
| Costs named, not only benefits | An option nobody can argue against has not been described honestly. |

## 1. The component catalogue

**Always the first section.** Every reusable block gets a name, rendered once,
in isolation, with a one-line description and a note of which pages use it.

### Start from what exists

**Open `/_styleguide` before drawing anything.** It renders every component in
every variant, which is precisely what a catalogue needs as its first draft.
Then go through `templates/components/` and `src/Twig/Components/`.

Every entry in the catalogue must say which of three things it is:

| | Means |
| --- | --- |
| **Existing** | `Card`, `Badge`, `DataTable`, `RankMedal`. Use it. Name it by its real component name so nobody rebuilds it. |
| **Extension** | An existing component gaining a variant or a prop — a new `Tone` on `Badge`, a value slot on `FeatureTile`. Cheap, and it keeps one component rather than two. |
| **New** | Genuinely nothing to build on. Has to justify itself in a sentence. |

A block made of existing components is not a new component. It says
**built from** and lists them: a standings table is `DataTable` plus
`RankMedal`, not a fresh table.

This matters most for tables. `DataTable` already owns the scroll shell, the
cell rhythm and the `dense` and `bleed` props, and `.data-table` in
`assets/styles/app.css` owns the padding. Six different-looking tables across a
proposal are six sets of columns inside one component.

Get this wrong and the cost is real. The proposal behind #61 named 27 blocks as
though the cupboard were bare; roughly a dozen were things the repo already had
or near-variants of them, including one called `DISCLOSURE` when
`Disclosure.html.twig` was sitting in `templates/components/` with the same
name and the props already on it.

### Naming

- **An existing component keeps its own name.** `Card`, not `PANEL`.
- Anything genuinely new gets `SCREAMING-KEBAB`, one to three words:
  `KPI-ROW`, `MATCH-LOG`, `H2H-BARS`, `CUT-PATH`.
- Named for the job, not the shape — the same rule the design tokens follow.
  `STANDINGS`, not `wide-table-with-form-column`.
- Grouped into three or four bands (summary blocks, page-specific blocks,
  admin blocks) so the catalogue is scannable.

The names are not throwaway. They become the files in `templates/components/`,
so the vocabulary survives the proposal and ends up in the codebase. If a block
in the catalogue has no plausible component behind it, it is probably two
blocks.

Mark blocks that exist in the data but are deliberately not rendered — those
are decisions, and a reader should see them.

## 2. The options

Each option gets:

- **A letter and a name.** `Option 3B — The story of the event`.
- **A sentence on what it is for.** Not what it looks like. "Opens on the
  podium and the sentence that describes the day" is a purpose; "uses cards
  with amber accents" is not.
- **The components it uses**, by name, as tags — existing ones included, so
  the true cost of an option is visible. An option built entirely from things
  that already exist is cheaper than one that needs four new blocks, and that
  should be legible without reading the code.
- **Its tradeoffs**, at least one of which is a cost.
- **The mockup itself**, at 375px and at full width.

Options are numbered by page and lettered within it, so `3A`, `3B` and `3C`
are the three tournament-page options. That is what makes the shorthand work.

## 3. How a choice is communicated

**One letter per page.** `2C, 3A, 4A, 5B` is a complete answer.

**Swaps are named by component.** Because every option is assembled from the
same catalogue, mixing is a normal outcome rather than a compromise:

- `4A but swap H2H-TABLE for H2H-BARS`
- `3C without BRACKET`
- `2A, and take ARCHIVE-SUMMARY from 2C`

A proposal should end with a **default to react against** — a full set of
letters the author would pick — because reacting to a concrete suggestion is
easier than choosing from a blank page.

## 4. Deferring the decision

A layout choice does not have to be made when the proposal is written. It has
to be made when the ticket that builds it starts.

So the proposal keeps all its options, and the ticket says so explicitly:

> Three options are drawn up in the design document, at both widths, with a
> catalogue of the named components each uses. **Pick one at the start of this
> ticket.**

This is why the catalogue matters more than the mockups. Options age; a named
component that maps to a Twig template does not.

## 5. Building the mockups

- **One markup block, two widths.** Write the mockup once and render it twice,
  in a 375px frame and in a wide one, using **container queries**
  (`@container (min-width: 600px)`) rather than viewport media queries. The
  narrow view is then literally the same component at phone size, not a second
  drawing of it. If the two disagree, that is a real responsive bug and the
  proposal has caught it for free.
- **Phone frame first in reading order.** The narrow layout is the design.
- **The site's palette, not the document's.** A mockup is a screenshot of a
  page that does not exist yet, so it should look like this site — dark
  surfaces and all — even when the surrounding document is theme-aware.
- **Real data with a stated source.** Every number should be traceable. Where a
  figure is illustrative rather than measured, say so in the caption.
- **Show the mobile compromises.** If a column drops behind
  `hidden sm:table-cell`, or a section collapses behind a disclosure on a
  phone, the narrow mockup should show that happening and the caption should
  name it.

## 6. Where a proposal lives

Proposals are published as artifacts, which are private to their owner. This
repository is public and contributors cannot open them.

So:

- **The tickets carry the substance inline.** A contributor who cannot open the
  artifact should still be able to build the ticket: the constraints, the data,
  the acceptance criteria and the mobile rules all live in the issue body.
- **The link is a convenience, not the source of truth.** Include it, and say
  plainly who it resolves for.
- Anything a proposal establishes that outlives it — a component name, a metric
  we decided against, a rule about what is deliberately not displayed — belongs
  in this repo, either in a ticket or in `docs/`.

## Checklist

Before a design proposal goes out:

- [ ] `/_styleguide` and `templates/components/` reviewed before drawing
- [ ] Component catalogue is the first section, and every option draws from it
- [ ] Every entry marked existing, extension or new, and every new one justified
- [ ] Blocks assembled from existing components say "built from" and list them
- [ ] Each component has a name, a one-line description and a rendered example
- [ ] Two or three options per page, each with a stated purpose
- [ ] Every mockup rendered at 375px and at full width, from the same markup
- [ ] No horizontal page overflow at 375px in any mockup — a table may scroll
      inside its container, the document may not
- [ ] Every option tagged with the components it uses
- [ ] At least one honest cost per option
- [ ] A default set of letters the author recommends
- [ ] All data real, with its source stated
- [ ] Blocks captured in the data but deliberately not rendered are called out
- [ ] The corresponding tickets say when the choice gets made

## Worked example

The Challonge full-import pipeline (#61) went through this. Its proposal named
27 blocks, then gave three options each for the import preview, the tournament
page, the player profile and a new records board — twelve mockups, each
rendered at 375px and at full width from one markup block. The layout choice
was deliberately deferred to #57, #58 and #59, which say so in their own
bodies.

It also got the reuse rule wrong on the first pass, which is why that rule is
in this file. The catalogue was drawn from scratch instead of from
`/_styleguide`, so about a dozen of its 27 entries were duplicates or
near-variants of components already in `templates/components/`. Every table in
it is `DataTable` with different columns. That was corrected in the proposal
rather than discovered during #57.
