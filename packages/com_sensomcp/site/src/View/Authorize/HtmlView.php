<?php
/**
 * @copyright (C) 2026 Sensomedia. All rights reserved.
 * @license   GNU General Public License version 2 or later
 */

namespace Sensomedia\Component\Sensomcp\Site\View\Authorize;

use Joomla\CMS\MVC\View\HtmlView as BaseHtmlView;

\defined('_JEXEC') or die;

class HtmlView extends BaseHtmlView
{
    public string $clientName = '';
    public string $username   = '';

    /** @var array<string,string> scopeId => libellé traduit */
    public array $availableScopes = [];

    /** @var string[] scopes pré-cochés (demandés par le client) */
    public array $requestedScopes = [];

    public array $oauth = [];

    public function display($tpl = null)
    {
        parent::display($tpl);
    }
}
