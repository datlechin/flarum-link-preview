import app from 'flarum/forum/app';

const patterns = new Map<string, RegExp | null>();

export function parseList(value: string): string[] {
  return value
    .split(/[\n,]+/)
    .map((entry) => entry.trim())
    .filter((entry) => entry !== '');
}

export default function matchesList(url: string, entries: readonly string[]): boolean {
  if (entries.length === 0) return false;

  const subject = subjectOf(url);

  if (subject === null) return false;

  return entries.some((entry) => {
    const pattern = patternFor(entry);

    return pattern !== null && pattern.test(subject);
  });
}

export function allowsUrl(url: string): boolean {
  const allowlist = parseList(app.forum.attribute<string | undefined>('datlechin-link-preview.allowlist') ?? '');
  const blocklist = parseList(app.forum.attribute<string | undefined>('datlechin-link-preview.blocklist') ?? '');

  if (allowlist.length > 0 && !matchesList(url, allowlist)) return false;

  return !matchesList(url, blocklist);
}

function subjectOf(url: string): string | null {
  let parsed: URL;

  try {
    parsed = new URL(url);
  } catch {
    return null;
  }

  const host = parsed.hostname
    .toLowerCase()
    .replace(/\.$/, '')
    .replace(/^www\./, '');

  return host + parsed.pathname.replace(/\/$/, '') + parsed.search;
}

function normalise(entry: string): string {
  const value = entry.trim();
  const authority = /^[a-z][a-z0-9+.-]*:\/\//i.test(value) ? value : `https://${value.replace(/^\/+/, '')}`;

  let parsed: URL;

  try {
    parsed = new URL(authority);
  } catch {
    return '';
  }

  const host = parsed.hostname
    .toLowerCase()
    .replace(/\.$/, '')
    .replace(/^www\./, '');

  return host + parsed.pathname.toLowerCase().replace(/\/+$/, '');
}

function patternFor(entry: string): RegExp | null {
  if (patterns.has(entry)) return patterns.get(entry) ?? null;

  const pattern = compile(entry);

  patterns.set(entry, pattern);

  return pattern;
}

function compile(entry: string): RegExp | null {
  const normalised = normalise(entry);

  if (normalised === '') return null;

  const slash = normalised.indexOf('/');
  const host = slash === -1 ? normalised : normalised.slice(0, slash);
  const path = slash === -1 ? null : normalised.slice(slash);

  const hostSource = escape(host).replace(/\\\*/g, '[^.]*');

  const source = path === null ? `^(?:[^/?]+\\.)?${hostSource}(?:[/?].*)?$` : `^${hostSource}${escape(path).replace(/\\\*/g, '.*')}(?:[/?].*)?$`;

  return new RegExp(source, 'i');
}

function escape(value: string): string {
  return value.replace(/[.*+?^${}()|[\]\\]/g, '\\$&');
}
