<?php
/**
 * @copyright (C) 2026 Sensomedia. All rights reserved.
 * @license   GNU General Public License version 2 or later
 */

namespace Sensomedia\Component\Sensomcp\Administrator\Field;

use Joomla\CMS\Form\Field\ListField;
use Joomla\CMS\HTML\HTMLHelper;
use Joomla\CMS\Language\Text;
use Sensomedia\Component\Sensomcp\Administrator\Helper\ThemeHelper;

\defined('_JEXEC') or die;

/**
 * Liste des thèmes d'habillage de l'admin, découverts dans
 * media/com_sensomcp/css/themes/. Ajouter un thème = déposer un fichier CSS.
 */
class ThemeField extends ListField
{
    protected $type = 'Theme';

    protected function getOptions(): array
    {
        $options = [
            HTMLHelper::_('select.option', '', Text::_('COM_SENSOMCP_THEME_NORMAL')),
        ];

        foreach (ThemeHelper::available() as $key => $meta) {
            $mode   = $meta['mode'] === 'dark'
                ? Text::_('COM_SENSOMCP_THEME_MODE_DARK')
                : Text::_('COM_SENSOMCP_THEME_MODE_LIGHT');
            $options[] = HTMLHelper::_('select.option', $key, $meta['name'] . ' — ' . $mode);
        }

        return array_merge($options, parent::getOptions());
    }
}
