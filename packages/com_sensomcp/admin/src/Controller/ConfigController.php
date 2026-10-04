<?php
/**
 * @copyright (C) 2026 Sensomedia. All rights reserved.
 * @license   GNU General Public License version 2 or later
 */

namespace Sensomedia\Component\Sensomcp\Administrator\Controller;

use Joomla\CMS\Factory;
use Joomla\CMS\MVC\Controller\BaseController;
use Joomla\CMS\Uri\Uri;
use Sensomedia\Component\Sensomcp\Administrator\OAuth\Metadata;

\defined('_JEXEC') or die;

/**
 * Génère et télécharge un bundle MCP (.mcpb) pré-rempli pour Claude Desktop,
 * enveloppant l'endpoint distant via mcp-remote.
 */
class ConfigController extends BaseController
{
    public function mcpb(): void
    {
        $endpoint = Metadata::mcpResource();

        // Nom du site + hôte, pour rendre le bundle identifiable et unique
        // (plusieurs sites installés côte à côte dans Claude Desktop).
        $siteName = trim((string) Factory::getApplication()->get('sitename')) ?: 'Joomla';
        $host     = Uri::getInstance()->getHost();
        $slug     = trim((string) preg_replace('/[^a-z0-9]+/', '-', strtolower($host)), '-');

        $manifest = [
            'manifest_version' => '0.3',
            'name'             => 'senso-mcp-' . $slug,
            'display_name'     => 'Senso MCP — ' . $siteName,
            'version'          => '0.3.0',
            'description'      => 'Manage ' . $siteName . ' (articles, custom fields, categories) through the Model Context Protocol.',
            'author'           => ['name' => 'Sensomedia', 'url' => 'https://sensomedia.com'],
            'user_config'      => [
                'api_token' => [
                    'type'        => 'string',
                    'title'       => 'Joomla API Token',
                    'description' => 'API token of a Joomla user allowed to use the MCP server.',
                    'sensitive'   => true,
                    'required'    => true,
                ],
            ],
            'server' => [
                'type'        => 'node',
                'entry_point' => 'n/a',
                'mcp_config'  => [
                    'command' => 'npx',
                    'args'    => [
                        '-y',
                        'mcp-remote',
                        $endpoint,
                        '--header',
                        'Authorization: Bearer ${user_config.api_token}',
                    ],
                ],
            ],
        ];

        $json = json_encode($manifest, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

        $tmp = tempnam(sys_get_temp_dir(), 'mcpb');
        $zip = new \ZipArchive();
        $zip->open($tmp, \ZipArchive::CREATE | \ZipArchive::OVERWRITE);
        $zip->addFromString('manifest.json', $json);
        $zip->close();

        $data = file_get_contents($tmp);
        @unlink($tmp);

        $app = Factory::getApplication();
        $app->setHeader('Content-Type', 'application/octet-stream', true);
        $app->setHeader('Content-Disposition', 'attachment; filename="senso-mcp-' . $slug . '.mcpb"', true);
        $app->setHeader('Content-Length', (string) \strlen($data), true);
        $app->sendHeaders();

        echo $data;

        $app->close();
    }
}
