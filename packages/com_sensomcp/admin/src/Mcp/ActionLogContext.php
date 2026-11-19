<?php
/**
 * @copyright (C) 2026 Sensomedia. All rights reserved.
 * @license   GNU General Public License version 2 or later
 */

namespace Sensomedia\Component\Sensomcp\Administrator\Mcp;

use Joomla\CMS\Factory;

\defined('_JEXEC') or die;

/**
 * Journal des actions Joomla pour les changements d'état et suppressions faits via MCP.
 *
 * `plg_actionlog_joomla::onContentChangeState()` et `onContentAfterDelete()` décident de
 * journaliser d'après l'option de la REQUÊTE HTTP (`$app->getInput()->get('option')`), pas
 * d'après le contexte de l'événement. Via l'API Joomla c'est le composant visé (com_content,
 * com_redirect…) ; via Senso MCP c'est `com_sensomcp`, absent de #__action_logs_extensions →
 * rien n'était journalisé (les save() l'étaient : onContentAfterSave se fie au contexte).
 *
 * `run()` présente la requête comme venant du composant visé le temps de l'appel, puis
 * restaure l'option d'origine, même en cas d'exception.
 *
 * ⚠ `publish()` / `delete()` des modèles reçoivent les clés PAR RÉFÉRENCE : passer une
 * closure `function () use ($model, &$pks) { … }` (une fonction fléchée capture par valeur).
 *
 * Exemple :
 *     $ok = ActionLogContext::run('com_content', static function () use ($model, &$ids, $state) {
 *         return $model->publish($ids, $state);
 *     });
 *
 * @since 1.6.0
 */
final class ActionLogContext
{
    /**
     * @template T
     * @param string        $option composant dont relève l'action (com_content, com_categories, com_tags…)
     * @param callable(): T $fn
     * @return T
     */
    public static function run(string $option, callable $fn): mixed
    {
        $input = Factory::getApplication()->getInput();
        $prev  = $input->get('option');
        $input->set('option', $option);

        try {
            return $fn();
        } finally {
            $input->set('option', $prev);
        }
    }
}
