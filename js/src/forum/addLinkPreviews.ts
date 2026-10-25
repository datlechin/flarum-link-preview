import app from 'flarum/forum/app';
import { extend } from 'flarum/common/extend';
import type Mithril from 'mithril';

import collectPreviewTargets from './utils/collectLinks';
import { mountCard, removeCardsWithin, sweepDetachedCards, unmountCardsWithin } from './utils/cardRegistry';

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

export default function addLinkPreviews(): void {
  extend('flarum/forum/components/CommentPost', ['oncreate', 'onupdate'], onPostRender);
  extend('flarum/forum/components/CommentPost', 'onremove', onPostRemove);
}

function previewsHidden(): boolean {
  return Boolean(app.session.user?.preferences()?.hideLinkPreviews);
}

function isBeingEdited(post: unknown, body: HTMLElement): boolean {
  if (body.querySelector('.Post-preview') !== null) return true;

  const isEditing = (post as { isEditing?: () => boolean }).isEditing;

  return typeof isEditing === 'function' && isEditing.call(post) === true;
}

function isConcealed(post: Element): boolean {
  return post.classList.contains('Post--hidden') && !post.classList.contains('revealContent');
}
