import type Comment from 'flarum/forum/components/Comment';
import type Mithril from 'mithril';

import { topLevelElements } from './watchContent';

export default function commentBodies(this: Comment, vnode: Mithril.VnodeDOM<any, Comment>): HTMLElement[] {
  if (this.attrs.isEditing) return [];

  const body = topLevelElements(vnode).find((element) => element.classList.contains('Post-body'));

  if (!body || isConcealed(body)) return [];

  return [body];
}

function isConcealed(body: HTMLElement): boolean {
  return body.closest('.Post--hidden:not(.revealContent)') !== null;
}
