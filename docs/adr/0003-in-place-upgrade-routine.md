# Self-healing in-place upgrade routine, despite the brief's "no data migration"

Issue #1's Agent Brief scoped data migration out: "Storage stays an options array (renamed from the keyword option); no data migration (fresh install — there is no released install to upgrade)." That premise is factually wrong — **v1.0.0 was tagged and released** (GitHub release, 2026-06-25). Renaming the gating capability (`kntnt_autolink_manage_keywords` → `kntnt_autolink_manage_link_groups`) and the storage option (`kntnt_autolink_keywords` → `kntnt_autolink_link_groups`) without an upgrade path would, on an **in-place** 1.0.0 → 1.1.0 update (which never fires the activation hook), leave no role holding the new capability — locking even administrators out of Tools → Autolink and every REST route — and orphan the existing keyword data under the old option key. We therefore add a version-keyed, self-healing upgrade routine (`classes/Migrator.php`), **deliberately overriding the brief on this one point**.

## Considered options

- **Follow the brief literally (no migrator)** — rejected: it bricks every existing 1.0.0 site on update — a capability lock-out plus orphaned keyword data — which is the exact failure the rename introduces.
- **A one-shot activation-only migration** — rejected: WordPress fires the activation hook only on activation, never on an in-place update, so it would not run on the very sites that need it.

## Consequences

- The migrator runs once per version bump: on `init` it compares the stored schema version (`kntnt_autolink_version`) to the running one and, on a mismatch, re-grants the renamed capability, folds the legacy keyword entries (base + variants → phrases, max → cap, the old *global* nofollow / new-tab → onto each group) into link groups, retires the legacy capability, then stamps the running version. Fresh installs short-circuit because `install.php` stamps the version up front.
- Migration is guarded against data loss: it writes link groups only when the legacy option exists **and** the new option is still unset, so a re-run can never clobber link groups created since. Migrated URLs are re-run through `esc_url_raw` so the sanitisation invariant stays local to the migrator rather than being inherited from the upstream option.
- This is a **deliberate, owner-ratified expansion** of issue #1's stated scope. The brief's "no data migration" line is superseded by this record; a future reader should not "simplify" the plugin by deleting the upgrade routine.
