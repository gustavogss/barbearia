import { mkdir, rm, writeFile } from 'node:fs/promises';
import path from 'node:path';

const sourceOrigin = normalizeOrigin(process.env.WP_SITE_URL || 'http://127.0.0.1:10003');
const sourceHostHeader = process.env.WP_HOST_HEADER || 'barbearia.local';
const publicOrigin = process.env.PUBLIC_SITE_URL ? normalizeOrigin(process.env.PUBLIC_SITE_URL) : '';
const outDir = path.resolve(process.env.OUT_DIR || 'dist');
const maxPages = Number.parseInt(process.env.MAX_PAGES || '250', 10);

const pageQueue = ['/'];
const crawledPages = new Set();
const queuedPages = new Set(pageQueue);
const fetchedAssets = new Set();
const siteHosts = new Set([
  new URL(sourceOrigin).host,
  new URL(sourceOrigin).hostname,
  sourceHostHeader,
  sourceHostHeader.replace(/^www\./, ''),
]);

await rm(outDir, { force: true, recursive: true });
await mkdir(outDir, { recursive: true });

while (pageQueue.length > 0) {
  if (crawledPages.size >= maxPages) {
    console.warn(`Page limit reached (${maxPages}). Increase MAX_PAGES if needed.`);
    break;
  }

  const pagePath = pageQueue.shift();
  if (!pagePath || crawledPages.has(pagePath)) continue;
  crawledPages.add(pagePath);

  const html = await fetchText(pagePath);
  const assetUrls = collectAssetUrls(html, pagePath);
  const pageUrls = collectPageUrls(html, pagePath);
  const rewrittenHtml = rewriteForStatic(html);

  await writeTextFile(outputPathForPage(pagePath), rewrittenHtml);

  for (const assetUrl of assetUrls) {
    await fetchAsset(assetUrl);
  }

  for (const nextPage of pageUrls) {
    if (!queuedPages.has(nextPage) && !crawledPages.has(nextPage)) {
      queuedPages.add(nextPage);
      pageQueue.push(nextPage);
    }
  }

  console.log(`Exported ${pagePath}`);
}

console.log(`Static export ready in ${path.relative(process.cwd(), outDir) || outDir}`);
console.log(`Pages: ${crawledPages.size}; assets: ${fetchedAssets.size}`);

function normalizeOrigin(value) {
  return value.replace(/\/+$/, '');
}

async function fetchText(pagePath) {
  const response = await fetchFromSource(pagePath);
  if (!response.ok) {
    throw new Error(`Failed to fetch ${pagePath}: ${response.status} ${response.statusText}`);
  }
  return response.text();
}

async function fetchAsset(assetPath) {
  const cleanPath = stripHash(assetPath);
  if (fetchedAssets.has(cleanPath) || shouldSkipAsset(cleanPath)) return;
  fetchedAssets.add(cleanPath);

  let response;
  try {
    response = await fetchFromSource(cleanPath);
  } catch (error) {
    console.warn(`Skipped asset ${cleanPath}: ${error.message}`);
    return;
  }

  if (!response.ok) {
    console.warn(`Skipped asset ${cleanPath}: ${response.status}`);
    return;
  }

  const bytes = Buffer.from(await response.arrayBuffer());
  await writeBinaryFile(outputPathForAsset(cleanPath), bytes);

  const contentType = response.headers.get('content-type') || '';
  if (contentType.includes('text/css') || cleanPath.split('?')[0].endsWith('.css')) {
    const css = bytes.toString('utf8');
    for (const nestedAsset of collectCssUrls(css, cleanPath)) {
      await fetchAsset(nestedAsset);
    }
  }
}

async function fetchFromSource(resourcePath) {
  let currentPath = resourcePath;
  const seenRedirects = new Set();

  for (let redirects = 0; redirects < 8; redirects++) {
    const url = new URL(currentPath, sourceOrigin);
    const response = await fetch(url, {
      headers: sourceHostHeader ? { Host: sourceHostHeader } : {},
      redirect: 'manual',
    });

    if (![301, 302, 303, 307, 308].includes(response.status)) {
      return response;
    }

    const location = response.headers.get('location');
    const redirectedPath = location ? normalizeSiteUrl(location, currentPath) : null;
    if (!redirectedPath) {
      return response;
    }

    if (seenRedirects.has(redirectedPath)) {
      return response;
    }

    seenRedirects.add(redirectedPath);
    currentPath = redirectedPath;
  }

  throw new Error(`Too many redirects while fetching ${resourcePath}`);
}

