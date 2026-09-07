import type { PreviewState } from '../../common/types';
export declare function previewFor(url: string): PreviewState;
/** `onChange` fires once, when this URL settles either way; the answer is then read with `previewFor`. */
export declare function loadPreview(url: string, onChange: () => void): void;
/** Cards only ask once, when created, so an entry evicted under a mounted card leaves it a skeleton forever. */
export declare function retainPreview(url: string): void;
export declare function releasePreview(url: string): void;
