# Server-rendered admin UI with a REST-backed modal, not a React SPA

The link-group manager and settings UI are built as native WordPress server-rendered screens — a `WP_List_Table` under Tools and a Settings-API page under Settings — progressively enhanced with plain vanilla JS (a `<dialog>` add/edit modal, chip inputs) that talks to a small REST API for mutations only, with **no build step**. We deliberately rejected mirroring the SlimSEO Pro inspiration's React + `@wordpress/components` + build-tooling stack, because it would bolt a node/build pipeline and a parallel client-rendering layer onto a plugin whose stated identity is "small, high-quality, fully owned, no build wall" — and the clean look we want is ~90% native WP admin CSS anyway.

## Considered options

- **React SPA (the literal SlimSEO inspiration)** — rejected: build pipeline, `node_modules`, REST-everything, and a duplicated client-side renderer, all disproportionate to managing a list of keywords.
- **`admin-ajax.php`** — rejected: REST is the modern, consistent mechanism; mixing the two would mean two auth/nonce/registration patterns for one small feature.

## Consequences

- After a modal save or bulk action the table refreshes by **re-rendering the `<tbody>` server-side via REST** (returned as HTML in the JSON), so sorting/search/pagination stay authoritative and there is no second row-renderer to maintain in JS.
- The plugin keeps shipping plain `css/` + `js/` files; the empty `migrations/`, build tooling, and node dependencies stay absent.
