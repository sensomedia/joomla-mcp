<?php
/**
 * @copyright (C) 2026 Sensomedia. All rights reserved.
 * @license   GNU General Public License version 2 or later
 */

use Joomla\CMS\HTML\HTMLHelper;
use Joomla\CMS\Language\Text;
use Joomla\CMS\Router\Route;
use Joomla\CMS\Session\Session;
use Joomla\CMS\Uri\Uri;
use Sensomedia\Component\Sensomcp\Administrator\Helper\Updates;

\defined('_JEXEC') or die;

$dashboard = Route::_('index.php?option=com_sensomcp&view=dashboard');
$mcpbUrl   = Route::_('index.php?option=com_sensomcp&task=config.mcpb&' . Session::getFormToken() . '=1');

$badge = static function (bool $ok): string {
    return $ok
        ? '<span class="badge bg-success">' . Text::_('COM_SENSOMCP_OK') . '</span>'
        : '<span class="badge bg-danger">' . Text::_('COM_SENSOMCP_KO') . '</span>';
};

$serverLabel = ['nginx' => 'nginx', 'apache' => 'Apache', 'litespeed' => 'LiteSpeed', 'unknown' => Text::_('COM_SENSOMCP_SERVER_UNKNOWN')][$this->server] ?? $this->server;

$notNeeded = '<span class="badge bg-secondary">' . Text::_('COM_SENSOMCP_NOT_NEEDED') . '</span>';

// Carte « Connexion OAuth » : /.well-known/ (règles standard) OU découverte sous /api/ (repli).
$fallbackOn  = \array_key_exists('wellknown_fallback', $this->checks);
$fallbackOk  = !empty($this->checks['wellknown_fallback']);
$wellknownOk = !empty($this->checks['wellknown']) && ($this->basePath === '' || !empty($this->checks['wellknown_domain']));

$oauthDirectives = array_values(array_filter($this->directives, static fn (array $d): bool => \in_array($d['id'], ['wellknown', 'wellknown_domain'], true)));
$otherDirectives = array_values(array_filter($this->directives, static fn (array $d): bool => !\in_array($d['id'], ['wellknown', 'wellknown_domain'], true)));

// nginx seul : la directive nginx d'abord, le .htaccess en alternative (Plesk :
// nginx passe à Apache les URL sans .php). Apache / LiteSpeed : .htaccess seul.
$htaccessOnly = \in_array($this->server, ['apache', 'litespeed'], true);
$host         = Uri::getInstance()->getHost();

/** Petit formulaire POST d'une tâche settings.* (jeton CSRF inclus). */
$taskForm = function (string $task, string $label, string $btnClass, string $confirm = '', array $extra = []): string {
    $onsubmit = $confirm !== '' ? ' onsubmit="return confirm(' . $this->escape(json_encode($confirm)) . ');"' : '';
    $hidden   = '';

    foreach ($extra as $name => $value) {
        $hidden .= '<input type="hidden" name="' . $this->escape($name) . '" value="' . $this->escape((string) $value) . '">';
    }

    return '<form method="post" action="' . Route::_('index.php?option=com_sensomcp') . '" class="d-inline"' . $onsubmit . '>'
        . '<input type="hidden" name="task" value="settings.' . $task . '">'
        . $hidden
        . HTMLHelper::_('form.token')
        . '<button type="submit" class="btn btn-sm ' . $btnClass . '">' . Text::_($label) . '</button>'
        . '</form>';
};

