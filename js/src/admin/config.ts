import app from 'flarum/admin/app';
import extractText from 'flarum/common/utils/extractText';
import type Mithril from 'mithril';

export const EXTENSION = 'datlechin-link-preview';

/**
 * The settings this extension owns, under the names they carry in
 * `locale/en.yml` and in `Settings\Config::DEFAULTS`.
 *
 * The storage key, both translation keys and the admin search entries are all
 * derived from these, so a setting cannot be renamed on the screen and left
 * under its old name in the search box or the reset list.
 */
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

/**
 * The floor the server raises a stored number to, and what it reads instead
 * when the row is not a number at all.
 *
 * `Settings\Config::previewLimit()` and `Config::cacheSeconds()` clamp on every
 * read, so a field left empty already runs on these numbers. They are repeated
 * here so the admin is shown the number that will take effect rather than the
 * blank that will not.
 */
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
