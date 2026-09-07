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
import type { MetaItem, PreviewLayout, PreviewSuccess } from '../../common/types';
import { loadPreview, previewFor } from '../utils/previewStore';

// Must match `Metadata::layout()`, which picks `large` from these same numbers.
// Different values here render one image two ways depending on whether its page
// declared dimensions.
const LARGE_MIN_WIDTH = 600;
const LARGE_MIN_RATIO = 1.2;
const LARGE_MAX_RATIO = 3;

/**
 * Strips bidi overrides from remote text. A title carrying one reorders
 * everything after it, so a card can be made to read as one address while
 * linking to another: Trojan Source pointed at a link preview.
 */
function plain(text: string | null): string {
  return (text ?? '').replace(/[\u202A-\u202E\u2066-\u2069]/g, '');
}

export interface LinkPreviewCardAttrs extends ComponentAttrs {
  /** The anchor the card stands in for. Its `href` is the card's `href`. */
  link: HTMLAnchorElement;
  /**
   * Where the link actually leads, which is not always the anchor's `href`: a
   * click tracker rewrites that to a route of its own. Everything but the
   * `href` is decided from this.
   */
  url: string;
  /** Whether `url` is an address this forum serves. */
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

    if (state.status === 'ready') {
      return this.viewReady(state.data);
    }

