import type { PreviewTarget } from './collectLinks';
export declare function mountCard(target: PreviewTarget): void;
export declare function sweepDetachedCards(): void;
/** Called while the post's DOM is still attached, so the wrappers can be found. */
export declare function unmountCardsWithin(root: ParentNode): void;
/** Unlike `unmountCardsWithin`, gives the anchors back for collecting, so previews can be turned off live. */
export declare function removeCardsWithin(root: ParentNode): void;
