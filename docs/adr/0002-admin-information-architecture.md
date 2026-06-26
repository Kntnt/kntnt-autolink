# Admin IA: structural rules under Settings, link-group manager under Tools

Autolink's two concerns are split across two native WordPress menus by audience and convention. The **link-group manager** — editor-facing and used often — lives under **Tools → Autolink** as a `WP_List_Table`. The admin-only **structural rules** live under **Settings → Autolink** via the Settings API. Reciprocal cross-links connect the two screens, and the plugin's action-links row on the Plugins screen carries both links; the Settings link shown *on the Tools page* is gated to `manage_options` so editors are never sent to a permission wall.

## Considered options

- **One combined page** (today's layout) — rejected: it crams an editor-facing list and admin-only configuration together and scales badly.
- **A dedicated top-level menu** — rejected: configuration belongs under Settings and a working list belongs under Tools by WP convention, and a utility plugin shouldn't claim top-level sidebar space.

## Consequences

- A future reader might assume the two screens "should" be merged into one menu; this split is **deliberate** — don't consolidate it.
- The capability split falls out naturally: editors reach only the Tools list; the Settings menu is admin-only by nature.
