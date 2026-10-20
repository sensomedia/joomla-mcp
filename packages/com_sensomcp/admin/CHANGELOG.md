# Senso MCP — Changelog

## 1.4.0 - 2026-10-20
### Added
- Reconnection warning: MCP clients load the tool list once, at connection time, so tools added or changed afterwards (a new plugin, a new parameter, an updated description) stay invisible until the user disconnects then reconnects the connector. The server now remembers the tool list each connection loaded and, when it no longer matches, prepends a notice to the next tool result asking the assistant to tell the user to reconnect (once per hour at most). Connections established before this version are asked to reconnect once, as a catch-up, at their first tool call after the update.
- Dashboard: a "Connections with an outdated tool list" panel lists the connections concerned, with a "Revoke" button for OAuth connections (forces re-authentication, hence a fresh tool list) and "Dismiss".
- An administrator notice is shown right after installing or updating a Senso MCP tool plugin or package.
- Experimental option (off by default): answer outdated connections with an SSE stream carrying the MCP `notifications/tools/list_changed` notification. In our tests claude.ai accepts the stream but does not reload its tools, so the option is only useful to try other MCP clients.

## 1.3.3 - 2026-10-20
### Security
- Unexpected errors (PHP, database, file system) are no longer returned verbatim to the MCP client or to the public OAuth endpoints. The caller gets a generic message with a reference id; the full detail is written to the site MCP log file (`administrator/logs/com_sensomcp.log.php`) and summarized in the MCP request journal under the same reference.
- The public OAuth discovery documents (`.well-known`) no longer list the scopes of every installed plugin: they only announce the generic `mcp` scope, which stands for everything the user may use at the consent step. This stops anonymous visitors from reading which paid plugins are installed, and stops the plugins from being loaded on every anonymous discovery request. Clients that request `mcp` (or nothing) are offered all available scopes, as before.
### Changed
- All extensions of the package now carry the package version number (the previous package shipped some plugins still labelled 1.3.1).

## 1.3.2 - 2026-10-17
### Security
- A refresh token presented again after it was already used now revokes the whole connection (new option: grace delay for network retries, 60 s by default); the assistant then asks to authorize again.
- The cleanup task keeps used refresh tokens for a few days (new option) so that such a reuse can be detected.

## 1.3.1 - 2026-10-17
### Security
- The right to use the API (`core.login.api`) and the account status are now checked on every request and at every token refresh, not only when the connection was approved.
- OAuth connections now have a maximum lifetime (new option, 90 days by default): after that, the assistant asks the user to authorize again.
- Dynamic OAuth client registration is rate-limited per IP address (new options: per hour and per day), and client names are cleaned before being shown on the consent page.
- Article version tools now only accept article versions, check the rights on that article (authors can manage the history of their own articles), and restore through the Joomla article form.
- Article links A/B/C and URL menu items no longer accept links with an executable scheme.

## 1.3.0 - 2026-10-16
### Security
- Permission checks now mirror the Joomla administrator more closely: content written through MCP goes through the Joomla forms and text filters, publishing fields (state, dates, featured, author) require the matching rights, and tools acting on administration areas require access to the corresponding manager. Accounts with limited rights may therefore see fewer tools, or get their articles created unpublished, exactly as in the administrator.
- Downloads triggered by a tool argument (media, extension packages, YOOtheme library) are validated more strictly, and MCP resources follow the reader's rights and the `content.read` scope.
- JSON-RPC batches are limited to 20 messages. The OAuth consent page now shows the host you will be redirected to.
### Added
- Shared form validation helper used by the Fields and Multilingual plugins (they require this version).
- Scheduled cleanup task: inactive dynamically registered OAuth clients are purged after 90 days (configurable).

## 1.2.13 - 2026-10-11
### Fixed
- `rebuild_jch_cache` / `clear_jch_cache(rebuild: true)` refused to run on JCH Optimize **9.4.2 Pro** with "Rebuild (recache) requires JCH Optimize Pro". JCH 9.4.2 dropped the `JCH_PRO` constant in favour of an `EditionInterface` service; the Pro edition is now detected through that service, with the constant kept as a fallback for older JCH versions.
- `rebuild_jch_cache` reported `started: true` on JCH 9.4.2 while nothing was rebuilt: JCH 9.4.2 added a single-use nonce to the signature of its recache endpoint, which rejected our ping with HTTP 403, and the tool never read the reply. The ping now uses JCH's own `RecacheTrigger` helper when present (older JCH versions keep the previous signature), and the tool reads the HTTP status: 202 = accepted, 409 = a recache is already running, anything else falls back to the background CLI and is reported in `web_ping`.

