# Link Preview

[![License](https://img.shields.io/badge/license-MIT-blue.svg)](LICENSE.md) [![Latest Stable Version](https://img.shields.io/packagist/v/datlechin/flarum-link-preview.svg)](https://packagist.org/packages/datlechin/flarum-link-preview) [![Total Downloads](https://img.shields.io/packagist/dt/datlechin/flarum-link-preview.svg)](https://packagist.org/packages/datlechin/flarum-link-preview) [![Sponsor](https://img.shields.io/github/sponsors/datlechin?logo=githubsponsors&label=Sponsor)](https://github.com/sponsors/datlechin)

A [Flarum](https://flarum.org) 2.x extension. A pasted address in a post gets a card showing the page's title, description, image and site icon. Your forum reads the page itself; no third-party service is involved.

## What gets a preview

A link qualifies when its text is the address itself, which is what happens when somebody pastes a URL. That is the same rule core uses to decide a link is "bare".

These are left exactly as they are:

- a link with text of its own, such as `[the docs](https://example.com)`
- mentions, and links core has already labelled as a discussion
- anything inside a quote, a code block or a code span
- links to media files, when **Skip links to media files** is on
- links past the per-post limit
- anything the allowed or blocked list rules out

A link to a discussion on this forum is answered from the database instead of being fetched, and shows the discussion's title, author, reply count and tags. A reader only ever sees a discussion they could already open, so the card cannot be used to look into a private tag.

Each member can switch previews off for themselves in their settings.

## Settings

| Setting | Default | What it does |
| --- | --- | --- |
| Open links in a new tab | on | Other sites only. A link to this forum always opens in the same tab, so pressing a card routes instead of reloading. |
| Fall back to Google for site icons | off | When a site offers no icon, ask Google's favicon service. This tells Google which sites your members link to. |
| Preview links to this forum | on | Discussion cards, as above. |
| Skip links to media files | off | No card for a link ending in an image, audio or video file. |
| Previews per post | 5 | Links past this count stay plain. |
| Fetch previews in batches | on | One request per page rather than one per link. |
| Cache duration | 60 minutes | How long a preview is kept. 0 turns caching off. |
| Allowed sites | *(empty)* | While it is not empty, only links matching it are previewed. |
| Blocked sites | *(empty)* | Never previewed, whatever the allowed list says. |

Both lists take one entry per line, or entries separated by commas:

| Entry | Matches |
| --- | --- |
| `example.com` | `example.com` and any subdomain of it |
| `example.com/news` | that path and everything under it |
| `*.example.com` | one label in front of the domain |

Entries are anchored at both ends, so `example.com` does not match `notexample.com.evil.org`.

## Security

The forum makes the request, so the endpoint is a way to ask your server to fetch a URL. It is fenced accordingly:

- `http` and `https` only.
- The host is resolved and every address in a private, reserved or loopback range is refused, IPv4 and IPv6 alike. The connection is then pinned to the address that was checked, so a DNS record that changes in between cannot swap in an internal one.
- Redirects are followed by hand, at most three hops, and every hop is checked again.
- The response has to answer 200 and be HTML. A challenge page or an error page is not read as a preview.
- At most 256 KB is read, and reading stops as soon as `</head>` goes past.
- Eight seconds in total, four to connect.
- 30 requests a minute per member, or per address for guests.
- Failures are cached too, for up to ten minutes, so a dead domain is not retried on every page view.

Previews are public in the sense that anyone who can read the post can see them. The gate is the fetching, not the reading.

## The external oEmbed fallback is gone

Older versions could hand the address to a third-party oEmbed service when the direct fetch failed. That sent addresses posted on your forum, including links to pages only your members can see, to a server nobody here controls, and it bought a preview only for sites that had deliberately refused one. Both settings have been removed and the migration deletes their rows. Sites that block server-side fetching now show a short line saying so.

## Installation

```sh
composer require datlechin/flarum-link-preview:"*"
```

## Updating

```sh
composer update datlechin/flarum-link-preview:"*"
php flarum migrate
php flarum cache:clear
```

The migration renames the old settings (`blacklist`, `whitelist`, `use_google_favicons`, `convert_media_urls`) and drops the external API ones. Check **Skip links to media files** afterwards: it asks the opposite question to the old **Preview Media URLs**.

## Links

- [Packagist](https://packagist.org/packages/datlechin/flarum-link-preview)
- [GitHub](https://github.com/datlechin/flarum-link-preview)
- [Discuss](https://discuss.flarum.org/d/30011)
