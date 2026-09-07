import LinkPreviewCard from '../components/LinkPreviewCard';
import type { PreviewTarget } from './collectLinks';
import { loadPreview, previewFor, releasePreview, retainPreview } from './previewStore';

/**
 * `m.mount` subscribes its target and never unsubscribes it, so a discarded root is
 * redrawn for the life of the tab, and one edited character replaces the whole
 * `.Post-body` subtree, which core renders from `m.trust(contentHtml)`. Hence the
 * sweep, the removal hook, and `m.mount(wrapper, null)`, the only call that cancels.
 */

interface Card {
  link: HTMLAnchorElement;
  url: string;
}

const mounted = new Map<HTMLElement, Card>();

export function mountCard(target: PreviewTarget): void {
  const { link, url, internal, block, mode } = target;

  // Marked first, so a redraw landing mid-mount cannot collect this anchor again.
  link.setAttribute('data-link-preview', '');

  const wrapper = document.createElement('span');

  // A `<span>` because the wrapper often lands inside a `<p>`, where a `<div>` makes
  // the browser close the paragraph. The stylesheet gives it `display: block`.
  wrapper.className = 'LinkPreview-container';
  wrapper.setAttribute('data-link-preview', '');

  // Never a sibling of a top level child of `.Post-body`: Mithril removes exactly as many
  // top level nodes as `m.trust(contentHtml)` produced, and one extra makes it remove the wrong ones.
  if (mode === 'replace') {
    link.classList.add('LinkPreview-source');
    link.before(wrapper);
  } else {
    block.append(wrapper);
  }

  mounted.set(wrapper, { link, url });

  retainPreview(url);

  m.mount(wrapper, { view: () => m(LinkPreviewCard, { link, url, internal }) });

  // The card can redraw itself but cannot take away the root it is mounted in.
  // Subscribed first, then asked once, because a preview already in the store
  // settles before the callback is ever registered.
  loadPreview(url, () => dropFailed(wrapper, url));
  dropFailed(wrapper, url);
}

export function sweepDetachedCards(): void {
  for (const wrapper of Array.from(mounted.keys())) {
    if (!wrapper.isConnected) unmount(wrapper);
  }
}

/** Called while the post's DOM is still attached, so the wrappers can be found. */
export function unmountCardsWithin(root: ParentNode): void {
  root.querySelectorAll<HTMLElement>('.LinkPreview-container').forEach(unmount);
}

/** Unlike `unmountCardsWithin`, gives the anchors back for collecting, so previews can be turned off live. */
export function removeCardsWithin(root: ParentNode): void {
  root.querySelectorAll<HTMLElement>('.LinkPreview-container').forEach((wrapper) => {
    takeDown(wrapper)?.link.removeAttribute('data-link-preview');
  });
}

function dropFailed(wrapper: HTMLElement, url: string): void {
  if (previewFor(url).status !== 'failed') return;

  // The anchor keeps its `data-link-preview`, which is what stops the next `onupdate`
  // mounting a card against the remembered failure and tearing it down again forever.
  takeDown(wrapper);
}

function takeDown(wrapper: HTMLElement): Card | undefined {
  const card = mounted.get(wrapper);

  unmount(wrapper);
  wrapper.remove();

  card?.link.classList.remove('LinkPreview-source');

  return card;
}

function unmount(wrapper: HTMLElement): void {
  const card = mounted.get(wrapper);

  if (!card) return;

  mounted.delete(wrapper);
  releasePreview(card.url);

  m.mount(wrapper, null);
}
