---
name: design-proposal
description: The fixed format for proposing a page layout before it is built — the companion component library, two or three options per page, mockups at 375px and full width, and how a choice is communicated. Use when asked to propose, design or rethink a page or a layout, when a new page is being added, when an existing page is being rebuilt, or when writing or reviewing a proposal document or its component library.
---

# Proposing a layout

Anything that needs a layout decision — a new page, a rebuild of an existing one
— goes through a **design proposal** before it is built.

**Read `docs/DESIGN-PROPOSALS.md`.** It is the format in full, with the rules
table, the naming conventions, a checklist and a worked example. What follows is
the short version, so you know what you are looking for.

- **There are two component libraries and only one is real.** `/_styleguide` is
  the factual one — it renders what is actually in `templates/components/`, and
  `PageRendersTest` requests it. A proposal's component library is the *proposed*
  one: mostly drawings of blocks that do not exist yet. Never read a proposal as
  documentation of the site.
- **The proposed library is a companion document to the proposal**, not a section
  inside it, so it can be reviewed on its own and diffed against
  `/_styleguide` — that diff is the build list. The proposal's section `01` links
  to it and lists the block names, so the proposal still reads alone.
- **Both are proposal artifacts and both stop when the proposal does.** When a
  ticket builds a block it goes into `templates/components/` and `/_styleguide`,
  and from then on the styleguide describes it. Do not maintain the proposal's
  library afterwards.
- **Start the library from `/_styleguide`.** Every entry is marked *in the
  styleguide*, *extension* or *new*; an existing component keeps its real name
  (`Card`, not `PANEL`); a block assembled from existing ones says **built from**
  rather than claiming to be new. Only genuinely new blocks get a
  `SCREAMING-KEBAB` name, and that name becomes the file when it is built.
- **Tables are the usual offender.** `DataTable` owns the scroll shell, the
  `dense` and `bleed` props and the `.data-table` cell rhythm. Six
  different-looking tables in a proposal are six sets of columns inside one
  component, not six components.
- **Two or three options per page**, each a different idea about what the page is
  *for* rather than a restyle, each tagged with the blocks it uses, each with at
  least one honest cost.
- **Every mockup rendered at 375px and at full width from the same markup**,
  using container queries rather than viewport media queries — so the narrow view
  is the same component at phone size and not a second drawing of it.
- **Choices are communicated as a letter per page plus component swaps**:
  `3A but swap H2H-TABLE for H2H-BARS`.
- **The choice is made when the ticket starts, not when the proposal is written.**
  The ticket says so explicitly.

Proposals are published as private artifacts, so a ticket must carry everything a
contributor needs in its own body. The link is a convenience for whoever owns it.


## Before drawing anything

Open `/_styleguide` and go through `templates/components/` and
`src/Twig/Components/`. That is the first draft of the proposed library, and the
gap between the two is the build list. The `design-system` skill covers how the
components themselves are written.

`docs/MOBILE.md` is the measurement record every mockup is checked against.
