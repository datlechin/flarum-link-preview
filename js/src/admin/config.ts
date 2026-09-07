import app from 'flarum/admin/app';
import extractText from 'flarum/common/utils/extractText';
import type Mithril from 'mithril';

export const EXTENSION = 'datlechin-link-preview';

// Names shared with `locale/en.yml` and `Settings\Config::DEFAULTS`: storage
// keys, translation keys and admin search entries are all derived from these.
export const SETTING = {
  openLinksInNewTab: 'open_links_in_new_tab',
  googleFaviconFallback: 'google_favicon_fallback',
  previewInternalLinks: 'preview_internal_links',
  skipMediaLinks: 'skip_media_links',
  maxPreviewsPerPost: 'max_previews_per_post',
  enableBatchRequests: 'enable_batch_requests',
  cacheTime: 'cache_time',
  allowlist: 'allowlist',
  blocklist: 'blocklist',
} as const;

// Must match the clamping in `Settings\Config::previewLimit()` and
// `cacheSeconds()`, so the admin is shown the number that will take effect.
export const NUMBER_BOUNDS: Record<string, { min: number; fallback: number }> = {
  [SETTING.maxPreviewsPerPost]: { min: 1, fallback: 5 },
  [SETTING.cacheTime]: { min: 0, fallback: 60 },
};

export function settingKey(name: string): string {
  return `${EXTENSION}.${name}`;
}

export function trans(key: string): Mithril.Children {
  return app.translator.trans(`${EXTENSION}.admin.${key}`);
}

export function transText(key: string): string {
  return extractText(trans(key));
}
