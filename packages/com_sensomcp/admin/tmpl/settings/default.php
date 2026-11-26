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

    <?php if ($d['file'] !== null && $d['writable'] && !$this->apiLoginEdit) : ?>
        <p class="small text-muted mb-0"><?php echo Text::_('COM_SENSOMCP_HTACCESS_SUPERUSER_ONLY'); ?></p>
    <?php elseif ($d['file'] !== null && $d['writable']) : ?>
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

<?php
// Trois blocs : prérequis communs (bloquent les deux modes), connexion OAuth, connexion par jeton (facultative).
$common      = array_values(array_filter($this->plugins, static fn (array $p): bool => !$p['token_only']));
$tokenPlugs  = array_values(array_filter($this->plugins, static fn (array $p): bool => $p['token_only']));
$commonOff   = \count(array_filter($common, static fn (array $p): bool => $p['installed'] && !$p['enabled']));
$commonOk    = $commonOff === 0 && !\in_array(false, array_column($common, 'installed'), true)
    && !empty($this->checks['auth_forwarded']) && !empty($this->checks['api']);
$oauthOk     = $commonOk && ($wellknownOk || $fallbackOk);
$tokenOn     = $tokenPlugs !== [] && !\in_array(false, array_column($tokenPlugs, 'enabled'), true);
$al          = $this->apiLogin;
$permsUrl    = Route::_('index.php?option=com_config') . '#page-permissions';
$step        = static fn (int $n): string => '<span class="badge rounded-pill bg-primary me-2">' . $n . '</span>';
$status      = static fn (string $cls, string $key): string => '<span class="badge ' . $cls . ' ms-2">' . Text::_($key) . '</span>';

