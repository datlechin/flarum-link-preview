import app from 'flarum/forum/app';
import { extend } from 'flarum/common/extend';
import type Component from 'flarum/common/Component';
import type Mithril from 'mithril';

import collectPreviewTargets from './collectLinks';
import { mountCard, removeCardsWithin, sweepDetachedCards, unmountCardsWithin } from './cardRegistry';

export type ContentBodies = (this: any, vnode: Mithril.VnodeDOM<any, any>) => Iterable<HTMLElement>;

export type WatchedComponent = string | { prototype: Component };

export default function watchContent(component: WatchedComponent, bodies: ContentBodies): void {
  const target = typeof component === 'string' ? component : component.prototype;

  extend(target as any, ['oncreate', 'onupdate'], function (this: Component, _: unknown, ...args: unknown[]) {
    sweepDetachedCards();

    for (const body of bodies.call(this, args[0] as Mithril.VnodeDOM<any, Component>)) {
      if (previewsHidden()) {
        removeCardsWithin(body);
      } else {
        collectPreviewTargets(body).forEach(mountCard);
      }
    }
  });

  extend(target as any, 'onremove', function (_: unknown, ...args: unknown[]) {
    topLevelElements(args[0] as Mithril.VnodeDOM).forEach(unmountCardsWithin);
  });
}

export function bodiesMatching(selector: string): ContentBodies {
  return function (vnode) {
    return topLevelElements(vnode).flatMap((element) => [
      ...(element.matches(selector) ? [element] : []),
      ...Array.from(element.querySelectorAll<HTMLElement>(selector)),
    ]);
  };
}

export function topLevelElements(vnode: Mithril.VnodeDOM): HTMLElement[] {
  const elements: HTMLElement[] = [];
  let node: Node | null = vnode.dom;

  for (let i = 0; node && i < (vnode.domSize ?? 1); i++, node = node.nextSibling) {
    if (node instanceof HTMLElement) elements.push(node);
  }

  return elements;
}

function previewsHidden(): boolean {
  return Boolean(app.session.user?.preferences()?.hideLinkPreviews);
}
