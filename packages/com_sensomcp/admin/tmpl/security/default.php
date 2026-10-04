<?php
/**
 * @copyright (C) 2026 Sensomedia. All rights reserved.
 * @license   GNU General Public License version 2 or later
 */

use Joomla\CMS\HTML\HTMLHelper;
use Joomla\CMS\Language\Text;
use Joomla\CMS\Router\Route;
use Sensomedia\Component\Sensomcp\Administrator\Helper\Updates;

\defined('_JEXEC') or die;

$dashboard = Route::_('index.php?option=com_sensomcp&view=dashboard');
$settings  = Route::_('index.php?option=com_sensomcp&view=settings');
$plugins   = Route::_('index.php?option=com_sensomcp&view=plugins');
?>
<div class="p-4 sensomcp-ui"<?php echo ($this->theme !== '' ? ' data-smcp-theme="' . $this->escape($this->theme) . '"' : '') . ($this->themeMode !== '' ? ' data-smcp-mode="' . $this->escape($this->themeMode) . '"' : ''); ?>>
    <ul class="nav nav-tabs mb-4">
        <li class="nav-item"><a class="nav-link" href="<?php echo $dashboard; ?>"><?php echo Text::_('COM_SENSOMCP_DASHBOARD'); ?></a></li>
        <li class="nav-item"><a class="nav-link" href="<?php echo $settings; ?>"><?php echo Text::_('COM_SENSOMCP_SETTINGS'); ?></a></li>
        <li class="nav-item"><span class="nav-link active"><?php echo Text::_('COM_SENSOMCP_SECURITY'); ?></span></li>
        <li class="nav-item"><a class="nav-link" href="<?php echo $plugins; ?>"><?php echo Text::_('COM_SENSOMCP_PLUGINS'); ?></a></li>
    </ul>
    <a class="sensomcp-logo" href="https://www.sensomcp.com" target="_blank" rel="noopener noreferrer" aria-label="Senso MCP"></a>

    <?php echo Updates::banner(); ?>

    <form action="index.php?option=com_sensomcp&view=security" method="post" name="adminForm" id="adminForm">
        <div class="card mb-4 border-danger<?php echo $this->locked ? ' bg-danger-subtle' : ''; ?>">
            <div class="card-body d-flex justify-content-between align-items-center flex-wrap gap-2">
                <div>
                    <strong class="text-danger"><span class="icon-lock me-1" aria-hidden="true"></span><?php echo Text::_('COM_SENSOMCP_EMERGENCY_LABEL'); ?></strong>
                    <div class="small text-muted"><?php echo Text::_($this->locked ? 'COM_SENSOMCP_EMERGENCY_ACTIVE_DESC' : 'COM_SENSOMCP_EMERGENCY_DESC'); ?></div>
                </div>
                <div class="d-flex gap-2 flex-wrap">
                    <button type="submit" class="btn <?php echo $this->locked ? 'btn-success' : 'btn-danger'; ?>"
                            onclick="document.getElementById('adminForm').elements['task'].value='<?php echo $this->locked ? 'security.unlock' : 'security.lock'; ?>';">
                        <?php echo Text::_($this->locked ? 'COM_SENSOMCP_EMERGENCY_RESUME_BTN' : 'COM_SENSOMCP_EMERGENCY_STOP_BTN'); ?>
                    </button>
                    <button type="submit" class="btn btn-outline-danger"
                            onclick="if(!confirm(<?php echo $this->escape(json_encode(Text::_('COM_SENSOMCP_OAUTH_REVOKE_CONFIRM'))); ?>)){return false;} document.getElementById('adminForm').elements['task'].value='security.revokeOauth';">
                        <?php echo Text::_('COM_SENSOMCP_OAUTH_REVOKE_BTN'); ?>
                    </button>
                </div>
            </div>
        </div>

        <div class="card mb-4 bg-body-secondary">
            <div class="card-body d-flex justify-content-between align-items-center">
                <div>
                    <strong><?php echo Text::_('COM_SENSOMCP_READONLY_LABEL'); ?></strong>
                    <div class="small text-muted"><?php echo Text::_('COM_SENSOMCP_READONLY_DESC'); ?></div>
                </div>
                <div class="form-check form-switch fs-4 mb-0">
                    <input class="form-check-input" type="checkbox" role="switch" id="readonly" name="readonly" value="1" <?php echo $this->readonly ? 'checked' : ''; ?>>
                </div>
            </div>
        </div>

        <?php $rl = $this->rateLimit; ?>
        <div class="card mb-4 bg-body-secondary">
            <div class="card-body">
                <div class="d-flex justify-content-between align-items-center">
                    <div>
                        <strong><?php echo Text::_('COM_SENSOMCP_RATELIMIT_ENABLED_LABEL'); ?></strong>
                        <div class="small text-muted"><?php echo Text::_('COM_SENSOMCP_RATELIMIT_ENABLED_DESC'); ?></div>
                    </div>
                    <div class="form-check form-switch fs-4 mb-0">
                        <input class="form-check-input" type="checkbox" role="switch" id="ratelimit_enabled" name="ratelimit_enabled" value="1" <?php echo !empty($rl['enabled']) ? 'checked' : ''; ?>>
                    </div>
                </div>
                <div class="row g-3 mt-1">
                    <div class="col-sm-6">
                        <label class="form-label small mb-1" for="ratelimit_max"><?php echo Text::_('COM_SENSOMCP_RATELIMIT_MAX_LABEL'); ?></label>
                        <input class="form-control" type="number" min="1" id="ratelimit_max" name="ratelimit_max" value="<?php echo (int) ($rl['max'] ?? 120); ?>">
                    </div>
                    <div class="col-sm-6">
                        <label class="form-label small mb-1" for="ratelimit_window"><?php echo Text::_('COM_SENSOMCP_RATELIMIT_WINDOW_LABEL'); ?></label>
                        <input class="form-control" type="number" min="1" id="ratelimit_window" name="ratelimit_window" value="<?php echo (int) ($rl['window'] ?? 60); ?>">
                    </div>
                </div>
            </div>
        </div>

        <input type="hidden" name="task" value="">
        <?php echo HTMLHelper::_('form.token'); ?>
    </form>
</div>
