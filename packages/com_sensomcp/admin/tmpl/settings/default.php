<?php
/**
 * @copyright (C) 2026 Sensomedia. All rights reserved.
 * @license   GNU General Public License version 2 or later
 */

use Joomla\CMS\Language\Text;
use Joomla\CMS\Router\Route;
use Joomla\CMS\Session\Session;
use Sensomedia\Component\Sensomcp\Administrator\Helper\Updates;

\defined('_JEXEC') or die;

$dashboard = Route::_('index.php?option=com_sensomcp&view=dashboard');
$mcpbUrl   = Route::_('index.php?option=com_sensomcp&task=config.mcpb&' . Session::getFormToken() . '=1');

$badge = static function (bool $ok): string {
    return $ok
        ? '<span class="badge bg-success">' . Text::_('COM_SENSOMCP_OK') . '</span>'
        : '<span class="badge bg-danger">' . Text::_('COM_SENSOMCP_KO') . '</span>';
};

$serverLabel = ['nginx' => 'nginx', 'apache' => 'Apache', 'unknown' => Text::_('COM_SENSOMCP_SERVER_UNKNOWN')][$this->server] ?? $this->server;
?>
<div class="p-4 sensomcp-ui"<?php echo ($this->theme !== '' ? ' data-smcp-theme="' . $this->escape($this->theme) . '"' : '') . ($this->themeMode !== '' ? ' data-smcp-mode="' . $this->escape($this->themeMode) . '"' : ''); ?>>
    <ul class="nav nav-tabs mb-4">
        <li class="nav-item"><a class="nav-link" href="<?php echo $dashboard; ?>"><?php echo Text::_('COM_SENSOMCP_DASHBOARD'); ?></a></li>
        <li class="nav-item"><span class="nav-link active"><?php echo Text::_('COM_SENSOMCP_SETTINGS'); ?></span></li>
        <li class="nav-item"><a class="nav-link" href="<?php echo Route::_('index.php?option=com_sensomcp&view=security'); ?>"><?php echo Text::_('COM_SENSOMCP_SECURITY'); ?></a></li>
        <li class="nav-item"><a class="nav-link" href="<?php echo Route::_('index.php?option=com_sensomcp&view=plugins'); ?>"><?php echo Text::_('COM_SENSOMCP_PLUGINS'); ?></a></li>
    </ul>
    <a class="sensomcp-logo" href="https://www.sensomcp.com" target="_blank" rel="noopener noreferrer" aria-label="Senso MCP"></a>

    <?php echo Updates::banner(); ?>

    <div class="card mb-4">
        <div class="card-header"><?php echo Text::_('COM_SENSOMCP_ENDPOINT'); ?></div>
        <div class="card-body">
            <div class="input-group">
                <input type="text" class="form-control" id="mcp-endpoint" readonly value="<?php echo htmlspecialchars($this->endpoint, ENT_QUOTES); ?>">
                <button class="btn btn-outline-secondary" type="button" onclick="sensomcpCopyValue('mcp-endpoint', this)"><?php echo Text::_('COM_SENSOMCP_COPY'); ?></button>
            </div>
        </div>
    </div>

    <div class="row">
        <div class="col-md-6 mb-4">
            <div class="card h-100">
                <div class="card-header"><?php echo Text::_('COM_SENSOMCP_PLUGINS_STATUS'); ?></div>
                <ul class="list-group list-group-flush">
                    <?php foreach ($this->plugins as $p) : ?>
                        <li class="list-group-item d-flex justify-content-between align-items-center">
                            <span><?php echo htmlspecialchars($p['label'], ENT_QUOTES); ?></span>
                            <?php
                            if (!$p['installed']) {
                                echo '<span class="badge bg-secondary">' . Text::_('COM_SENSOMCP_NOT_INSTALLED') . '</span>';
                            } elseif ($p['enabled']) {
                                echo $badge(true);
                            } else {
                                echo '<span class="badge bg-warning">' . Text::_('COM_SENSOMCP_DISABLED') . '</span>';
                            }
                            ?>
                        </li>
                    <?php endforeach; ?>
                </ul>
            </div>
        </div>
        <div class="col-md-6 mb-4">
            <div class="card h-100">
                <div class="card-header d-flex justify-content-between">
                    <span><?php echo Text::_('COM_SENSOMCP_API_ACCESS'); ?></span>
                    <span class="text-muted small"><?php echo Text::_('COM_SENSOMCP_SERVER'); ?> : <strong><?php echo htmlspecialchars($serverLabel, ENT_QUOTES); ?></strong></span>
                </div>
                <ul class="list-group list-group-flush">
                    <li class="list-group-item d-flex justify-content-between">
                        <span><code>Authorization</code> — <?php echo Text::_('COM_SENSOMCP_AUTH_FORWARDED'); ?></span>
                        <?php echo $badge((bool) ($this->checks['auth_forwarded'] ?? false)); ?>
                    </li>
                    <li class="list-group-item d-flex justify-content-between">
                        <span><code>/api/index.php</code> — <?php echo Text::_('COM_SENSOMCP_API_ROUTING'); ?></span>
                        <?php echo $badge((bool) ($this->checks['api'] ?? false)); ?>
                    </li>
                    <li class="list-group-item d-flex justify-content-between">
                        <span><code>/.well-known/…</code> — <?php echo Text::_('COM_SENSOMCP_WELLKNOWN_REWRITE'); ?></span>
                        <?php echo $badge((bool) ($this->checks['wellknown'] ?? false)); ?>
                    </li>
                </ul>
            </div>
        </div>
    </div>

    <?php if ($this->directives === []) : ?>
        <div class="alert alert-success">
            <strong>✓</strong> <?php echo Text::_('COM_SENSOMCP_SERVER_OK'); ?>
        </div>
    <?php else : ?>
        <?php foreach ($this->directives as $i => $d) : ?>
            <div class="card mb-4 border-<?php echo $d['level'] === 'all' ? 'danger' : 'warning'; ?>">
                <div class="card-header d-flex justify-content-between align-items-center">
                    <span>
                        <?php echo Text::_($d['title']); ?>
                        <?php if ($d['level'] === 'all') : ?>
                            <span class="badge bg-danger ms-2"><?php echo Text::_('COM_SENSOMCP_LEVEL_ALL'); ?></span>
                        <?php else : ?>
                            <span class="badge bg-warning ms-2"><?php echo Text::_('COM_SENSOMCP_LEVEL_OAUTH'); ?></span>
                        <?php endif; ?>
                    </span>
                    <button class="btn btn-sm btn-outline-secondary" type="button" onclick="sensomcpCopyText('mcp-dir-<?php echo $i; ?>', this)"><?php echo Text::_('COM_SENSOMCP_COPY'); ?></button>
                </div>
                <div class="card-body">
                    <pre id="mcp-dir-<?php echo $i; ?>" class="mb-0 p-2 bg-body-secondary border rounded" style="max-height:260px;overflow:auto;"><?php echo htmlspecialchars($d['code'], ENT_QUOTES); ?></pre>
                </div>
            </div>
        <?php endforeach; ?>
        <p class="text-muted small"><?php echo Text::_('COM_SENSOMCP_DIR_HINT'); ?></p>
    <?php endif; ?>

    <div class="row">
        <div class="col-lg-7 mb-4">
            <div class="card h-100">
                <div class="card-header d-flex justify-content-between align-items-center">
                    <span><?php echo Text::_('COM_SENSOMCP_CONFIG_JSON'); ?></span>
                    <button class="btn btn-sm btn-outline-secondary" type="button" onclick="sensomcpCopyText('mcp-config', this)"><?php echo Text::_('COM_SENSOMCP_COPY'); ?></button>
                </div>
                <div class="card-body">
                    <pre id="mcp-config" class="mb-0 p-2 bg-body-secondary border rounded" style="overflow:auto;max-height:340px"><?php echo htmlspecialchars($this->configJson, ENT_QUOTES); ?></pre>
                    <small class="text-muted"><?php echo Text::_('COM_SENSOMCP_CONFIG_JSON_HINT'); ?></small>
                </div>
            </div>
        </div>
        <div class="col-lg-5 mb-4">
            <div class="card h-100">
                <div class="card-header"><?php echo Text::_('COM_SENSOMCP_MCPB_EXPORT'); ?></div>
                <div class="card-body">
                    <p class="mb-4"><?php echo Text::_('COM_SENSOMCP_MCPB_HINT'); ?></p>
                    <a class="btn btn-primary" href="<?php echo $mcpbUrl; ?>"><?php echo Text::_('COM_SENSOMCP_MCPB_DOWNLOAD'); ?></a>
                </div>
            </div>
        </div>
    </div>
</div>

<script>
function sensomcpFlash(btn) {
    var t = btn.innerHTML;
    btn.innerHTML = '✓';
    setTimeout(function () { btn.innerHTML = t; }, 1200);
}
function sensomcpCopyValue(id, btn) {
    navigator.clipboard.writeText(document.getElementById(id).value).then(function () { sensomcpFlash(btn); });
}
function sensomcpCopyText(id, btn) {
    navigator.clipboard.writeText(document.getElementById(id).innerText).then(function () { sensomcpFlash(btn); });
}
</script>
