# Changelog

All notable changes to this project are documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/), and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [Unreleased]

## [2.2.0] - 2026-10-26

### Fixed

- Pages with a `<head>` larger than 1 MiB, such as Google Play app pages, got no preview.

### Changed

- Pages are fetched with Symfony HttpClient and gzip compression. Up to 2 MiB of HTML is read after decompression.
- Requests to private networks are blocked with Symfony's `NoPrivateNetworkHttpClient`.
- The HTTP client is bound as `datlechin-link-preview.transport` in the container, so it can be extended, for example to add a proxy.
- Requires PHP 8.3, the same as Flarum 2.0.

## [2.1.0] - 2026-10-26

### Added

- `Datlechin\LinkPreview\Extend\LinkPreview::route()` lets other extensions add cards for their own pages.
- The `LinkPreview` frontend extender's `content()` lets other extensions preview links in their own content.
- Previews in private messages.

### Changed

- Links to the forum are matched with Flarum's router. A forum page without a card now returns `not_previewable` instead of `no_metadata`.
- Meta items have a `type`, and counts use a full translation key.
- The built-in cards for discussions, users, tags and the index are registered with the same extender.

## [2.0.2] - 2026-10-25

### Fixed

- On a forum installed in a subfolder, links to other pages on the same domain, such as `example.com/shop` next to `example.com/forum`, got no preview.

## [2.0.1] - 2026-09-07

### Fixed

- A link on its own line kept its plain link, and its card was shown below the paragraph, when there was no blank line above it.
- The same problem with several links on consecutive lines.

## [2.0.0] - 2026-09-07

Requires Flarum 2.0. The extension was rewritten.

### Added

- Links to the forum (discussions, users, tags and the index) are previewed from the database, and only show what the reader is allowed to see.
- Large and compact card layouts, chosen from the page's metadata.
- Users can turn off previews in their settings.
- The click count is shown when Link Clicks is installed.
- Dark mode support.

### Changed

- **Breaking:** "Domain Blacklist" and "Domain Whitelist" are renamed to "Blocked sites" and "Allowed sites". Existing entries are migrated. Entries now match the whole host name, so `evil.com` no longer matches `notevil.com.example.org`.
- **Breaking:** "Preview Media URLs" is replaced by "Skip links to media files", which has the opposite meaning. Check this setting after updating.
- **Breaking:** Both API endpoints use POST.
- **Breaking:** Errors are returned as error codes with HTTP status codes instead of translated messages.
- The whole card is one link.
- Cards use Flarum's own styles.
- A link whose preview fails is left unchanged, with no card or error message.

### Removed

- **Breaking:** The external oEmbed fallback and its two settings. It sent links to a third-party service.

### Fixed

- Previews failed on servers where the IPv4-only DNS lookup did not work.
- A Cloudflare challenge page was cached as the preview.
- Images with a relative URL were missing.
- YouTube links got no preview because the page was cut off too early.
- Facebook refused the request because the extension identified itself as Chrome.
- One slow request held up every other preview on the page.
- Cards were kept in memory until the tab was closed.
- Links to the forum reloaded the page instead of navigating within it.

### Security

- Requests to loopback, private and reserved IP addresses are blocked, for IPv4 and IPv6, and the connection uses the address that was checked.
- Up to 3 redirects are followed, and each one is checked again.
- Only `http` and `https` URLs are fetched, and TLS certificates are always verified.
- Each page is limited to 1 MiB and 8 seconds, and each user to 30 requests per minute. Failed previews are cached.

[Unreleased]: https://github.com/datlechin/flarum-link-preview/compare/v2.2.0...HEAD
[2.2.0]: https://github.com/datlechin/flarum-link-preview/compare/v2.1.0...v2.2.0
[2.1.0]: https://github.com/datlechin/flarum-link-preview/compare/v2.0.2...v2.1.0
[2.0.2]: https://github.com/datlechin/flarum-link-preview/compare/v2.0.1...v2.0.2
[2.0.1]: https://github.com/datlechin/flarum-link-preview/compare/v2.0.0...v2.0.1
[2.0.0]: https://github.com/datlechin/flarum-link-preview/compare/v1.6.1...v2.0.0