## 1.2.12 - 2026-10-11
### Fixed
- ChatGPT connector: the connection completed (OAuth, token) but no tool was ever listed. Since 1.2.0 the server answered the MCP 2026-07-28 `server/discover` probe with a discovery result that only advertised the handshake protocol 2025-06-18; ChatGPT then treated the server as "modern", found no mutually supported version and stopped before `tools/list` (Claude tolerated it). The probe is now answered with "method not found" (-32601), the standard reply of a handshake-based server, which makes every dual-era client fall back to `initialize` as expected. The probe is no longer written to the request log.

## 1.2.11 - 2026-10-08
### Added
- Licence section of the dashboard: when the store cannot be reached, the technical cause (cURL/TLS error, HTTP status, non-JSON reply) is now shown under the "Store temporarily unreachable" message and written to `administrator/logs/com_sensomcp.log.php`, so an outbound firewall or outdated CA bundle on the hosting can be diagnosed.

## 1.2.10 - 2026-10-05
### Added
- Custom field values on categories: `get_category` returns `fields`, `create_category` / `update_category` accept a `fields` object, and the new `list_category_fields` tool lists the fields applicable to a category (context `com_content.categories`).
### Fixed
- Custom field values with a structure (subform rows, structured third-party types) are now stored as Joomla does (JSON) instead of being broken into "Array" rows, and are returned decoded by `get_article` / `get_category`.

## 1.2.9 - 2026-10-03
### Fixed
- `create_article` / `update_article` now accept the article dates `created`, `publish_up` and `publish_down` (ISO 8601; a value without UTC offset is read in the site timezone). `publish_up`/`publish_down` can be cleared with null.
- `update_article` now accepts `alias`; it was previously impossible to change an article alias.
- `update_article`: changing one image attribute (e.g. the alt text) no longer wipes the other image values.
### Added
- `create_article` / `update_article` now cover the whole Publishing/Options tabs: `access`, `featured` (+ `featured_up`/`featured_down`, needs edit.state), `created_by`, `created_by_alias`, `note`, `metadata` (author, robots, rights) and `attribs` (article options); `metadata`/`attribs` are merged key by key on update. Images gain `image_intro_caption`, `float_intro`, `image_fulltext_caption`, `float_fulltext`.
- `get_article` now also returns `access`, `note`, `attribs`, `metadata`, `created_by_alias`, `created_by_name`, `modified_by_name`, `featured_up`/`featured_down`, `ordering`, `version` and `checked_out`.
- `search_articles` now returns `alias`, `featured`, `access`, `publish_up`/`publish_down`, and can sort by `publish_up`.
- Both write tools return the stored dates (UTC) and featured state after saving.

## 1.2.8 - 2026-09-29
### Added
- Debug mode: a tool that fails now also logs its error (message and origin), not only the call parameters.

## 1.2.7 - 2026-09-27
### Fixed
- Multilingual public URLs of all-languages articles, categories and tags now resolve reliably: the per-language URLs are resolved in parallel with a longer timeout, so they are no longer occasionally left partly untranslated (e.g. after a save).
- `update_article` and `create_article` now accept `metakey` (meta keywords); it was previously ignored.
### Added
- `get_article` now also returns the article's meta keywords (metakey), dates (created, modified, publish_up, publish_down) and author, so structured-data and other tools no longer have to look them up elsewhere.

## 1.2.6 - 2026-09-26
### Added
- Articles: the editorial Link A/B/C fields (Publishing tab) can now be read and written. `get_article` returns them and `create_article`/`update_article` accept a `links` object ({a,b,c} → {url, text, target}).

## 1.2.5 - 2026-09-25
### Added
- `*` (all-languages) articles, categories and tags now return one public URL per content
  language (a `urls` map) alongside `url`, each resolved to the site's canonical SEF.
### Fixed
- Dashboard: the "most used methods" counters are legible again (white text on the blue badge).

## 1.2.4 - 2026-09-23
### Fixed
- MCP responses are now shielded against stray PHP output. A warning, notice or deprecation
  raised by Joomla core or a third-party plugin during a tool call — for instance the
  nested-set rebuild behind `create_category` / `update_category` / `create_menu_item` /
  `update_menu_item`, or a folder scan by `list_media` — is logged instead of leaking into
  the response body. Fixes the `Unexpected token '<', "<br />\n<b>"... is not valid JSON`
  error seen on servers that keep PHP `display_errors` on.
- Plugin catalog is now fetched live (60-second anti-burst window instead of a 6-hour
  cache), with the stored copy kept only as an offline fallback when the store is
  unreachable. The local cache is also invalidated immediately when the license key is
  changed or the current domain is declared/reassigned. This fixes paid plugins staying
  stuck on "Your license does not cover this plugin" right after declaring the site's
  domain, and keeps coverage and available versions always up to date.

## 1.2.3 - 2026-09-10
### Added
- MCP `create_category` / `update_category`: the category meta description and image (with
  alt text and the "empty alt" option) can now be set from the tools.

