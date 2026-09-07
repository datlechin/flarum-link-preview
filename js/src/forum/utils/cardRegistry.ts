import LinkPreviewCard from '../components/LinkPreviewCard';
import type { PreviewTarget } from './collectLinks';
import { loadPreview, previewFor, releasePreview, retainPreview } from './previewStore';

/**
 * Every card on the page, and the undoing of it.
 *
 * A card is a Mithril root mounted into post HTML Mithril does not own.
 * `m.mount` subscribes the target and never unsubscribes it, so a discarded
 * root is redrawn for the life of the tab, and one edited character replaces
 * the whole `.Post-body` subtree, core rendering it from `m.trust(contentHtml)`
 * which Mithril diffs by string compare. Hence three defences: the sweep, the
 * removal hook, and `m.mount(wrapper, null)` in both, the only call that
 * actually cancels the subscription.
 */

/** What has to be put back if the card is taken away again. */
interface Card {
  link: HTMLAnchorElement;
  url: string;
}

const mounted = new Map<HTMLElement, Card>();

export function mountCard(target: PreviewTarget): void {
  const { link, url, internal, block, mode } = target;

  // Marked first, so a redraw landing mid-mount cannot collect this anchor
  // again. `collectPreviewTargets` skips anchors carrying the attribute, which
  // is what keeps the pass idempotent across `onupdate`.
  link.setAttribute('data-link-preview', '');

  const wrapper = document.createElement('span');

  // A `<span>` because the wrapper often lands inside a `<p>`, where a `<div>`
  // makes the browser close the paragraph. The stylesheet gives it `display: block`.
  wrapper.className = 'LinkPreview-container';
  wrapper.setAttribute('data-link-preview', '');

  // Always inside a block of the post, never a sibling of a top level child of
  // `.Post-body`: Mithril removes exactly as many top level nodes as
  // `m.trust(contentHtml)` produced, so one extra there and it removes the
  // wrong ones, stranding a mounted root in a detached subtree the
  // `isConnected` sweep can never see.
  if (mode === 'replace') {
    link.classList.add('LinkPreview-source');
    link.before(wrapper);
  } else {
    block.append(wrapper);
  }

  mounted.set(wrapper, { link, url });

  // Held while the card is on screen so the store cannot evict the answer it is
  // drawn from and strand it in a skeleton.
  retainPreview(url);

  m.mount(wrapper, { view: () => m(LinkPreviewCard, { link, url, internal }) });

  // Asked for here as well as in the card, which can redraw itself but cannot
  // take away the root it is mounted in. The load comes first so that the state
  // read below is one the store has settled on rather than a stale failure it
  // is already refetching.
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

/** Restores the plain links, so turning previews off does not need a reload. */
export function removeCardsWithin(root: ParentNode): void {
  root.querySelectorAll<HTMLElement>('.LinkPreview-container').forEach((wrapper) => {
    takeDown(wrapper)?.link.removeAttribute('data-link-preview');
  });
}

/** A preview that failed shows nothing, so the link is given back as it was. */
function dropFailed(wrapper: HTMLElement, url: string): void {
  if (previewFor(url).status !== 'failed') return;

  // The anchor keeps its `data-link-preview`. That mark is what stops
  // `collectPreviewTargets` picking it up on the next `onupdate`, mounting a
  // card against the remembered failure, and tearing it down again forever.
  takeDown(wrapper);
}

/**
 * Takes a card away and puts the anchor back on screen, leaving the caller to
 * say whether the anchor may be collected again.
 */
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
