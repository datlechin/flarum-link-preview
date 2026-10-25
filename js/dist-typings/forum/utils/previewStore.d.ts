import type { PreviewState } from '../../common/types';
export declare function previewFor(url: string): PreviewState;
export declare function loadPreview(url: string, onChange: () => void): void;
export declare function retainPreview(url: string): void;
export declare function releasePreview(url: string): void;
