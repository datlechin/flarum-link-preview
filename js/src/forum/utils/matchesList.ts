import app from 'flarum/forum/app';

/**
 * Mirrors `Datlechin\LinkPreview\Preview\UrlFilter` and has to stay that way. The server
 * still wins where the two disagree, so a mismatch costs a request rather than a preview.
 */

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

/**
 * What both sides of a comparison are reduced to: host, path and query, with no scheme,
 * no leading `www.`, no trailing slash and no trailing dot on the root label. The
 * fragment goes too, so `#section` cannot decide whether a link is blocked.
 */
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

/**
 * Parsed rather than trimmed: `example.com:8443`, `someone@example.com` and `example.com.`
 * all name the host an administrator meant, and `parse_url` reads them that way server side.
 */
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

  // Inside the host `*` stops at a dot, so `*.example.com` cannot reach past one
  // label and match `example.com.attacker.net`. In a path it runs freely.
  const hostSource = escape(host).replace(/\\\*/g, '[^.]*');

  // A host entry covers subdomains; a path entry does not, it already names the address it means.
  const source = path === null ? `^(?:[^/?]+\\.)?${hostSource}(?:[/?].*)?$` : `^${hostSource}${escape(path).replace(/\\\*/g, '.*')}(?:[/?].*)?$`;

  // Case insensitive for the path; the host is already lowercased on both sides.
  return new RegExp(source, 'i');
}

function escape(value: string): string {
  return value.replace(/[.*+?^${}()|[\]\\]/g, '\\$&');
}