function collectAssetUrls(html, basePath) {
  const urls = new Set();
  const patterns = [
    /\s(?:src|href)=["']([^"']+)["']/gi,
    /\ssrcset=["']([^"']+)["']/gi,
    /url\(([^)]+)\)/gi,
  ];

  for (const pattern of patterns) {
    for (const match of html.matchAll(pattern)) {
      const raw = match[1];
      if (pattern.source.includes('srcset')) {
        raw.split(',').forEach((item) => addAssetUrl(urls, item.trim().split(/\s+/)[0], basePath));
      } else {
        addAssetUrl(urls, raw, basePath);
      }
    }
  }

  return [...urls];
}

function collectCssUrls(css, basePath) {
  const urls = new Set();
  for (const match of css.matchAll(/url\(([^)]+)\)/gi)) {
    addAssetUrl(urls, match[1], basePath);
  }
  return [...urls];
}

function collectPageUrls(html, basePath) {
  const urls = new Set();
  for (const match of html.matchAll(/\shref=["']([^"']+)["']/gi)) {
    const normalized = normalizeSiteUrl(match[1], basePath);
    if (!normalized) continue;
    if (shouldSkipPage(normalized)) continue;
    if (looksLikeAsset(normalized)) continue;
    urls.add(ensureDirectoryPath(normalized));
  }
  return [...urls];
}

function addAssetUrl(urls, rawUrl, basePath) {
  const normalized = normalizeSiteUrl(rawUrl, basePath);
  if (!normalized || shouldSkipAsset(normalized)) return;
  if (looksLikeAsset(normalized) || normalized.startsWith('/wp-content/')) {
    urls.add(normalized);
  }
}

function normalizeSiteUrl(rawUrl, basePath) {
  const cleaned = rawUrl.trim().replace(/^['"]|['"]$/g, '');
  if (!cleaned || cleaned.startsWith('#') || cleaned.startsWith('data:') || cleaned.startsWith('mailto:') || cleaned.startsWith('tel:')) {
    return null;
  }

  let url;
  try {
    url = new URL(cleaned, new URL(basePath, sourceOrigin));
  } catch {
    return null;
  }

  if (!['http:', 'https:'].includes(url.protocol)) return null;
  if (!siteHosts.has(url.host)) return null;

  return `${url.pathname}${url.search}`;
}

function rewriteForStatic(html) {
  let output = html;
  const replacement = publicOrigin || '';
  for (const host of siteHosts) {
    if (!host) continue;
    output = output.replaceAll(`http://${host}`, replacement);
    output = output.replaceAll(`https://${host}`, replacement);
  }
  output = output.replaceAll(sourceOrigin, replacement);
  return output;
}

function shouldSkipPage(urlPath) {
  return [
    '/wp-admin',
    '/wp-login.php',
    '/xmlrpc.php',
    '/wp-json',
    '/feed',
    '/comments/feed',
    '/cart',
    '/carrinho',
    '/checkout',
    '/finalizar-compra',
    '/my-account',
    '/minha-conta',
  ].some((prefix) => urlPath.startsWith(prefix)) || urlPath.includes('add-to-cart=');
}

function shouldSkipAsset(urlPath) {
  return urlPath.startsWith('/wp-json') || urlPath.startsWith('/wp-admin') || urlPath.includes('add-to-cart=');
}

function looksLikeAsset(urlPath) {
  return /\.(avif|bmp|css|eot|gif|ico|jpe?g|js|json|map|mp4|otf|png|svg|ttf|webm|webp|woff2?|xml)(\?.*)?$/i.test(urlPath);
}

function ensureDirectoryPath(urlPath) {
  const withoutQuery = stripQueryAndHash(urlPath);
  if (withoutQuery.endsWith('/')) return withoutQuery;
  return `${withoutQuery}/`;
}

function outputPathForPage(pagePath) {
  const cleanPath = stripQueryAndHash(pagePath);
  return path.join(outDir, cleanPath, 'index.html');
}

function outputPathForAsset(assetPath) {
  const cleanPath = stripQueryAndHash(assetPath);
  return path.join(outDir, cleanPath);
}

function stripHash(urlPath) {
  return urlPath.split('#')[0];
}

function stripQueryAndHash(urlPath) {
  return urlPath.split('#')[0].split('?')[0];
}

async function writeTextFile(filePath, content) {
  await mkdir(path.dirname(filePath), { recursive: true });
  await writeFile(filePath, content, 'utf8');
}

async function writeBinaryFile(filePath, content) {
  await mkdir(path.dirname(filePath), { recursive: true });
  await writeFile(filePath, content);
}