## 1.2.2 - 2026-09-10
### Added
- Bundled changelog engine: the Update view now shows the release notes for each version,
  served dynamically with native Joomla caching.
### Changed
- Every manifest now declares its changelog URL, so the Manage and Update views link to the
  release notes.

## 1.2.1 - 2026-09-10
### Fixed
- Options screen title is now localized (it previously showed the raw language key
  `com_sensomcp_configuration`).
- Post-install/update changelog: now shown for recent versions and written in English
  (more international); the "installed/updated" wording follows suit.

## 1.2.0 - 2026-09-10
### Added
- Debug mode (Options → Security, disabled by default): logs every tool call with its
  parameters (sensitive fields redacted, long values truncated) to
  administrator/logs/com_sensomcp.log.php.
- MCP `server/discover` support (2026-07-28 protocol): no more "unknown method" errors
  with recent clients.
- `set_article_state` now supports archiving and moving to the trash (in addition to
  publish/unpublish).
### Security
- `delete_article`: permanent deletion now requires an explicit confirmation
  (confirm=true); without it, items are only moved to the trash (recoverable).

## 1.1.1 - 2026-09-09
### Added
- `set_article_state`: new archived (2) and trashed (-2) states.
### Security
- `delete_article`: confirmation safeguard before any permanent deletion.

## 1.1.0 - 2026-09-06
### Added
- Reworked Plugins screen: 100% AJAX, one-click update, version badge, responsive layout,
  uninstall.
- Catalog localized to the administration language.
- New-version notification banner on the dashboard.
- Five more languages: German, Spanish, Italian, Dutch, Portuguese (Brazil).

## 1.0.5 - 2026-09-05
### Fixed
- Installing a plugin from the catalog failed ("Download from the store failed") because the
  request did not include the site domain, now required by the store for seat control. The
  site domain is now sent, as it already was for automatic updates.

## 1.0.4 - 2026-09-04
### Changed
- Explained MCP tool refusal: when a per-element access check denies a tool, the explanatory
  message is now returned to the client on the call (instead of a generic refusal).

## 1.0.3 - 2026-09-03
### Security
- OAuth consent screen: now offers only the scopes the authorized account can actually use
  (Joomla rights), and never grants a scope beyond those rights even if the request is forged.
  Completes the alignment of MCP rights with the ACL (runtime enforcement was already in place).
### Fixed
- Installation error message: doubled apostrophe.

## 1.0.2 - 2026-09-03
### Fixed
- Plugin catalog: after entering or changing the license key, the "Plugins & tools" view
  stayed stuck on the "Get…" (purchase) button until the cache expired (6 h) or was manually
  refreshed. The cache is now tied to the license key and invalidates automatically as soon
  as it changes: covered plugins become installable on the next load.

## 1.0.1 - 2026-09-03
### Security
- **Alignment of MCP rights with the Joomla ACL**: each tool is now subject to the ACL of the
  token's user. A write tool (create/update/delete an article, module, extension…) is refused
  if the user lacks the matching Joomla right, even if the token holds the scope. `list()`
  hides unauthorized tools.
- **Read filtering**: article and category searches/reads now return only content visible to
  the user (access levels + published state); version history and module/YOOtheme settings
  reads require edit rights. No more leaking drafts, trashed or restricted content.
- **Anti-SSRF protection**: tools that make the server download a URL (extension
  install/update, media upload by URL) reject non-http(s) URLs and those resolving to a
  private or reserved address; page rendering is restricted to the current site.
- The native Joomla API token grants MCP access only if the account may log in via the API
  and is not blocked.

## 1.0.0-beta9 - 2026-08-31
### Fixed
- `rebuild_jch_cache` (site plugin): the rebuild did not start on Plesk hosting. Cause:
  `open_basedir` prevents `is_executable()` from seeing the PHP binary outside the vhost,
  while `exec` is allowed. The recache now primarily uses **JCH's signed web trigger**
  (`task=recache.run`, the mechanism behind the "Rebuild cache" button) — no exec or CLI,
  immune to `open_basedir` — with a fallback to the CLI spawn (PHP binary detection fixed by a
  `php -v` probe), then instructions.

## 1.0.0-beta8 - 2026-08-31
### Changed
- Aligned every package extension (engine + tool plugins) on the same version for install
  consistency.
### Added
- YOOtheme: explicit guidance in the layout-writing tools — for a full-width section use the
  "None" (empty) width, not "Expand" (which keeps a `uk-container-expand` wrapper with residual
  side padding).

## 1.0.0-beta7 - 2026-08-28
### Added
- Site plugin: JCH Optimize cache maintenance tools — `clear_jch_cache`, `rebuild_jch_cache`,
  `set_jch_cache_state` (enable/disable both JCH system plugins). The LLM checks that both
  plugins are active before clearing/rebuilding.
