export { default as extend } from './extend';

// Exported so another extension can reach the section list rather than having
// to replace the whole page to add a setting to it.
export { default as LinkPreviewSettingsPage } from './components/LinkPreviewSettingsPage';
export * from './config';
