import app from 'flarum/forum/app';
import Component from 'flarum/common/Component';
import type { ComponentAttrs } from 'flarum/common/Component';
import Icon from 'flarum/common/components/Icon';
import classList from 'flarum/common/utils/classList';
import extractText from 'flarum/common/utils/extractText';
import humanTime from 'flarum/common/helpers/humanTime';
import listItems from 'flarum/common/helpers/listItems';
import ItemList from 'flarum/common/utils/ItemList';
import type Mithril from 'mithril';
import type { PreviewLayout, PreviewSuccess } from '../../common/types';
import { loadPreview, previewFor } from '../utils/previewStore';

const KNOWN_ERRORS = ['invalid_url', 'blocked', 'unsafe_address', 'unreachable', 'http_error', 'not_previewable', 'no_metadata'];

// `Metadata::layout()` picks `large` from these same numbers. Different ones
// here would make one image render two ways depending on whether the page it
// came from happened to declare its dimensions.
const LARGE_MIN_WIDTH = 600;
const LARGE_MIN_RATIO = 1.2;
const LARGE_MAX_RATIO = 3;

/**
 * The page on the other end writes its own title, and a bidi override dropped
 * into it reorders everything after it, so a card can be made to read as one
 * address while linking to another. That is the Trojan Source trick pointed at
 * a link preview, so every string that came from somewhere else is cleaned
 * before it reaches the screen.
 */
function plain(text: string | null): string {
  return (text ?? '').replace(/[\u202A-\u202E\u2066-\u2069]/g, '');
}

export interface LinkPreviewCardAttrs extends ComponentAttrs {
  /** The anchor the card stands in for. Its `href` is the card's `href`. */
  link: HTMLAnchorElement;
  /**
   * Where the link actually leads, which `collectPreviewTargets` resolved and
   * which is not always the anchor's `href`. A click tracker rewrites the
   * `href` to a route of its own, so this is what the card describes and what
   * everything but the `href` is decided from.
   */
  url: string;
  /** Whether `url` is an address this forum serves. Decided by `collectPreviewTargets`, so the two cannot drift apart. */
  internal: boolean;
}

export default class LinkPreviewCard extends Component<LinkPreviewCardAttrs> {
  protected imageFailed = false;
  protected deadFavicons = new Set<string>();
  protected measuredLarge: boolean | null = null;
  protected clicks: MutationObserver | null = null;

  oninit(vnode: Mithril.Vnode<LinkPreviewCardAttrs, this>) {
    super.oninit(vnode);

    loadPreview(this.attrs.url, () => m.redraw());
  }

  view(): Mithril.Children {
    const state = previewFor(this.attrs.url);

    if (state.status === 'loading') {
      return this.viewLoading();
    }

    if (state.status === 'failed') {
      return this.viewFailed(state.code);
    }

    return this.viewReady(state.data);
  }

  /**
   * Core's own placeholder, not a second kind of skeleton: `fakeText` is the
   * bar `LoadingPost` is built from, and the stylesheet puts core's `blink`
   * animation over it.
   */
  protected viewLoading(): Mithril.Children {
    const label = extractText(app.translator.trans('datlechin-link-preview.forum.loading'));

    return (
      <a {...this.anchorAttrs(['LinkPreview--compact', 'LinkPreview--loading'], label)} aria-busy="true">
        <div className="LinkPreview-body">
          <div className="fakeText" />
          <div className="fakeText" />
        </div>
      </a>
    );
  }

  protected viewFailed(code: string): Mithril.Children {
    const key = KNOWN_ERRORS.includes(code) ? code : 'unknown';
    const message = app.translator.trans(`datlechin-link-preview.forum.errors.${key}`);
    const host = this.host();

    // No `aria-label`: the site name and the message are both real text inside
    // the anchor, and a label would replace them as the accessible name rather
    // than add to it.
    return (
      <a {...this.anchorAttrs(['LinkPreview--compact', 'LinkPreview--failed'])}>
        <div className="LinkPreview-body">
          <ul className="LinkPreview-info">{listItems(this.infoItems(null, host, host).toArray())}</ul>
          <div className="LinkPreview-excerpt">{message}</div>
        </div>
      </a>
    );
  }

