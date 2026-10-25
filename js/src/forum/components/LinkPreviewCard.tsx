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

const LARGE_MIN_WIDTH = 600;
const LARGE_MIN_RATIO = 1.2;
const LARGE_MAX_RATIO = 3;

function plain(text: string | null): string {
  return (text ?? '').replace(/[\u202A-\u202E\u2066-\u2069]/g, '');
}

export interface LinkPreviewCardAttrs extends ComponentAttrs {
  link: HTMLAnchorElement;
  url: string;
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
    const siteName = heading === '' ? host : this.siteLabel(plain(data.siteName), heading, host);
    const description = plain(data.description);

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

  protected infoItems(data: PreviewSuccess, siteName: string, host: string): ItemList<Mithril.Children> {
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

    if (clicks !== null) {
      items.add('clicks', <span>{app.translator.trans('datlechin-link-preview.forum.clicks', { count: clicks })}</span>, 95);
    }

    data.meta?.forEach((item, index) => {
      items.add(`meta${index}`, this.viewMeta(item), 90 - index);
    });

    return items;
  }

  protected viewMeta(item: MetaItem): Mithril.Children {
    if ('text' in item) {
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

  protected clickCount(): number | null {
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

  oncreate(vnode: Mithril.VnodeDOM<LinkPreviewCardAttrs, this>) {
    super.oncreate(vnode);

    if (!this.attrs.link.classList.contains('LinkPreview-source')) return;

    this.clicks = new MutationObserver(() => m.redraw());
    this.clicks.observe(this.attrs.link, { attributes: true, attributeFilter: ['data-clicks'] });
  }

  onremove(vnode: Mithril.VnodeDOM<LinkPreviewCardAttrs, this>) {
    super.onremove(vnode);

    this.clicks?.disconnect();
    this.clicks = null;
  }

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
      target: internal || !this.setting('openLinksInNewTab', true) ? '_self' : '_blank',
      className: classList('LinkPreview', modifiers, this.sourceClasses(internal && this.hrefReachesDestination(href))),
      'aria-label': label,
      onclick: (event: MouseEvent) => this.routeSamePage(event),
    };
  }

  protected routeSamePage(event: MouseEvent): void {
    if (event.button !== 0 || event.ctrlKey || event.metaKey || event.shiftKey || event.altKey || event.defaultPrevented) {
      return;
    }

    const anchor = event.currentTarget as HTMLAnchorElement;

    if (anchor.href !== window.location.href) return;

    event.preventDefault();

    m.route.set(anchor.pathname + anchor.search + anchor.hash);
  }

  protected sourceClasses(routable: boolean): string[] {
    const source = this.attrs.link.classList;
    const kept: string[] = [];

    if (routable && source.contains('UrlLink--internal')) kept.push('UrlLink', 'UrlLink--internal');

    return kept;
  }

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

  protected remeasure(data: PreviewSuccess, image: HTMLImageElement): void {
    if (data.image?.width != null && data.image.height != null) {
      return;
    }

    const ratio = image.naturalHeight > 0 ? image.naturalWidth / image.naturalHeight : 0;
    const large = data.layout === 'large' || (image.naturalWidth >= LARGE_MIN_WIDTH && ratio >= LARGE_MIN_RATIO && ratio <= LARGE_MAX_RATIO);

    if (this.measuredLarge === large) {
      return;
    }

    this.measuredLarge = large;
    m.redraw();
  }

  protected faviconUrl(data: PreviewSuccess, host: string): string | null {
    if (data.favicon && !this.deadFavicons.has(data.favicon)) {
      return data.favicon;
    }

    if (this.attrs.internal || !this.setting('googleFaviconFallback', false)) {
      return null;
    }

    const google = `https://www.google.com/s2/favicons?sz=64&domain_url=${encodeURIComponent(host)}`;

    return this.deadFavicons.has(google) ? null : google;
  }

  protected setting(name: string, fallback: boolean): boolean {
    return app.forum.attribute<boolean | undefined>(`datlechin-link-preview.${name}`) ?? fallback;
  }

  protected host(url: string = this.attrs.url): string {
    try {
      return plain(new URL(url).host.replace(/^www\./, ''));
    } catch {
      return plain(url);
    }
  }
}
