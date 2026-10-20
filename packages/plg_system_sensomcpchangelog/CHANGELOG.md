# Senso MCP Changelog endpoint — Changelog

## 1.4.0 - 2026-10-20
### Changed
- Version aligned with the Senso MCP package (no functional change).

## 1.3.3 - 2026-10-20
### Changed
- Version aligned with the Senso MCP package (no functional change).

## 1.3.2 - 2026-10-17
### Security
- The changelog endpoint only answers for categories that have a published update stream, takes the extension identity from that stream and keeps a single cache entry per category.

## 1.3.1
### Added
- Console command `sensomcp:changelog:notes` and native Joomla cache for the XML output.