    // A failed preview leaves the link as it was, and `cardRegistry` takes the
    // card down as the store settles. This is the frame in between.
    return state.status === 'loading' ? this.viewLoading() : null;
  }

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

  protected viewReady(data: PreviewSuccess): Mithril.Children {
    const image = this.imageFailed ? null : data.image;
    const layout = this.layout(data, image !== null);
    const host = this.host(data.url);
    const heading = plain(data.title);
    // Without a title the card already shows the host as its title, and the
    // site line above would repeat it.
    const siteName = heading === '' ? host : this.siteLabel(plain(data.siteName), heading, host);
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
   * description. An `ItemList` so another extension can add to the row.
   */
  protected infoItems(data: PreviewSuccess, siteName: string, host: string): ItemList<Mithril.Children> {
    // Every item is a vnode. `ItemList.toArray()` boxes a primitive so it can
    // hang `itemName` off it, `listItems` puts that box in the `<li>`, and
    // Mithril takes the object for a component and reads `.view` off undefined.
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

    // Beside the site, ahead of the items that belong to the page rather than
    // to the link.
    if (clicks !== null) {
      items.add('clicks', <span>{app.translator.trans('datlechin-link-preview.forum.clicks', { count: clicks })}</span>, 95);
    }

    // Descending priorities, so the order the server sent survives the sort.
    data.meta?.forEach((item, index) => {
      items.add(`meta${index}`, this.viewMeta(item), 90 - index);
    });

    return items;
  }

  /** Whatever the server had to say about this page, in the shape it said it. */
  protected viewMeta(item: MetaItem): Mithril.Children {
    if ('text' in item) {
      // `username` is core's class for a name, and what the stylesheet weights.
      return <span className={item.key === 'author' ? 'username' : undefined}>{plain(item.text)}</span>;
    }

    if ('count' in item) {
      return <span>{app.translator.trans(`datlechin-link-preview.forum.meta.${item.key}`, { count: item.count })}</span>;
    }

    return humanTime(new Date(item.date));
  }

  protected viewFavicon(data: PreviewSuccess, host: string): Mithril.Children {
    const src = this.faviconUrl(data, host);

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
   * Read off the anchor rather than fetched: the click-counting extension
   * leaves `data-clicks` off entirely below the forum's `min_display_count`,
   * so its absence is already a decision not to show a number.
   */
  protected clickCount(): number | null {
    // A link still on screen beside the card already wears the count as a
    // badge, and two renderings of it read as two numbers that happen to agree.
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
   * The realtime handler writes `data-clicks` onto the anchor without
   * redrawing, because an ordinary link's badge is CSS reading that attribute.
   * This card renders the number as text, so it has to watch for the change.
   */
  oncreate(vnode: Mithril.VnodeDOM<LinkPreviewCardAttrs, this>) {
    super.oncreate(vnode);

    // Only where the count can appear. On an after-mode card the anchor is
    // still on screen wearing its own badge, `clickCount()` returns null, and
    // an observer here would spend a global redraw changing nothing.
    if (!this.attrs.link.classList.contains('LinkPreview-source')) return;

    this.clicks = new MutationObserver(() => m.redraw());
    this.clicks.observe(this.attrs.link, { attributes: true, attributeFilter: ['data-clicks'] });
  }

  onremove(vnode: Mithril.VnodeDOM<LinkPreviewCardAttrs, this>) {
    super.onremove(vnode);

    this.clicks?.disconnect();
    this.clicks = null;
  }

  /**
   * The one anchor the whole card lives in.
   *
   * The `href` stays the anchor's own, whatever an extension made of it, so a
   * click is still routed and counted the way the forum was configured to.
   * Everything else is decided from the destination, because a rewritten
   * `href` says nothing about where the reader ends up.
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
      // so `_blank` on an internal link reboots the application instead of
      // routing.
      target: internal || !this.setting('openLinksInNewTab', true) ? '_self' : '_blank',
      className: classList('LinkPreview', modifiers, this.sourceClasses(internal && this.hrefReachesDestination(href))),
      'aria-label': label,
      onclick: (event: MouseEvent) => this.routeSamePage(event),
    };
  }

  /**
   * Route a click on a card that points at the address already open.
   *
   * Core's `routeInternalLinks()` returns early on a link whose address is
   * byte-identical to the current one, so that an anchor jump on the page still
   * works, and the browser then reloads the whole application to arrive where
   * the reader already is. A card carries no fragment, so there is nothing for
   * the browser to jump to.
   *
   * Routing anyway is what the reader asked for, and core does the rest:
   * `DiscussionPageResolver.onmatch` notices the destination is the discussion
   * already open and scrolls the stream to the post the address names, or to
   * the first one.
   */
  protected routeSamePage(event: MouseEvent): void {
    // Anything but a plain left click is the reader asking for a new tab or a
    // menu, and the browser does those better.
    if (event.button !== 0 || event.ctrlKey || event.metaKey || event.shiftKey || event.altKey || event.defaultPrevented) {
      return;
    }

    const anchor = event.currentTarget as HTMLAnchorElement;

    if (anchor.href !== window.location.href) return;

    event.preventDefault();

    m.route.set(anchor.pathname + anchor.search + anchor.hash);
  }

  /**
   * The classes the card may inherit from the anchor it stands in for. An
   * allowlist, because what styles an inline link means something else on a
   * box: `UrlLink--discussion` is `white-space: nowrap`, which flattens the
   * line clamps, and `LinkPreview-source` is `display: none`, which would hide
   * the card itself.
   */
  protected sourceClasses(routable: boolean): string[] {
    const source = this.attrs.link.classList;
    const kept: string[] = [];

    // `UrlLink--internal` is the hook core's click handler matches on.
    if (routable && source.contains('UrlLink--internal')) kept.push('UrlLink', 'UrlLink--internal');

    return kept;
  }

  /**
   * Whether the anchor's own `href` still arrives where the card says it does.
   *
   * Core checks nothing but the origin before handing the path to the SPA
   * router, and a tracking route is on this origin without being a route the
   * application has, so `UrlLink--internal` may only survive onto a card whose
   * `href` really is the destination. Core renders a discussion link with its
   * slug and often a post number, while the resolved destination is the bare
   * `/d/123`, so the `href` extends that path rather than matching it.
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
   * own title, which flarum.org does, so fall back to the host, which is the
   * one thing the title never repeats.
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
   * The server can only judge an image whose size the page declared. Where it
   * did not, the browser has now measured the real thing.
   */
  protected remeasure(data: PreviewSuccess, image: HTMLImageElement): void {
    if (data.image?.width != null && data.image.height != null) {
      return;
    }

    const ratio = image.naturalHeight > 0 ? image.naturalWidth / image.naturalHeight : 0;
    // A server layout of `large` without dimensions can only have come from
    // `twitter:card`, which is the page stating its own preference.
    const large = data.layout === 'large' || (image.naturalWidth >= LARGE_MIN_WIDTH && ratio >= LARGE_MIN_RATIO && ratio <= LARGE_MAX_RATIO);

    if (this.measuredLarge === large) {
      return;
    }

    this.measuredLarge = large;
    m.redraw();
  }

  /**
   * Each source is remembered separately, because one `onerror` handler serves
   * both: a site whose own icon 404s must still fall through to the service.
   */
  protected faviconUrl(data: PreviewSuccess, host: string): string | null {
    if (data.favicon && !this.deadFavicons.has(data.favicon)) {
      return data.favicon;
    }

    // An internal card is answered from the database so that reading a post
    // never reaches off the forum. Asking Google for this forum's own favicon
    // would give that away for nothing.
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
   * The site the card is describing. Callers pass the effective URL the server
   * ended up at where they have one, so a shortener names its destination
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
