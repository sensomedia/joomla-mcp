<?php
/**
 * @copyright (C) 2026 Sensomedia. All rights reserved.
 * @license   GNU General Public License version 2 or later
 */

namespace Sensomedia\Component\Sensomcp\Administrator\Helper;

use Joomla\CMS\Access\Access;
use Joomla\CMS\Factory;
use Joomla\CMS\Helper\UserGroupsHelper;

\defined('_JEXEC') or die;

/**
 * Droit « Connexion aux services Web » (core.login.api) de la configuration globale : exigé par
 * Senso MCP pour TOUTE connexion (OAuth et jeton API Joomla), comme par l'API du cœur.
 *
 * Lecture : règles de l'asset racine et calcul effectif du cœur (Access::checkGroup, héritage des
 * groupes parents compris ; un groupe Super User passe toujours). Écriture : modèle « Application »
 * de com_config (storePermissions, celui de l'écran Permissions), avec ses garde-fous (core.admin
 * global, pas de modification de ses propres groupes, groupes Super User protégés).
 */
final class ApiLoginGroups
{
    public const ACTION = 'core.login.api';

    /**
     * @return array{
     *   selected: list<array{id:int,title:string,kind:string,via:string}>,
     *   available: list<array{id:int,title:string,level:int}>,
     *   all: bool, onlySuper: bool
     * }  kind = super | explicit | inherited
     */
    public static function status(): array
    {
        Access::clearStatics();

        $explicit  = self::explicitRules();
        $forbidden = TokenGroups::forbidden();
        $groups    = UserGroupsHelper::getInstance()->getAll();
        $selected  = [];
        $available = [];
        $all       = false;
        $onlySuper = true;

        foreach ($groups as $grp) {
            $id    = (int) $grp->id;
            $title = (string) $grp->title;
            $super = (bool) Access::checkGroup($id, 'core.admin');
            $has   = $super || (bool) Access::checkGroup($id, self::ACTION);

            if (!$has) {
                if (!\in_array($id, $forbidden, true)) {
                    $available[] = ['id' => $id, 'title' => $title, 'level' => (int) ($grp->level ?? 0)];
                }

                continue;
            }

            if (\in_array($id, $forbidden, true) && !$super) {
                $all = $all || (int) $grp->parent_id === 0;
            }

            $kind = $super ? 'super' : (($explicit[$id] ?? null) === 1 ? 'explicit' : 'inherited');
            $via  = '';

            if ($kind === 'inherited') {
                foreach (array_reverse(array_map('intval', (array) ($grp->path ?? []))) as $anc) {
                    if ($anc !== $id && ($explicit[$anc] ?? null) === 1) {
                        $via = (string) ($groups[$anc]->title ?? '');
                        break;
                    }
                }
            }

            $onlySuper = $onlySuper && $super;
            $selected[] = ['id' => $id, 'title' => $title, 'kind' => $kind, 'via' => $via];
        }

        return ['selected' => $selected, 'available' => $available, 'all' => $all, 'onlySuper' => $onlySuper];
    }

    /** L'utilisateur courant peut-il modifier ce droit (core.admin global, comme l'écran Permissions) ? */
    public static function canEdit(): bool
    {
        return Factory::getApplication()->getIdentity()->authorise('core.admin');
    }

    /**
     * Ajoute (valeur 1) ou retire (retour à « hérité ») le droit pour un groupe.
     *
     * @return string ok | super (groupe Super User, toujours autorisé) | invalid | denied (refus du cœur, message déjà posé)
     */
    public static function change(string $action, int $groupId): string
    {
        $groups = UserGroupsHelper::getInstance()->getAll();

        if (!isset($groups[$groupId]) || ($action === 'add' && \in_array($groupId, TokenGroups::forbidden(), true))) {
            return 'invalid';
        }

        if ($action === 'remove' && Access::checkGroup($groupId, 'core.admin')) {
            return 'super';
        }

        $model = Factory::getApplication()->bootComponent('com_config')
            ->getMVCFactory()->createModel('Application', 'Administrator', ['ignore_request' => true]);

        $res = $model->storePermissions([
            'component' => 'root.1',
            'action'    => self::ACTION,
            'rule'      => (string) $groupId,
            'value'     => $action === 'add' ? '1' : '',
            'title'     => '',
        ]);

        Access::clearStatics();

        foreach (['_system', 'com_config'] as $cache) {
            try {
                Factory::getCache($cache)->clean();
            } catch (\Throwable) {
                // best-effort
            }
        }

        return $res === false ? 'denied' : 'ok';
    }

    /** @return array<int,int> groupe → 0|1 tels qu'enregistrés sur l'asset racine pour core.login.api */
    private static function explicitRules(): array
    {
        $rule = Access::getAssetRules(1)->getData()[self::ACTION] ?? null;

        return $rule ? array_map('intval', $rule->getData()) : [];
    }
}