  protected viewReady(data: PreviewSuccess): Mithril.Children {
    const image = this.imageFailed ? null : data.image;
    const layout = this.layout(data, image !== null);
    // Where the link ends up, which is not always where it pointed.
    const host = this.host(data.url);
    const heading = plain(data.title);
    const title = heading || host;
    // A card with no title of its own already says the host as its title, and
    // repeating it on the line above reads as two different facts.
    const siteName = heading === '' ? host : this.siteLabel(plain(data.siteName), title, host);
    const description = plain(data.description);

    // No `aria-label`: everything the card says is text inside the anchor, and
    // a label would replace all of it as the accessible name.
    return (
      <a {...this.anchorAttrs([`LinkPreview--${layout}`, 'fadeIn'])}>
        {image && (
          <div className="LinkPreview-media">
            <img
              className="LinkPreview-image"
              src={image.url}
              alt=""
              aria-hidden="true"
              loading="lazy"
              decoding="async"
              onerror={() => {
                this.imageFailed = true;
                m.redraw();
              }}
              onload={(event: Event) => {
                if (event.target instanceof HTMLImageElement) {
                  this.remeasure(data, event.target);
                }
              }}
            />
          </div>
        )}
        <div className="LinkPreview-body">
          <ul className="LinkPreview-info">{listItems(this.infoItems(data, siteName, host).toArray())}</ul>
          {heading !== '' && <div className="LinkPreview-title">{heading}</div>}
          {description && <div className="LinkPreview-excerpt">{description}</div>}
        </div>
      </a>
    );
  }

  /**
   * Everything the card says about the link other than its title and its
   * description, as one list.
   *
   * `DiscussionListItem` states its own metadata the same way, and for the same
   * reason: an `ItemList` rendered through `listItems` lets another extension
   * put something of its own in the row without this component knowing about it.
   */
  protected infoItems(data: PreviewSuccess | null, siteName: string, host: string): ItemList<Mithril.Children> {
    const items = new ItemList<Mithril.Children>();

    items.add(
      'site',
      <span className="LinkPreview-site">
        {this.viewFavicon(data, host)}
        <span className="LinkPreview-siteName">{siteName}</span>
      </span>,
      100
    );

    const clicks = this.clickCount();

    // Beside the site rather than at the end of the row: how many people opened
    // this link is a fact about the link, and on a discussion card the items
    // between belong to the discussion.
    if (clicks !== null) {
      items.add('clicks', app.translator.trans('datlechin-link-preview.forum.clicks', { count: clicks }), 95);
    }

    const discussion = data?.discussion;

    if (!discussion) {
      return items;
    }

    // Named, not coloured. Core only ever paints a tag's colour as a background
    // with `--contrast-color` picking a legible foreground over it; the same
    // colour used as text on this card has nothing guaranteeing it can be read.
    for (const [index, tag] of discussion.tags.entries()) {
      items.add(`tag${index}`, plain(tag.name), 90 - index);
    }

    if (discussion.author) {
      items.add('author', <span className="username">{plain(discussion.author)}</span>, 80);
    }

    // `commentCount` counts the opening post as well, which is how Flarum
    // stores it and not what a reader means by "replies".
    items.add('replies', app.translator.trans('datlechin-link-preview.forum.replies', { count: Math.max(0, discussion.commentCount - 1) }), 70);

    items.add('createdAt', humanTime(new Date(discussion.createdAt)), 60);

    return items;
  }

  protected viewFavicon(data: PreviewSuccess | null, host: string): Mithril.Children {
    const src = data ? this.faviconUrl(data, host) : null;

    if (!src) {
      return <Icon name="fas fa-link" className="LinkPreview-favicon" />;
    }

    return (
      <img
        className="LinkPreview-favicon"
        src={src}
        alt=""
        aria-hidden="true"
        loading="lazy"
        decoding="async"
        onerror={() => {
          this.deadFavicons.add(src);
          m.redraw();
        }}
      />
    );
  }

