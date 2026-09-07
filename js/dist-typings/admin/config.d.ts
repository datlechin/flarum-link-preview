import type Mithril from 'mithril';
export declare const EXTENSION = "datlechin-link-preview";
export declare const SETTING: {
    readonly openLinksInNewTab: "open_links_in_new_tab";
    readonly googleFaviconFallback: "google_favicon_fallback";
    readonly previewInternalLinks: "preview_internal_links";
    readonly skipMediaLinks: "skip_media_links";
    readonly maxPreviewsPerPost: "max_previews_per_post";
    readonly enableBatchRequests: "enable_batch_requests";
    readonly cacheTime: "cache_time";
    readonly allowlist: "allowlist";
    readonly blocklist: "blocklist";
};
export declare const NUMBER_BOUNDS: Record<string, {
    min: number;
    fallback: number;
}>;
export declare function settingKey(name: string): string;
export declare function trans(key: string): Mithril.Children;
export declare function transText(key: string): string;
