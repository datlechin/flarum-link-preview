import type Component from 'flarum/common/Component';
import type Mithril from 'mithril';
export type ContentBodies = (this: any, vnode: Mithril.VnodeDOM<any, any>) => Iterable<HTMLElement>;
export type WatchedComponent = string | {
    prototype: Component;
};
export default function watchContent(component: WatchedComponent, bodies: ContentBodies): void;
export declare function bodiesMatching(selector: string): ContentBodies;
export declare function topLevelElements(vnode: Mithril.VnodeDOM): HTMLElement[];
