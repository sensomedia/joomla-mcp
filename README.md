# Senso MCP — MCP Server for Joomla

**Senso MCP** turns a Joomla 5 or 6 site into a [Model Context Protocol](https://modelcontextprotocol.io/) (MCP) server.
Connect Claude, ChatGPT or any MCP-compatible AI assistant and manage your Joomla site in natural language.

- Website: <https://www.sensomcp.com>
- Documentation: <https://www.sensomcp.com/en/doc>
- Download (installable package, with automatic updates): <https://www.sensomcp.com/en/downloads/senso-mcp-pack-free>

This repository contains the source code of the **free pack**.

## What it does

The MCP server runs inside Joomla itself: no extra server, no Node.js bridge. Your AI assistant connects to
your site and acts through well-defined, permission-checked tools.

With the free pack, an assistant can:

- create, read, update, reorder and delete **articles**, **categories** and **tags**;
- list, compare and restore **article versions**;
- read **site information** (languages, template, versions) to ground its actions;
- fetch a rendered front-end page to check the result;
- clear the Joomla caches.

## Security

- **OAuth 2.1** authorization with standard discovery endpoints, or a Joomla API token.
- Every tool is gated by **Joomla's own ACL**: the assistant can only do what the connected account may do.
- Scoped, revocable access per client. Passwords are never exposed.

## Requirements

- Joomla 5.x or 6.x (no compatibility plugin needed)
- PHP 8.2 or later

## Installation

1. Download the package from <https://www.sensomcp.com/en/downloads/senso-mcp-pack-free>.
2. Install it in Joomla: *System → Install → Extensions*.
3. Connect your assistant: see [Connect Claude](https://www.sensomcp.com/en/doc/mcp-connection/connect-claude)
   or [Connect ChatGPT](https://www.sensomcp.com/en/doc/mcp-connection/connect-chatgpt).

A step-by-step guide is available in the
[Getting Started](https://www.sensomcp.com/en/doc/getting-started) section of the documentation.

## What is in this repository

| Folder | Extension |
|---|---|
| `packages/com_sensomcp` | Component: MCP server, OAuth, settings, dashboard |
| `packages/plg_webservices_sensomcp` | Web Services plugin: API routes |
| `packages/plg_system_sensomcp` | System plugin: discovery endpoints |
| `packages/plg_system_sensomcpchangelog` | System plugin: changelog display |
| `packages/plg_task_sensomcp` | Scheduled task plugin: maintenance |
| `packages/plg_sensomcp_site` | Tools: site information |
| `packages/plg_sensomcp_content` | Tools: articles, categories, tags, versions |

## Going further

Additional plugins add tools for custom fields, media, menus, SEO, multilingual sites, modules, templates,
users, extensions and YooTheme Pro. See <https://www.sensomcp.com/en/pricing>.

## Support

- Documentation and FAQ: <https://www.sensomcp.com/en/doc>
- Community chat: <https://discord.gg/B6XWbfbGw8>
- Bug reports: open an issue in this repository.

## License

GNU General Public License version 2 or later. See [LICENSE](LICENSE).

Developed by [Sensomedia](https://www.sensomedia.com). The Joomla!® name is used under a limited licence from
Open Source Matters; Senso MCP is not affiliated with or endorsed by Open Source Matters or the Joomla!® project.
