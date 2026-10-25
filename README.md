# Link Preview

[![License](https://img.shields.io/badge/license-MIT-blue.svg)](LICENSE.md) [![Latest Stable Version](https://img.shields.io/packagist/v/datlechin/flarum-link-preview.svg)](https://packagist.org/packages/datlechin/flarum-link-preview) [![Total Downloads](https://img.shields.io/packagist/dt/datlechin/flarum-link-preview.svg)](https://packagist.org/packages/datlechin/flarum-link-preview) [![Sponsor](https://img.shields.io/github/sponsors/datlechin?logo=githubsponsors&label=Sponsor)](https://github.com/sponsors/datlechin)

Paste a link in a post and it becomes a card with the page's title, description and image. Your forum reads the page itself, so nothing is sent to a third party.

![A post with three link preview cards](screenshots/forum.png)

## Install

```sh
composer require datlechin/flarum-link-preview:"*"
```

Updating:

```sh
composer update datlechin/flarum-link-preview:"*"
php flarum migrate
php flarum cache:clear
```

Needs Flarum 2.0 and PHP 8.2.

## What gets a card

Only a pasted address, meaning the link's text is the address itself. `[the docs](https://example.com)` keeps the words you wrote.

Left alone: mentions, quotes and code, media files if you ask, links past the per-post limit, and anything your lists rule out. A preview that cannot be built is left alone too: no card, no message, just the link as it was written.

A link back to this forum gets a card built from the database, not fetched: a discussion, a member, a tag, the index, or a page another extension describes. Readers only see what they could already open. It has to be alone on its line; inside a sentence Flarum's own `#12` label is better.

Posts and private messages both get cards. Members can turn previews off for themselves in their settings.

## Settings

![The settings page](screenshots/admin.png)

The two lists take one entry per line or separated by commas:

| Entry | Matches |
| --- | --- |
| `example.com` | that host and its subdomains |
| `example.com/news` | that path and everything under it |
| `*.example.com` | one label in front of the domain |

Anchored at both ends, so `example.com` does not match `notexample.com.evil.org`.

## Security

Anyone who can read a post can ask your server to fetch a URL, so:

- Loopback, private and reserved addresses are refused, IPv4 and IPv6. The connection is pinned to the address that was checked, so DNS cannot swap in an internal one afterwards.
- Redirects are followed by hand, three hops at most, each one checked again.
- `http` and `https` only. TLS is always verified.
- 1 MiB and 8 seconds per page, 30 requests a minute per member.
- Failures are cached too, so a dead domain is not retried on every page view.

Older versions could fall back to a third-party oEmbed service. That sent your members' links to someone else's server, so it is gone.

## For extension developers

Two things can be added from another extension, each without a hard dependency on this one.

### A card for your pages

Register a card against a forum route name. The route is matched by Flarum's own router, so optional segments and slugs work as they do for the page.

```php
use Datlechin\LinkPreview\Extend\LinkPreview;

return [
    (new Extend\Conditional())
        ->whenExtensionEnabled('datlechin-link-preview', fn () => [
            (new LinkPreview())->route('acme-recipes.show', RecipeCard::class),
        ]),
];
```

```php
use Datlechin\LinkPreview\Internal\Card;
use Datlechin\LinkPreview\Internal\MatchedRoute;
use Datlechin\LinkPreview\Internal\Meta;
use Datlechin\LinkPreview\Internal\RouteCard;

class RecipeCard implements RouteCard
{
    public function card(MatchedRoute $route, User $actor): ?Card
    {
        $recipe = Recipe::whereVisibleTo($actor)->find($route->parameter('id'));

        if (! $recipe) {
            return null;
        }

        return new Card(
            title: $recipe->title,
            description: $recipe->summary,
            image: $recipe->photo_url,
            meta: [
                Meta::user($recipe->user->username),
                Meta::count('acme-recipes.forum.link_preview.steps', $recipe->step_count),
                Meta::date($recipe->created_at),
            ],
        );
    }
}
```

- Return `null` when the page does not exist or the actor may not see it. Both read the same from outside.
- The forum's name and favicon are added for you.
- `Meta::count()` takes a full translation key with a plural string, so it lives in your locale.
- A card that throws is logged and skipped, and the rest of the batch is still answered.

### Links inside your content

Posts and private messages are scanned already. To scan formatted HTML that your own component renders, name the component and the elements holding it:

```ts
app.initializers.add('acme-recipes', () => {
  const LinkPreview = flarum.reg.get('datlechin-link-preview', 'forum/extenders/LinkPreview');

  if (LinkPreview) {
    new LinkPreview()
      .content('ext:acme/recipes/forum/components/RecipePage', '.RecipePage-body')
      .extend(app, { name: 'acme-recipes', exports: {} });
  }
});
```

- Look the extender up inside the initializer, not with a top-level `ext:` import. That import resolves when your script loads, and a forum that has not re-sorted its extensions since you added Link Preview as an optional dependency loads your script first.
- The component can be a module path, so a lazily loaded page is patched when it arrives, or the class itself.
- The second argument is a selector matched within the component's DOM, or a function given the vnode that returns the elements.
- Links are collected on create and update, and cards are unmounted on remove.

## Links

- [Packagist](https://packagist.org/packages/datlechin/flarum-link-preview)
- [Discuss](https://discuss.flarum.org/d/30011)
