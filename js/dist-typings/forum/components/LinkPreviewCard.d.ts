import Component from 'flarum/common/Component';
import type { ComponentAttrs } from 'flarum/common/Component';
import ItemList from 'flarum/common/utils/ItemList';
import type Mithril from 'mithril';
import type { MetaItem, PreviewLayout, PreviewSuccess } from '../../common/types';
export interface LinkPreviewCardAttrs extends ComponentAttrs {
    link: HTMLAnchorElement;
    /**
     * Not always the anchor's `href`, which a click tracker rewrites to a route of
     * its own. Everything but the `href` is decided from this.
     */
    url: string;
    internal: boolean;
}
export default class LinkPreviewCard extends Component<LinkPreviewCardAttrs> {
    protected imageFailed: boolean;
    protected deadFavicons: Set<string>;
    protected measuredLarge: boolean | null;
    protected clicks: MutationObserver | null;
    oninit(vnode: Mithril.Vnode<LinkPreviewCardAttrs, this>): void;
    view(): Mithril.Children;
    protected viewLoading(): Mithril.Children;
    protected viewReady(data: PreviewSuccess): Mithril.Children;
    protected infoItems(data: PreviewSuccess, siteName: string, host: string): ItemList<Mithril.Children>;
    protected viewMeta(item: MetaItem): Mithril.Children;
    protected viewFavicon(data: PreviewSuccess, host: string): Mithril.Children;
    protected clickCount(): number | null;
    oncreate(vnode: Mithril.VnodeDOM<LinkPreviewCardAttrs, this>): void;
    onremove(vnode: Mithril.VnodeDOM<LinkPreviewCardAttrs, this>): void;
    protected anchorAttrs(modifiers: Array<string | false>, label?: string): Record<string, unknown>;
    protected routeSamePage(event: MouseEvent): void;
    protected sourceClasses(routable: boolean): string[];
    protected hrefReachesDestination(href: string): boolean;
    protected siteLabel(siteName: string, title: string, host: string): string;
    protected layout(data: PreviewSuccess, hasImage: boolean): PreviewLayout;
    protected remeasure(data: PreviewSuccess, image: HTMLImageElement): void;
    protected faviconUrl(data: PreviewSuccess, host: string): string | null;
    protected setting(name: string, fallback: boolean): boolean;
    /**
     * Callers pass the resolved `data.url` so a shortener names where it lands.
     * The default is only for the states that have no answer to read it from.
     */
    protected host(url?: string): string;
}
