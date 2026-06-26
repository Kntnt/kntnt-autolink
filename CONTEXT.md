# Kntnt Autolink

The domain of automatically turning literal phrases in published content into links to a chosen URL.

## Language

**Autolink**:
The automatic replacement of a phrase found in published content with a link to its link group's URL. Both the feature and the individual act.
_Avoid_: keyword linking, interlink

**Link group**:
The unit a user manages: a set of equal, interchangeable phrases that all link to one URL, share one link budget, and carry their own link behaviour (whether the links are nofollowed and whether they open in a new tab). It has no canonical or "main" member — every phrase in it is a peer.
_Avoid_: keyword, entry, rule, term

**Phrase**:
A single surface form — one or more words — belonging to a link group. All phrases in a group are equal candidates; the linker matches them case-insensitively on whole-word boundaries, with the longest matching phrase winning a tie.
_Avoid_: word, variant, base form, synonym, form

**Group cap**:
The maximum number of links a single link group may produce within one post. The first occurrences of any of the group's phrases, in document order, consume the budget; once it is spent, no further phrase from that group links. Default is 1.
_Avoid_: max, per-keyword max, limit

**Post cap**:
The maximum number of autolinks allowed across all link groups within one post, applied on top of each group cap.
_Avoid_: global max, max links

**Term targeting**:
An optional include-filter that narrows autolinking to posts carrying at least one of the chosen terms. It combines with the enabled post types as a logical AND; when no terms are chosen, autolinking runs on every post of the enabled post types.
_Avoid_: term filter, scope, taxonomy rule
