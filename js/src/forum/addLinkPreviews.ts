import app from 'flarum/forum/app';
import { extend } from 'flarum/common/extend';
import type Mithril from 'mithril';

import collectPreviewTargets from './utils/collectLinks';
import { mountCard, removeCardsWithin, sweepDetachedCards, unmountCardsWithin } from './utils/cardRegistry';

/**
 * `extend()` passes the extended method's return value first and its arguments
 * after, so the vnode arrives second. Cast because naming a lazy loaded module
 * by path leaves `extend()` nothing to infer the component's types from.
 */
const onPostRender = function (this: unknown, _: unknown, ...args: unknown[]): void {
  sweepDetachedCards();

  const vnode = args[0] as Mithril.VnodeDOM;
  const body = vnode.dom.querySelector<HTMLElement>('.Post-body');

  if (!body) return;

  if (previewsHidden()) {
    removeCardsWithin(body);
    return;
  }

  if (isBeingEdited(this, body)) return;

  if (isConcealed(vnode.dom)) return;

  collectPreviewTargets(body).forEach(mountCard);
};

const onPostRemove = function (this: unknown, _: unknown, ...args: unknown[]): void {
  const vnode = args[0] as Mithril.VnodeDOM;

  unmountCardsWithin(vnode.dom);
};

/**
 * Put a card under every bare address in a post.
 *
 * `CommentPost` is code split in Flarum 2, so it is named by module path rather
 * than imported. Importing it would bundle a second copy and patch the one the
 * page never loads.
 */
export default function addLinkPreviews(): void {
  extend('flarum/forum/components/CommentPost', ['oncreate', 'onupdate'], onPostRender);
  extend('flarum/forum/components/CommentPost', 'onremove', onPostRemove);
}

function previewsHidden(): boolean {
  return Boolean(app.session.user?.preferences()?.hideLinkPreviews);
}

/**
 * A post whose body is the composer's live preview rather than saved content.
 *
 * `ComposerPostPreview` rewrites its own subtree from the raw text every 50ms,
 * by a diff that knows nothing about anything mounted into it, so a card put
 * there is torn out from under Mithril within the next tick.
 */
function isBeingEdited(post: unknown, body: HTMLElement): boolean {
  if (body.querySelector('.Post-preview') !== null) return true;

  const isEditing = (post as { isEditing?: () => boolean }).isEditing;

  return typeof isEditing === 'function' && isEditing.call(post) === true;
}

/**
 * A deleted post the reader has not opened.
 *
 * Core renders the content anyway and only collapses it with CSS, so the links
 * are all there to be found. Revealing the post redraws it and the cards are
 * made then, rather than spending a request each behind `display: none`.
 */
function isConcealed(post: Element): boolean {
  return post.classList.contains('Post--hidden') && !post.classList.contains('revealContent');
}