/** Corps d'une directive serveur : règle nginx, bloc .htaccess, boutons Ajouter / Retirer. */
$renderDirective = function (array $d, int $i) use ($htaccessOnly, $host): void {
    $k = $d['id'] . $i;
    ?>
    <?php if (!$htaccessOnly) : ?>
        <h5 class="h6"><?php echo Text::_('COM_SENSOMCP_DIR_NGINX'); ?></h5>
        <p class="small mb-2"><?php echo Text::sprintf($d['nginxWhere'], $this->escape($host)); ?></p>
        <div class="d-flex justify-content-end mb-1">
            <button class="btn btn-sm btn-outline-secondary" type="button" onclick="sensomcpCopyText('mcp-dir-n<?php echo $k; ?>', this)"><?php echo Text::_('COM_SENSOMCP_COPY'); ?></button>
        </div>
        <pre id="mcp-dir-n<?php echo $k; ?>" class="mb-4 p-2 bg-body-secondary border rounded" style="max-height:260px;overflow:auto;"><?php echo htmlspecialchars($d['nginx'], ENT_QUOTES); ?></pre>
        <?php if ($d['nginxPlesk'] !== '') : ?>
            <p class="small mb-2"><?php echo Text::_('COM_SENSOMCP_DIR_NGINX_PLESK'); ?></p>
            <div class="d-flex justify-content-end mb-1">
                <button class="btn btn-sm btn-outline-secondary" type="button" onclick="sensomcpCopyText('mcp-dir-p<?php echo $k; ?>', this)"><?php echo Text::_('COM_SENSOMCP_COPY'); ?></button>
            </div>
            <pre id="mcp-dir-p<?php echo $k; ?>" class="mb-4 p-2 bg-body-secondary border rounded" style="max-height:260px;overflow:auto;"><?php echo htmlspecialchars($d['nginxPlesk'], ENT_QUOTES); ?></pre>
        <?php endif; ?>
        <h5 class="h6"><?php echo Text::_('COM_SENSOMCP_DIR_HTACCESS_ALT'); ?></h5>
    <?php endif; ?>

    <?php if ($d['file'] === null) : ?>
        <p class="small mb-2"><?php echo Text::_('COM_SENSOMCP_HTACCESS_NODIR_HINT'); ?></p>
    <?php else : ?>
        <p class="small mb-1">
            <?php echo Text::_('COM_SENSOMCP_DIR_FILE'); ?> <code><?php echo $this->escape($d['file']); ?></code>
            <?php if (!$d['exists']) : ?>
                <span class="text-muted">(<?php echo Text::_('COM_SENSOMCP_DIR_FILE_NEW'); ?>)</span>
            <?php endif; ?>
        </p>
    <?php endif; ?>
    <?php if ($d['domainRoot']) : ?>
        <p class="small mb-1"><?php echo Text::_('COM_SENSOMCP_DIR_DOMAIN_ROOT_NOTE'); ?></p>
    <?php endif; ?>
    <p class="small mb-2"><?php echo Text::_('COM_SENSOMCP_DIR_PLACE_TOP'); ?></p>
    <div class="d-flex justify-content-end mb-1">
        <button class="btn btn-sm btn-outline-secondary" type="button" onclick="sensomcpCopyText('mcp-dir-h<?php echo $k; ?>', this)"><?php echo Text::_('COM_SENSOMCP_COPY'); ?></button>
    </div>
    <pre id="mcp-dir-h<?php echo $k; ?>" class="mb-3 p-2 bg-body-secondary border rounded" style="max-height:260px;overflow:auto;"><?php echo htmlspecialchars($d['htaccess'], ENT_QUOTES); ?></pre>

    <?php if ($d['present']) : ?>
        <div class="alert alert-warning small py-2"><?php echo Text::_('COM_SENSOMCP_DIR_PRESENT_NO_EFFECT'); ?></div>
    <?php endif; ?>

    <?php if ($d['file'] !== null && $d['writable']) : ?>
        <form method="post" action="<?php echo Route::_('index.php?option=com_sensomcp'); ?>" class="d-inline"
              onsubmit="return confirm(<?php echo $this->escape(json_encode(Text::sprintf($d['present'] ? 'COM_SENSOMCP_DIR_REMOVE_CONFIRM' : 'COM_SENSOMCP_DIR_APPLY_CONFIRM', $d['file']))); ?>);">
            <input type="hidden" name="task" value="settings.<?php echo $d['present'] ? 'htaccessRemove' : 'htaccessAdd'; ?>">
            <input type="hidden" name="directive" value="<?php echo $this->escape($d['id']); ?>">
            <?php echo HTMLHelper::_('form.token'); ?>
            <?php if ($d['present']) : ?>
                <button type="submit" class="btn btn-sm btn-outline-danger"><?php echo Text::_('COM_SENSOMCP_DIR_REMOVE'); ?></button>
            <?php else : ?>
                <button type="submit" class="btn btn-sm btn-primary"><?php echo Text::_('COM_SENSOMCP_DIR_APPLY'); ?></button>
            <?php endif; ?>
        </form>
    <?php elseif ($d['file'] !== null) : ?>
        <p class="small text-muted mb-0"><?php echo Text::sprintf('COM_SENSOMCP_HTACCESS_NOTWRITABLE', $this->escape($d['file'])); ?></p>
    <?php endif; ?>
    <?php
};
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
                <?php $disabledCount = \count(array_filter($this->plugins, static fn (array $p): bool => $p['installed'] && !$p['enabled'])); ?>
                <div class="card-header d-flex justify-content-between align-items-center">
                    <span><?php echo Text::_('COM_SENSOMCP_PLUGINS_STATUS'); ?></span>
                    <?php if ($disabledCount > 1) : ?>
                        <?php echo $taskForm('pluginsEnable', 'COM_SENSOMCP_PLUGINS_ENABLE_ALL', 'btn-primary'); ?>
                    <?php endif; ?>
                </div>
                <ul class="list-group list-group-flush">
                    <?php foreach ($this->plugins as $p) : ?>
                        <li class="list-group-item">
                            <div class="d-flex justify-content-between align-items-center">
                                <span><?php echo htmlspecialchars($p['label'], ENT_QUOTES); ?></span>
                                <span>
                                    <?php
                                    if (!$p['installed']) {
                                        echo '<span class="badge bg-secondary">' . Text::_('COM_SENSOMCP_NOT_INSTALLED') . '</span>';
                                    } elseif ($p['enabled']) {
                                        echo $badge(true);
                                    } else {
                                        echo '<span class="badge bg-warning me-2">' . Text::_('COM_SENSOMCP_DISABLED') . '</span>';
                                        echo $taskForm('pluginsEnable', 'COM_SENSOMCP_PLUGIN_ENABLE', 'btn-outline-primary', '', ['plugin' => $p['key']]);
                                    }
                                    ?>
                                </span>
                            </div>

                            <?php if ($p['key'] === 'user.token' && $p['installed']) : ?>
                                <?php $tg = $this->tokenGroups; ?>
                                <div class="mt-2 small">
                                    <div class="text-muted mb-1"><?php echo Text::_('COM_SENSOMCP_TOKEN_GROUPS'); ?></div>
                                    <?php if ($tg['all']) : ?>
                                        <div class="alert alert-warning py-1 px-2 mb-2"><?php echo Text::_('COM_SENSOMCP_TOKEN_GROUPS_ALL'); ?></div>
                                    <?php else : ?>
                                        <div class="d-flex flex-wrap gap-1 mb-2">
                                            <?php foreach ($tg['selected'] as $g) : ?>
                                                <span class="badge bg-info text-dark d-inline-flex align-items-center gap-1">
                                                    <?php echo $this->escape($g['title']); ?>
                                                    <?php if (\count($tg['selected']) > 1) : ?>
                                                        <form method="post" action="<?php echo Route::_('index.php?option=com_sensomcp'); ?>" class="d-inline"
                                                              onsubmit="return confirm(<?php echo $this->escape(json_encode(Text::sprintf('COM_SENSOMCP_TOKEN_GROUP_REMOVE_CONFIRM', $g['title']))); ?>);">
                                                            <input type="hidden" name="task" value="settings.tokenGroupRemove">
                                                            <input type="hidden" name="group_id" value="<?php echo (int) $g['id']; ?>">
                                                            <?php echo HTMLHelper::_('form.token'); ?>
                                                            <button type="submit" class="btn-close btn-close-sm" style="font-size:.55rem" aria-label="<?php echo $this->escape(Text::sprintf('COM_SENSOMCP_TOKEN_GROUP_REMOVE', $g['title'])); ?>" title="<?php echo $this->escape(Text::sprintf('COM_SENSOMCP_TOKEN_GROUP_REMOVE', $g['title'])); ?>"></button>
                                                        </form>
                                                    <?php endif; ?>
                                                </span>
                                            <?php endforeach; ?>
                                        </div>
                                    <?php endif; ?>
                                    <?php if ($tg['available'] !== []) : ?>
                                        <form method="post" action="<?php echo Route::_('index.php?option=com_sensomcp'); ?>" class="d-flex gap-1">
                                            <input type="hidden" name="task" value="settings.tokenGroupAdd">
                                            <?php echo HTMLHelper::_('form.token'); ?>
                                            <select name="group_id" class="form-select form-select-sm" aria-label="<?php echo $this->escape(Text::_('COM_SENSOMCP_TOKEN_GROUP_ADD')); ?>">
                                                <?php foreach ($tg['available'] as $g) : ?>
                                                    <option value="<?php echo (int) $g['id']; ?>"><?php echo str_repeat('– ', $g['level']) . $this->escape($g['title']); ?></option>
                                                <?php endforeach; ?>
                                            </select>
                                            <button type="submit" class="btn btn-sm btn-outline-primary text-nowrap"><?php echo Text::_($tg['all'] ? 'COM_SENSOMCP_TOKEN_GROUP_RESTRICT' : 'COM_SENSOMCP_TOKEN_GROUP_ADD'); ?></button>
                                        </form>
                                    <?php endif; ?>
                                    <div class="text-muted mt-1"><?php echo Text::_('COM_SENSOMCP_TOKEN_GROUPS_HINT'); ?></div>
                                </div>
                            <?php endif; ?>
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
                        <?php echo $fallbackOk && empty($this->checks['wellknown']) ? $notNeeded : $badge((bool) ($this->checks['wellknown'] ?? false)); ?>
                    </li>
                    <?php if (\array_key_exists('wellknown_domain', $this->checks)) : ?>
                        <li class="list-group-item d-flex justify-content-between">
                            <span><code>/.well-known/…<?php echo htmlspecialchars($this->basePath, ENT_QUOTES); ?></code> — <?php echo Text::_('COM_SENSOMCP_WELLKNOWN_DOMAIN'); ?></span>
                            <?php echo $fallbackOk && empty($this->checks['wellknown_domain']) ? $notNeeded : $badge((bool) $this->checks['wellknown_domain']); ?>
                        </li>
                    <?php endif; ?>
                    <?php if ($fallbackOn) : ?>
                        <li class="list-group-item d-flex justify-content-between">
                            <span><code>/api/…/.well-known/openid-configuration</code> — <?php echo Text::_('COM_SENSOMCP_WELLKNOWN_FALLBACK'); ?></span>
                            <?php echo $badge($fallbackOk); ?>
                        </li>
                    <?php endif; ?>
                </ul>
            </div>
        </div>
    </div>

    <?php if ($otherDirectives === [] && ($wellknownOk || $fallbackOk)) : ?>
        <div class="alert alert-success">
            <strong>✓</strong> <?php echo Text::_('COM_SENSOMCP_SERVER_OK'); ?>
        </div>
    <?php endif; ?>

    <?php if ($fallbackOn || !$wellknownOk) : ?>
        <div class="card mb-4 border-<?php echo $fallbackOk ? 'success' : 'warning'; ?>">
            <div class="card-header">
                <?php echo Text::_('COM_SENSOMCP_OAUTH_CARD_TITLE'); ?>
                <?php if ($fallbackOk) : ?>
                    <span class="badge bg-success ms-2"><?php echo Text::_('COM_SENSOMCP_OK'); ?></span>
                <?php else : ?>
                    <span class="badge bg-warning ms-2"><?php echo Text::_('COM_SENSOMCP_LEVEL_OAUTH'); ?></span>
                <?php endif; ?>
            </div>
            <div class="card-body">
                <?php if ($fallbackOn) : ?>
                    <p class="mb-2"><?php echo Text::_($fallbackOk ? 'COM_SENSOMCP_OAUTH_FALLBACK_ACTIVE' : 'COM_SENSOMCP_OAUTH_FALLBACK_BROKEN'); ?></p>
                    <?php if ($fallbackOk && $wellknownOk) : ?>
                        <p class="small text-muted mb-2"><?php echo Text::_('COM_SENSOMCP_OAUTH_FALLBACK_STANDARD_OK'); ?></p>
                    <?php endif; ?>
                    <?php echo $taskForm('oauthFallbackOff', 'COM_SENSOMCP_OAUTH_FALLBACK_OFF', 'btn-outline-secondary'); ?>
                <?php else : ?>
                    <p class="mb-3"><?php echo Text::_('COM_SENSOMCP_OAUTH_CARD_PROBLEM'); ?></p>
                    <?php echo $taskForm('oauthRepair', 'COM_SENSOMCP_OAUTH_REPAIR', 'btn-primary', Text::_('COM_SENSOMCP_OAUTH_REPAIR_CONFIRM')); ?>
                    <p class="small text-muted mt-2 mb-0"><?php echo Text::_('COM_SENSOMCP_OAUTH_REPAIR_HINT'); ?></p>
                <?php endif; ?>

                <?php if (!$fallbackOk && !$wellknownOk) : ?>
                    <details class="mt-3"<?php echo $this->oauthManual ? ' open' : ''; ?>>
                        <summary class="fw-semibold"><?php echo Text::_('COM_SENSOMCP_OAUTH_MANUAL'); ?></summary>

                        <h4 class="h6 mt-3"><?php echo Text::_('COM_SENSOMCP_OAUTH_MANUAL_STANDARD'); ?></h4>
                        <?php foreach ($oauthDirectives as $i => $d) : ?>
                            <div class="border rounded p-3 mb-3">
                                <p class="fw-semibold small mb-2"><?php echo Text::_($d['title']); ?></p>
                                <?php $renderDirective($d, $i); ?>
                            </div>
                        <?php endforeach; ?>

                        <?php if (!$fallbackOn) : ?>
                            <h4 class="h6 mt-3"><?php echo Text::_('COM_SENSOMCP_OAUTH_MANUAL_FALLBACK_TITLE'); ?></h4>
                            <p class="small mb-2"><?php echo Text::_('COM_SENSOMCP_OAUTH_MANUAL_FALLBACK'); ?></p>
                            <?php echo $taskForm('oauthFallbackOn', 'COM_SENSOMCP_OAUTH_FALLBACK_ON', 'btn-outline-primary'); ?>
                        <?php endif; ?>

                        <h4 class="h6 mt-4"><?php echo Text::_('COM_SENSOMCP_OAUTH_HOST_REQUEST'); ?></h4>
                        <div class="d-flex justify-content-end mb-1">
                            <button class="btn btn-sm btn-outline-secondary" type="button" onclick="sensomcpCopyText('mcp-host-request', this)"><?php echo Text::_('COM_SENSOMCP_COPY'); ?></button>
                        </div>
                        <pre id="mcp-host-request" class="mb-0 p-2 bg-body-secondary border rounded" style="white-space:pre-wrap;"><?php echo htmlspecialchars($this->hostRequest, ENT_QUOTES); ?></pre>
                    </details>
                <?php endif; ?>
            </div>
        </div>
    <?php endif; ?>

    <?php foreach ($otherDirectives as $i => $d) : ?>
        <div class="card mb-4 border-<?php echo $d['level'] === 'all' ? 'danger' : 'warning'; ?>">
            <div class="card-header">
                <?php echo Text::_($d['title']); ?>
                <?php if ($d['level'] === 'all') : ?>
                    <span class="badge bg-danger ms-2"><?php echo Text::_('COM_SENSOMCP_LEVEL_ALL'); ?></span>
                <?php else : ?>
                    <span class="badge bg-warning ms-2"><?php echo Text::_('COM_SENSOMCP_LEVEL_OAUTH'); ?></span>
                <?php endif; ?>
            </div>
            <div class="card-body">
                <?php $renderDirective($d, $i); ?>
            </div>
        </div>
    <?php endforeach; ?>

    <?php if ($this->directives !== []) : ?>
        <p class="text-muted small"><?php echo Text::_('COM_SENSOMCP_DIR_RECHECK'); ?></p>
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
