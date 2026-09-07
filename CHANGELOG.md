# Changelog

## 2.0.0

Requires Flarum 2.0 and PHP 8.2. Both halves of the extension were rewritten.

### Breaking

- **Domain Blacklist** and **Domain Whitelist** are now **Blocked sites** and **Allowed sites**. Your entries are migrated. Matching is anchored now, so an entry of `com` no longer blocks every site on the internet, and `evil.com` no longer matches `notevil.com.example.org`.
- **Preview Media URLs** is now **Skip links to media files**, which asks the opposite question. Check it after updating.
- The external oEmbed fallback is gone, with both its settings. It sent every address that failed to a third party, including links to pages only your members can see. Sites that refuse to be read by a server now keep a plain link.
- Both API endpoints are POST, including the one that only reads. A cross-site GET needs nothing but an `<img>` tag to fire.
- Failures come back as codes with real HTTP statuses, instead of translated sentences returned as success.

### Previews

- The whole card is one link. The title and the domain used to be two separate links to the same address, and the body was not clickable at all.
- Two shapes, chosen from what the page says about itself: a large card with the page's image, or a compact row.
- A link back to this forum is answered from the database instead of being fetched: a discussion, a member, a tag, or the index. Readers only ever see what they could already open.
- A link whose preview fails is left exactly as it was. No card, no message.
- Cards are built from Flarum's own design: the box a post quote is, the type of the discussion list, core's loading placeholder and focus ring, and dark mode with nothing to configure.
- Members can turn previews off for themselves.
- Shows the click count when Link Clicks is counting one.

### Fixed

- Previews failing on every link, on hosts where the old IPv4-only lookup could not answer.
- A Cloudflare challenge page being read as a real page and cached as the preview.
- Images missing when the page gave their address relative to itself.
- YouTube returning nothing, because the page was cut off before the part that describes it.
- Facebook refusing the request, because the extension claimed to be Chrome.
- One stuck request stranding every other preview on the page.
- Cards piling up in memory for as long as the tab stayed open.
- Links back to the forum reloading the page instead of routing, including a link to the discussion you are already reading.

### Security

- Loopback, private and reserved addresses are refused, IPv4 and IPv6 alike, and the connection is pinned to the address that was checked so DNS cannot swap in another afterwards.
- Redirects are followed three hops at most, and every hop is checked again.
- `http` and `https` only. TLS is always verified.
- 1 MiB and 8 seconds per page, 30 requests a minute per member, and failures are cached so a dead domain is not retried on every page view.
