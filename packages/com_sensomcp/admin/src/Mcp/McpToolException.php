<?php
/**
 * @copyright (C) 2026 Sensomedia. All rights reserved.
 * @license   GNU General Public License version 2 or later
 */

namespace Sensomedia\Component\Sensomcp\Administrator\Mcp;

\defined('_JEXEC') or die;

/**
 * Erreur « métier » d'un outil MCP — CONTRAT PUBLIC (les plugins la lèvent).
 *
 * Elle n'est pas transformée en erreur JSON-RPC : la spec MCP veut que les
 * erreurs d'exécution d'un outil reviennent au modèle sous forme de résultat
 * `tools/call` avec `isError: true`, afin qu'il puisse se corriger lui-même.
 */
class McpToolException extends \RuntimeException
{
}