  /**
   * How many people have opened this link, when something is counting.
   *
   * Read off the anchor rather than fetched, because the extension that counts
   * clicks already renders the number there and already decides whether it is
   * worth showing: it leaves the attribute off entirely below the forum's
   * `min_display_count`. Reading it means this card inherits that decision
   * instead of making a second one, and shows nothing at all when nothing is
   * counting.
   */
  protected clickCount(): number | null {
    // Only when the card stands in for the link. Where the link is still on
    // screen beside the card, it is already wearing the count as a badge, and
    // saying it twice reads as two different numbers that happen to agree.
    if (!this.attrs.link.classList.contains('LinkPreview-source')) {
      return null;
    }

    const raw = this.attrs.link.getAttribute('data-clicks');

    if (raw === null) {
      return null;
    }

    const count = Number.parseInt(raw, 10);

    return Number.isFinite(count) && count > 0 ? count : null;
  }

  /**
   * The count is written straight onto the anchor when somebody else clicks the
   * link, by a realtime handler that changes the attribute and deliberately
   * does not redraw, because for an ordinary link the badge is CSS reading that
   * attribute. This card renders the number as text instead, so it has to ask
   * to be told.
   */
  oncreate(vnode: Mithril.VnodeDOM<LinkPreviewCardAttrs, this>) {
    super.oncreate(vnode);

    this.clicks = new MutationObserver(() => m.redraw());
    this.clicks.observe(this.attrs.link, { attributes: true, attributeFilter: ['data-clicks'] });
  }

  onremove(vnode: Mithril.VnodeDOM<LinkPreviewCardAttrs, this>) {
    super.onremove(vnode);

    this.clicks?.disconnect();
    this.clicks = null;
  }

  /**
   * The one anchor the whole card lives in. Issue #47 was two of these, so the
   * body was dead to the pointer and the title and the domain were separate
   * links to one address.
   *
   * The `href` is the anchor's own, whatever an extension made of it, so a
   * click still goes wherever the forum was configured to send it and is still
   * counted. Everything else is decided from the destination instead, because
   * a rewritten `href` says nothing about where the reader ends up.
   */
  protected anchorAttrs(modifiers: Array<string | false>, label?: string): Record<string, unknown> {
    const link = this.attrs.link;
    const href = link.getAttribute('href') ?? this.attrs.url;
    const internal = this.attrs.internal;
    const rel = new Set((link.getAttribute('rel') ?? '').split(/\s+/).filter(Boolean));

    if (!internal) {
      rel.add('noopener');
    }

    return {
      href,
      rel: rel.size ? Array.from(rel).join(' ') : undefined,
      // Core's routeInternalLinks() gives up on any target other than `_self`,
      // so `_blank` on an internal link tears the application down and boots it
      // again instead of routing.
      target: internal || !this.setting('openLinksInNewTab', true) ? '_self' : '_blank',
      className: classList('LinkPreview', modifiers, this.sourceClasses(internal && this.hrefReachesDestination(href))),
      'aria-label': label,
    };
  }

  /**
   * The classes the card may inherit from the anchor it stands in for. An
   * allowlist, because the card is a box of its own and most of what core and
   * other extensions put on an inline link means something else here:
   * `UrlLink--discussion` is `white-space: nowrap`, which flattens the two line
   * clamp on the title and the description, and `LinkPreview-source` is
   * `display: none`, which would hide the card itself.
   */
  protected sourceClasses(routable: boolean): string[] {
    const source = this.attrs.link.classList;
    const kept: string[] = [];

    // Only `UrlLink--internal`, which is the behavioural hook core's click
    // handler matches on. The bare `UrlLink` beside it is styled nowhere in
    // core and matched by nothing, so carrying it over would say nothing.
    if (routable && source.contains('UrlLink--internal')) kept.push('UrlLink', 'UrlLink--internal');

    return kept;
  }