/** Ligne de plugin : libellé + état / bouton Activer (badge gris pour un plugin facultatif). */
$pluginRow = function (array $p) use ($badge, $taskForm): void {
    ?>
    <li class="list-group-item d-flex justify-content-between align-items-center">
        <span><?php echo htmlspecialchars($p['label'], ENT_QUOTES); ?></span>
        <span class="text-nowrap">
            <?php
            if (!$p['installed']) {
                echo '<span class="badge bg-secondary">' . Text::_('COM_SENSOMCP_NOT_INSTALLED') . '</span>';
            } elseif ($p['enabled']) {
                echo $badge(true);
            } else {
                echo '<span class="badge ' . ($p['token_only'] ? 'bg-secondary' : 'bg-danger') . ' me-2">' . Text::_('COM_SENSOMCP_DISABLED') . '</span>';
                echo $taskForm('pluginsEnable', 'COM_SENSOMCP_PLUGIN_ENABLE', 'btn-outline-primary', '', ['plugin' => $p['key']]);
            }
            ?>
        </span>
    </li>
    <?php
};
?>
    <div class="card mb-4">
        <div class="card-header"><?php echo Text::_('COM_SENSOMCP_ENDPOINT'); ?></div>
        <div class="card-body">
            <div class="input-group">
                <input type="text" class="form-control" id="mcp-endpoint" readonly value="<?php echo htmlspecialchars($this->endpoint, ENT_QUOTES); ?>">
                <button class="btn btn-outline-secondary" type="button" onclick="sensomcpCopyValue('mcp-endpoint', this)"><?php echo Text::_('COM_SENSOMCP_COPY'); ?></button>
            </div>
            <p class="small text-muted mt-2 mb-0"><?php echo Text::_('COM_SENSOMCP_SETTINGS_INTRO'); ?></p>
        </div>
    </div>

    <!-- 1. Prérequis communs -->
    <div class="card mb-4 border-<?php echo $commonOk ? 'success' : 'danger'; ?>">
        <div class="card-header d-flex justify-content-between align-items-center">
            <span>
                <?php echo $step(1) . Text::_('COM_SENSOMCP_COMMON_TITLE'); ?>
                <?php echo $commonOk ? $status('bg-success', 'COM_SENSOMCP_OK') : $status('bg-danger', 'COM_SENSOMCP_STATUS_TO_FIX'); ?>
            </span>
            <?php if ($commonOff > 1) : ?>
                <?php echo $taskForm('pluginsEnable', 'COM_SENSOMCP_PLUGINS_ENABLE_ALL', 'btn-primary'); ?>
            <?php endif; ?>
        </div>
        <div class="card-body">
            <p class="small text-muted"><?php echo Text::_('COM_SENSOMCP_COMMON_HINT'); ?></p>
            <div class="row">
                <div class="col-lg-6 mb-3">
                    <h3 class="h6"><?php echo Text::_('COM_SENSOMCP_PLUGINS_STATUS'); ?></h3>
                    <ul class="list-group mb-3">
                        <?php foreach ($common as $p) { $pluginRow($p); } ?>
                    </ul>

                    <h3 class="h6"><?php echo Text::_('COM_SENSOMCP_API_LOGIN_TITLE'); ?></h3>
                    <div class="small">
                        <?php if ($al['all']) : ?>
                            <div class="alert alert-warning py-1 px-2 mb-2"><?php echo Text::_('COM_SENSOMCP_API_LOGIN_ALL'); ?></div>
                        <?php elseif ($al['onlySuper']) : ?>
                            <div class="alert alert-info py-1 px-2 mb-2"><?php echo Text::_('COM_SENSOMCP_API_LOGIN_ONLY_SUPER'); ?></div>
                        <?php endif; ?>
                        <div class="d-flex flex-wrap gap-1 mb-2">
                            <?php foreach ($al['selected'] as $g) : ?>
                                <?php
                                $note = match ($g['kind']) {
                                    'super'     => Text::_('COM_SENSOMCP_API_LOGIN_SUPER_BADGE'),
                                    'inherited' => $g['via'] !== '' ? Text::sprintf('COM_SENSOMCP_API_LOGIN_VIA', $g['via']) : '',
                                    default     => '',
                                };
                                ?>
                                <span class="badge <?php echo $g['kind'] === 'explicit' ? 'bg-info text-dark' : 'bg-light text-dark border'; ?> d-inline-flex align-items-center gap-1">
                                    <?php echo $this->escape($g['title']); ?>
                                    <?php if ($note !== '') : ?><span class="fw-normal">(<?php echo $this->escape($note); ?>)</span><?php endif; ?>
                                    <?php if ($g['kind'] === 'explicit' && $this->apiLoginEdit) : ?>
                                        <form method="post" action="<?php echo Route::_('index.php?option=com_sensomcp'); ?>" class="d-inline"
                                              onsubmit="return confirm(<?php echo $this->escape(json_encode(Text::sprintf('COM_SENSOMCP_API_LOGIN_REMOVE_CONFIRM', $g['title']))); ?>);">
                                            <input type="hidden" name="task" value="settings.apiLoginGroupRemove">
                                            <input type="hidden" name="group_id" value="<?php echo (int) $g['id']; ?>">
                                            <?php echo HTMLHelper::_('form.token'); ?>
                                            <button type="submit" class="btn-close btn-close-sm" style="font-size:.55rem" aria-label="<?php echo $this->escape(Text::sprintf('COM_SENSOMCP_API_LOGIN_REMOVE', $g['title'])); ?>" title="<?php echo $this->escape(Text::sprintf('COM_SENSOMCP_API_LOGIN_REMOVE', $g['title'])); ?>"></button>
                                        </form>
                                    <?php endif; ?>
                                </span>
                            <?php endforeach; ?>
                        </div>
                        <?php if ($this->apiLoginEdit && $al['available'] !== []) : ?>
                            <form method="post" action="<?php echo Route::_('index.php?option=com_sensomcp'); ?>" class="d-flex gap-1">
                                <input type="hidden" name="task" value="settings.apiLoginGroupAdd">
                                <?php echo HTMLHelper::_('form.token'); ?>
                                <select name="group_id" class="form-select form-select-sm" aria-label="<?php echo $this->escape(Text::_('COM_SENSOMCP_TOKEN_GROUP_ADD')); ?>">
                                    <?php foreach ($al['available'] as $g) : ?>
                                        <option value="<?php echo (int) $g['id']; ?>"><?php echo str_repeat('– ', $g['level']) . $this->escape($g['title']); ?></option>
                                    <?php endforeach; ?>
                                </select>
                                <button type="submit" class="btn btn-sm btn-outline-primary text-nowrap"><?php echo Text::_('COM_SENSOMCP_TOKEN_GROUP_ADD'); ?></button>
                            </form>
                        <?php elseif (!$this->apiLoginEdit) : ?>
                            <div class="text-muted"><?php echo Text::_('COM_SENSOMCP_API_LOGIN_READONLY'); ?></div>
                        <?php endif; ?>
                        <div class="text-muted mt-1">
                            <?php echo Text::_('COM_SENSOMCP_API_LOGIN_HINT'); ?>
                            <a href="<?php echo $permsUrl; ?>"><?php echo Text::_('COM_SENSOMCP_API_LOGIN_LINK'); ?></a>
                        </div>
                    </div>
                </div>
                <div class="col-lg-6 mb-3">
                    <h3 class="h6"><?php echo Text::_('COM_SENSOMCP_API_ACCESS'); ?> <span class="text-muted small fw-normal">— <?php echo Text::_('COM_SENSOMCP_SERVER'); ?> : <strong><?php echo htmlspecialchars($serverLabel, ENT_QUOTES); ?></strong></span></h3>
                    <ul class="list-group">
                        <li class="list-group-item d-flex justify-content-between">
                            <span><code>Authorization</code> — <?php echo Text::_('COM_SENSOMCP_AUTH_FORWARDED'); ?></span>
                            <?php echo $badge((bool) ($this->checks['auth_forwarded'] ?? false)); ?>
                        </li>
                        <li class="list-group-item d-flex justify-content-between">
                            <span><code>/api/index.php</code> — <?php echo Text::_('COM_SENSOMCP_API_ROUTING'); ?></span>
                            <?php echo $badge((bool) ($this->checks['api'] ?? false)); ?>
                        </li>
                    </ul>
                </div>
            </div>

            <?php foreach ($otherDirectives as $i => $d) : ?>
                <div class="card mt-2 border-danger">
                    <div class="card-header">
                        <?php echo Text::_($d['title']); ?>
                        <span class="badge bg-danger ms-2"><?php echo Text::_('COM_SENSOMCP_LEVEL_ALL'); ?></span>
                    </div>
                    <div class="card-body">
                        <?php $renderDirective($d, $i); ?>
                    </div>
                </div>
            <?php endforeach; ?>
        </div>
    </div>

    <!-- 2. Connexion OAuth -->
    <div class="card mb-4 border-<?php echo $oauthOk ? 'success' : 'warning'; ?>">
        <div class="card-header">
            <?php echo $step(2) . Text::_('COM_SENSOMCP_OAUTH_TITLE'); ?>
            <span class="badge bg-primary-subtle text-primary-emphasis ms-2"><?php echo Text::_('COM_SENSOMCP_RECOMMENDED'); ?></span>
            <?php echo $oauthOk ? $status('bg-success', 'COM_SENSOMCP_STATUS_READY') : $status('bg-warning text-dark', 'COM_SENSOMCP_STATUS_TO_FIX'); ?>
        </div>
        <div class="card-body">
            <p class="small text-muted"><?php echo Text::_('COM_SENSOMCP_OAUTH_HINT'); ?></p>
            <ul class="list-group mb-3">
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

            <?php if ($fallbackOn || !$wellknownOk) : ?>
                <div class="border rounded p-3 border-<?php echo $fallbackOk ? 'success' : 'warning'; ?>">
                    <p class="fw-semibold mb-2"><?php echo Text::_('COM_SENSOMCP_OAUTH_CARD_TITLE'); ?></p>
                    <?php if ($fallbackOn) : ?>
                        <p class="mb-2"><?php echo Text::_($fallbackOk ? 'COM_SENSOMCP_OAUTH_FALLBACK_ACTIVE' : 'COM_SENSOMCP_OAUTH_FALLBACK_BROKEN'); ?></p>
                        <?php if ($fallbackOk && $wellknownOk) : ?>
                            <p class="small text-muted mb-2"><?php echo Text::_('COM_SENSOMCP_OAUTH_FALLBACK_STANDARD_OK'); ?></p>
                        <?php endif; ?>
                        <?php echo $taskForm('oauthFallbackOff', 'COM_SENSOMCP_OAUTH_FALLBACK_OFF', 'btn-outline-secondary'); ?>
                    <?php else : ?>
                        <p class="mb-3"><?php echo Text::_('COM_SENSOMCP_OAUTH_CARD_PROBLEM'); ?></p>
                        <?php if ($this->apiLoginEdit) : ?>
                            <?php echo $taskForm('oauthRepair', 'COM_SENSOMCP_OAUTH_REPAIR', 'btn-primary', Text::_('COM_SENSOMCP_OAUTH_REPAIR_CONFIRM')); ?>
                        <?php else : ?>
                            <p class="small text-muted mb-0"><?php echo Text::_('COM_SENSOMCP_HTACCESS_SUPERUSER_ONLY'); ?></p>
                        <?php endif; ?>
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
            <?php endif; ?>
        </div>
    </div>

    <?php if ($this->directives !== []) : ?>
        <p class="text-muted small"><?php echo Text::_('COM_SENSOMCP_DIR_RECHECK'); ?></p>
    <?php endif; ?>

    <!-- 3. Connexion par jeton API Joomla (facultative) -->
    <div class="card mb-4">
        <div class="card-header">
            <?php echo $step(3) . Text::_('COM_SENSOMCP_TOKEN_TITLE'); ?>
            <span class="badge bg-light text-dark border ms-2"><?php echo Text::_('COM_SENSOMCP_OPTIONAL'); ?></span>
            <?php echo $tokenOn ? $status('bg-success', 'COM_SENSOMCP_STATUS_ACTIVE') : $status('bg-secondary', 'COM_SENSOMCP_STATUS_OFF'); ?>
        </div>
        <div class="card-body">
            <p class="small text-muted"><?php echo Text::_('COM_SENSOMCP_TOKEN_HINT'); ?></p>
            <div class="row">
                <div class="col-lg-6 mb-3">
                    <ul class="list-group">
                        <?php foreach ($tokenPlugs as $p) { $pluginRow($p); } ?>
                    </ul>
                </div>
                <div class="col-lg-6 mb-3">
                    <?php $userToken = array_values(array_filter($tokenPlugs, static fn (array $p): bool => $p['key'] === 'user.token'))[0] ?? null; ?>
                    <?php if ($userToken && $userToken['installed']) : ?>
                        <?php $tg = $this->tokenGroups; ?>
                        <div class="small">
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

                            <?php $nt = $this->noToken; ?>
                            <?php if (!$nt['all']) : ?>
                                <?php if ($nt['total'] === 0) : ?>
                                    <div class="text-success mt-2"><?php echo Text::_('COM_SENSOMCP_TOKEN_MISSING_NONE'); ?></div>
                                <?php else : ?>
                                    <details class="mt-2">
                                        <summary><?php echo Text::sprintf('COM_SENSOMCP_TOKEN_MISSING_TITLE', $nt['total']); ?></summary>
                                        <p class="text-muted mt-1 mb-1"><?php echo Text::_('COM_SENSOMCP_TOKEN_MISSING_HINT'); ?></p>
                                        <ul class="list-unstyled mb-0">
                                            <?php foreach ($nt['users'] as $u) : ?>
                                                <li>
                                                    <a href="<?php echo Route::_('index.php?option=com_users&task=user.edit&id=' . (int) $u['id']); ?>"><?php echo $this->escape($u['name']); ?></a>
                                                    <span class="text-muted">(<?php echo $this->escape($u['username']); ?>)</span>
                                                    — <?php echo Text::_($u['state'] === 'disabled' ? 'COM_SENSOMCP_TOKEN_MISSING_DISABLED' : 'COM_SENSOMCP_TOKEN_MISSING_NOTOKEN'); ?>
                                                    <?php if (!$u['login']) : ?>
                                                        <span class="badge bg-warning text-dark ms-1"><?php echo Text::_('COM_SENSOMCP_TOKEN_MISSING_NOLOGIN'); ?></span>
                                                    <?php endif; ?>
                                                </li>
                                            <?php endforeach; ?>
                                        </ul>
                                        <?php if ($nt['total'] > \count($nt['users'])) : ?>
                                            <div class="text-muted"><?php echo Text::sprintf('COM_SENSOMCP_TOKEN_MISSING_MORE', $nt['total'] - \count($nt['users'])); ?></div>
                                        <?php endif; ?>
                                    </details>
                                <?php endif; ?>
                            <?php endif; ?>
                        </div>
                    <?php endif; ?>
                </div>
            </div>

            <details<?php echo $tokenOn ? ' open' : ''; ?>>
                <summary class="fw-semibold"><?php echo Text::_('COM_SENSOMCP_TOKEN_CLIENT_CONFIG'); ?></summary>
                <?php if (!$tokenOn) : ?>
                    <div class="alert alert-secondary small py-2 mt-2"><?php echo Text::_('COM_SENSOMCP_TOKEN_CONFIG_OFF'); ?></div>
                <?php endif; ?>
                <div class="row mt-2">
                    <div class="col-lg-7 mb-3">
                        <div class="d-flex justify-content-between align-items-center mb-1">
                            <h3 class="h6 mb-0"><?php echo Text::_('COM_SENSOMCP_CONFIG_JSON'); ?></h3>
                            <button class="btn btn-sm btn-outline-secondary" type="button" onclick="sensomcpCopyText('mcp-config', this)"><?php echo Text::_('COM_SENSOMCP_COPY'); ?></button>
                        </div>
                        <pre id="mcp-config" class="mb-1 p-2 bg-body-secondary border rounded" style="overflow:auto;max-height:340px"><?php echo htmlspecialchars($this->configJson, ENT_QUOTES); ?></pre>
                        <small class="text-muted"><?php echo Text::_('COM_SENSOMCP_CONFIG_JSON_HINT'); ?></small>
                    </div>
                    <div class="col-lg-5 mb-3">
                        <h3 class="h6"><?php echo Text::_('COM_SENSOMCP_MCPB_EXPORT'); ?></h3>
                        <p class="small mb-3"><?php echo Text::_('COM_SENSOMCP_MCPB_HINT'); ?></p>
                        <a class="btn btn-primary btn-sm" href="<?php echo $mcpbUrl; ?>"><?php echo Text::_('COM_SENSOMCP_MCPB_DOWNLOAD'); ?></a>
                    </div>
                </div>
            </details>
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