### Fixed
- `rebuild_jch_cache`: always passes `--live-site` to the CLI recache command (required when
  `live_site` is empty in configuration.php, otherwise the recache exited immediately with an
  error).

## 1.0.0-beta6 - 2026-08-26
### Added / Changed (YOOtheme plugin)
- **YOOtheme** plugin extended (37 tools) following real-world feedback: field binding reworked
  on YOOtheme's native `source` format (prefix/suffix filters, hide-if-empty, source
  article/#node/#parent), **repeatable sources** (subforms / collection fields), **composed
  sources** with if/if-else conditions, and **multi-field visibility conditions** (AND/OR/custom
  expression) — Essentials extended functions.
### Fixed
- Custom field name validation (hyphens vs underscores).

## 1.0.0-beta5 - 2026-08-26
### Added
- New **YOOtheme** plugin (paid, full bundle): 34 tools to drive YOOtheme Pro 5 over MCP —
  templates (layout models per category/tag), builder pages (articles), dynamic mapped fields,
  **official YOOtheme Library** (import pro designs + images), full customizer **settings**,
  builder **mega-menus/dropdowns**, **builder modules**, and saved My Layouts/Presets. Safe
  writing throughout (lock + snapshots + re-read). YOOtheme Pro 5 required; Essentials optional.

## 1.0.0-beta4 - 2026-08-25
### Fixed
- OAuth on a non-UTC server: the authorization code exchange (and refresh) failed with
  "invalid_grant / expired code" (HTTP 400) whenever the server's PHP timezone was not UTC.
  `CodeStore`/`RefreshStore` compared the expiry (stored in UTC) via `strtotime()`, which
  interpreted it in the local timezone → offset → codes seen as expired at the very moment of
  creation. The comparison is now done in UTC via `Factory::getDate()->toUnix()`. Affected any
  non-UTC site (the MCP connection failed after consent).

## 1.0.0-beta3 - 2026-08-24
### Added
- MCP server rate limiting (per identity, configurable window): protects availability
  (runaway client, leaked token) — HTTP 429 + Retry-After.
- Emergency stop: reversible global lock (every request refused, 503), in one click or via
  the Options.
- Global OAuth access revocation (hard kill): all issued OAuth tokens are invalidated, every
  client must re-authenticate.
- Enriched "Plugins and security" view: rate-limit settings and incident-response buttons;
  actions also save the form.
### Fixed
- Logo link (hidden "external link" icon).

## 1.0.0-beta2 - 2026-08-24
### Added
- Admin themes: 7 moods (3 dark, 3 light + Normal), extensible by simply dropping a CSS file;
  clickable logo, adapts to Joomla's dark mode, mobile responsive.
- OAuth `.well-known` discovery served by a bundled system plugin (socket-less nginx rule) —
  portable to any server.
- ACL enforced: component access (core.manage); Security + Options changes (core.admin).
- Settings view: Options button exposed, system plugin status, socket-less server directives.
### Fixed
- Dark-mode contrasts (badges, switches, counter tiles), card radii, spacings.

## 1.0.0-beta1 - 2026-08-23
### Added
- First public (beta) release: full package bundling the MCP engine and all tool plugins
  (free and paid).
- Reordering tools: reorder_article, reorder_article_field (Content), reorder_module
  (Modules), reorder_menu_item and reorder_menu (Menus).
- "Plugins and security" view: per-tool enablement (No/Yes), global read-only switch, counter
  tiles.
- List pagination (offset/limit) on the list tools.
- MCP Resources (recent articles, read-only) and guided Prompts (writing, SEO audit,
  translation).
### Fixed
- OAuth `scope` column widened to VARCHAR(1024) (18 scopes exceeded the 255 limit).

## 0.3.0 - 2026-08-22
### Added
- Pluggable architecture: the component is a pure engine (MCP transport + OAuth + tool
  registry), all tools come from plugins in the `sensomcp` group.
- Bundled free plugins: Content (articles, custom fields, categories, tags), Media (library),
  Site (get_site_info, access levels, installed components).
- Dashboard (statistics, recent requests, graph) and Settings screen (server diagnostics,
  directives, client config, .mcpb export).
- Granular OAuth scopes with per-checkbox consent.
### Fixed
- Deleting categories/tags: silent false success (PHP reference on publish/delete).

## 0.2.0 - 2026-08-19
### Added
- OAuth 2.1 authorization server (for claude.ai web): DCR, PKCE S256, refresh with rotation,
  .well-known metadata.

## 0.1.0 - 2026-08-19
### Added
- MCP server (JSON-RPC 2.0, stateless HTTP transport) exposed through the Joomla API.
- Authentication via the native Joomla API token.