  /**
   * Whether the anchor's own `href` still arrives where the card says it does.
   *
   * `UrlLink--internal` is the marker core's click handler routes on, and core
   * checks nothing but the origin before handing the path to the SPA router. A
   * tracking route is on this origin and is not a route the application has, so
   * the marker may only survive onto a card whose `href` really is the
   * destination. Core renders a discussion link with its slug and sometimes a
   * post number, while the resolved destination is the bare `/d/123`, so the
   * `href` extends that path rather than matching it.
   */
  protected hrefReachesDestination(href: string): boolean {
    let url: URL;
    let destination: URL;

    try {
      url = new URL(href, document.baseURI);
      destination = new URL(this.attrs.url, document.baseURI);
    } catch {
      return false;
    }

    if (url.origin !== destination.origin) return false;

    const path = url.pathname;
    const target = destination.pathname;

    return path === target || path.startsWith(`${target}-`) || path.startsWith(`${target}/`);
  }

  /**
   * What to print above the title. A page is free to set `og:site_name` to its
   * own title, which flarum.org does, and the card would then say the same
   * words twice over; the host is the one thing the title never repeats.
   */
  protected siteLabel(siteName: string, title: string, host: string): string {
    const name = siteName.trim().toLowerCase();
    const heading = title.trim().toLowerCase();

    if (name === '' || heading.startsWith(name) || heading.endsWith(name)) return host;

    return siteName;
  }

  protected layout(data: PreviewSuccess, hasImage: boolean): PreviewLayout {
    if (!hasImage) {
      return 'compact';
    }

    if (this.measuredLarge !== null) {
      return this.measuredLarge ? 'large' : 'compact';
    }

    return data.layout;
  }

  /**
   * The server can only judge an image it was told the size of. When the page
   * declared none, the browser has now measured the real thing, so the same
   * rule is applied again with real numbers.
   */
  protected remeasure(data: PreviewSuccess, image: HTMLImageElement): void {
    if (data.image?.width != null && data.image.height != null) {
      return;
    }

    const ratio = image.naturalHeight > 0 ? image.naturalWidth / image.naturalHeight : 0;
    // A server layout of `large` without dimensions can only have come from
    // `twitter:card`, which is the source stating its own preference.
    const large = data.layout === 'large' || (image.naturalWidth >= LARGE_MIN_WIDTH && ratio >= LARGE_MIN_RATIO && ratio <= LARGE_MAX_RATIO);

    if (this.measuredLarge === large) {
      return;
    }

    this.measuredLarge = large;
    m.redraw();
  }

  /**
   * Each source is tried in turn and each is remembered separately, because one
   * `onerror` handler serves both: a site whose own icon 404s should still fall
   * through to the service, which the single flag this replaces prevented.
   */
  protected faviconUrl(data: PreviewSuccess, host: string): string | null {
    if (data.favicon && !this.deadFavicons.has(data.favicon)) {
      return data.favicon;
    }

    // An internal card is answered from the database precisely so that reading
    // a post never reaches off the forum. Asking Google for the favicon of the
    // forum the reader is already on would give that away for nothing.
    if (this.attrs.internal || !this.setting('googleFaviconFallback', false)) {
      return null;
    }

    const google = `https://www.google.com/s2/favicons?sz=64&domain_url=${encodeURIComponent(host)}`;

    return this.deadFavicons.has(google) ? null : google;
  }

  protected setting(name: string, fallback: boolean): boolean {
    return app.forum.attribute<boolean | undefined>(`datlechin-link-preview.${name}`) ?? fallback;
  }

  /**
   * The site the card is describing.
   *
   * Defaults to the address that was linked, but a resolved preview passes the
   * effective URL the server ended up at, so a shortener names the destination
   * rather than itself.
   */
  protected host(url: string = this.attrs.url): string {
    try {
      return plain(new URL(url).host.replace(/^www\./, ''));
    } catch {
      return plain(url);
    }
  }
}
