import app from 'flarum/forum/app';

/**
 * The allowlist and the blocklist, applied in the browser.
 *
 * Both lists are already in the forum payload, so a link an administrator has
 * ruled out can be dropped before it is ever collected. That is the whole
 * point: without this the browser asks the server about a URL the server is
 * only going to refuse, once per reader, and the refusal is not free either.
 *
 * The rules are the ones `Datlechin\LinkPreview\Preview\UrlFilter` applies, and
 * have to stay that way. Where the two disagree the server still wins, so a
 * mismatch costs a wasted request rather than a preview that should not exist.
 */

const patterns = new Map<string, RegExp | null>();

/**
 * Split a setting into entries. Administrators write these lists one per line
 * or comma separated, and both have always been accepted.
 */
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

/**
 * Whether this URL is worth asking the server about.
 */
export function allowsUrl(url: string): boolean {
  const allowlist = parseList(app.forum.attribute<string | undefined>('datlechin-link-preview.allowlist') ?? '');
  const blocklist = parseList(app.forum.attribute<string | undefined>('datlechin-link-preview.blocklist') ?? '');

  if (allowlist.length > 0 && !matchesList(url, allowlist)) return false;

  return !matchesList(url, blocklist);
}

/**
 * What both sides of a comparison are reduced to: host, path and query, with
 * no scheme, no leading `www.` and no trailing slash.
 *
 * `hostname` leaves out the userinfo and the port, which are no part of naming
 * a site, and the root label's trailing dot goes the same way: `example.com.`
 * and `example.com` are one host and an entry naming either has to catch both.
 * The fragment never reaches the server and is dropped here as well, so that
 * `#section` cannot decide whether a link is blocked.
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

function normalise(entry: string): string {
  return entry
    .trim()
    .toLowerCase()
    .replace(/^[a-z][a-z0-9+.-]*:\/\//, '')
    .replace(/^www\./, '')
    .replace(/\/+$/, '');
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

  // Inside the host `*` stops at a dot, so `*.example.com` cannot reach past
  // one label and match `example.com.attacker.net`. Inside a path it runs
  // freely, because a path has no such boundary to respect.
  const hostSource = escape(host).replace(/\\\*/g, '[^.]*');

  // An entry naming a host covers its subdomains. An entry naming a path does
  // not, because it is already saying which address it means.
  const source = path === null ? `^(?:[^/?]+\\.)?${hostSource}(?:[/?].*)?$` : `^${hostSource}${escape(path).replace(/\\\*/g, '.*')}(?:[/?].*)?$`;

  // Case insensitive, because a path is compared against an entry an
  // administrator wrote in whatever case they felt like, and `/Downloads` and
  // `/downloads` are the same page far more often than they are two. The host
  // is already lowercased on both sides.
  return new RegExp(source, 'i');
}

function escape(value: string): string {
  return value.replace(/[.*+?^${}()|[\]\\]/g, '\\$&');
}
