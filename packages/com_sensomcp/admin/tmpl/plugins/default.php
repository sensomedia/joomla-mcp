<?php
/**
 * @copyright (C) 2026 Sensomedia. All rights reserved.
 * @license   GNU General Public License version 2 or later
 */

use Joomla\CMS\Language\Text;
use Joomla\CMS\Router\Route;
use Joomla\CMS\Session\Session;
use Sensomedia\Component\Sensomcp\Administrator\Helper\PluginRow;
use Sensomedia\Component\Sensomcp\Administrator\Helper\Updates;

\defined('_JEXEC') or die;

$this->document->getWebAssetManager()->useScript('bootstrap.collapse');

$dashboard = Route::_('index.php?option=com_sensomcp&view=dashboard');
$settings  = Route::_('index.php?option=com_sensomcp&view=settings');
$security  = Route::_('index.php?option=com_sensomcp&view=security');

$cntEnabled = $cntDisabled = $cntDangerOn = 0;
foreach ($this->catalogPlugins as $p) {
    foreach ($p['tools'] as $t) {
        $t['enabled'] ? $cntEnabled++ : $cntDisabled++;
        if ($t['risk'] === 'danger' && $t['enabled']) {
            $cntDangerOn++;
        }
    }
}
?>
<div class="p-4 sensomcp-ui"<?php echo ($this->theme !== '' ? ' data-smcp-theme="' . $this->escape($this->theme) . '"' : '') . ($this->themeMode !== '' ? ' data-smcp-mode="' . $this->escape($this->themeMode) . '"' : ''); ?>>
    <ul class="nav nav-tabs mb-4">
        <li class="nav-item"><a class="nav-link" href="<?php echo $dashboard; ?>"><?php echo Text::_('COM_SENSOMCP_DASHBOARD'); ?></a></li>
        <li class="nav-item"><a class="nav-link" href="<?php echo $settings; ?>"><?php echo Text::_('COM_SENSOMCP_SETTINGS'); ?></a></li>
        <li class="nav-item"><a class="nav-link" href="<?php echo $security; ?>"><?php echo Text::_('COM_SENSOMCP_SECURITY'); ?></a></li>
        <li class="nav-item"><span class="nav-link active"><?php echo Text::_('COM_SENSOMCP_PLUGINS'); ?></span></li>
    </ul>
    <a class="sensomcp-logo" href="https://www.sensomcp.com" target="_blank" rel="noopener noreferrer" aria-label="Senso MCP"></a>

    <?php echo Updates::banner(); ?>

    <div class="row mb-4">
        <?php
        $tiles = [
            ['enabled',  $cntEnabled,  'success',                               'COM_SENSOMCP_SECURITY_TILE_ENABLED'],
            ['disabled', $cntDisabled, 'secondary',                             'COM_SENSOMCP_SECURITY_TILE_DISABLED'],
            ['danger',   $cntDangerOn, $cntDangerOn > 0 ? 'danger' : 'success', 'COM_SENSOMCP_SECURITY_TILE_DANGER_ON'],
        ];
        foreach ($tiles as $tile) : ?>
            <div class="col-4"><div class="card text-center h-100"><div class="card-body p-2">
                <div class="fs-2 fw-bold text-<?php echo $tile[2]; ?>" id="smcp-cnt-<?php echo $tile[0]; ?>"><?php echo (int) $tile[1]; ?></div>
                <div class="small text-muted"><?php echo Text::_($tile[3]); ?></div>
            </div></div></div>
        <?php endforeach; ?>
    </div>

    <div class="alert alert-info d-flex align-items-center">
        <span class="icon-info-circle me-2" aria-hidden="true"></span>
        <div><?php echo Text::_('COM_SENSOMCP_SECURITY_RECONNECT_NOTE'); ?></div>
    </div>

    <div class="d-flex justify-content-between align-items-center mb-2 gap-2 flex-wrap">
        <h3 class="m-0"><?php echo Text::_('COM_SENSOMCP_CATALOG_TITLE'); ?></h3>
        <button type="button" class="btn btn-sm btn-outline-secondary" id="smcp-refresh">
            <span class="icon-refresh me-1" aria-hidden="true"></span><?php echo Text::_('COM_SENSOMCP_CATALOG_REFRESH'); ?>
        </button>
    </div>

    <div class="mb-3 position-relative">
        <span class="icon-search position-absolute top-50 translate-middle-y ms-3 text-muted" aria-hidden="true"></span>
        <input type="search" id="smcp-search" class="form-control ps-5" placeholder="<?php echo $this->escape(Text::_('COM_SENSOMCP_CATALOG_SEARCH')); ?>" autocomplete="off">
    </div>

    <div id="smcp-list">
        <?php foreach ($this->catalogPlugins as $plugin) {
            echo PluginRow::render($plugin);
        } ?>
    </div>
</div>

<script>
(function () {
    var TOKEN = <?php echo json_encode(Session::getFormToken()); ?>;
    var BASE  = 'index.php?option=com_sensomcp&format=json&task=plugins.';
    var L = {
        error:   <?php echo json_encode(Text::_('COM_SENSOMCP_AJAX_ERROR')); ?>,
        network: <?php echo json_encode(Text::_('COM_SENSOMCP_AJAX_NETWORK_ERROR')); ?>,
        confirmUninstall: <?php echo json_encode(Text::_('COM_SENSOMCP_CATALOG_UNINSTALL_CONFIRM')); ?>
    };
    var list = document.getElementById('smcp-list');
    if (!list) { return; }

    function post(task, data) {
        var body = new URLSearchParams(data || {});
        body.set(TOKEN, '1');
        return fetch(BASE + task, {
            method: 'POST',
            headers: { 'Content-Type': 'application/x-www-form-urlencoded', 'X-Requested-With': 'XMLHttpRequest' },
            body: body.toString()
        }).then(function (r) { return r.json(); });
    }

    // Recompte les 3 tuiles à partir de l'état RÉEL des interrupteurs d'outils du DOM.
    function recountTiles() {
        var en = 0, dis = 0, dg = 0;
        list.querySelectorAll('.smcp-tool').forEach(function (t) {
            if (t.checked) { en++; if (t.dataset.risk === 'danger') { dg++; } } else { dis++; }
        });
        var e = document.getElementById('smcp-cnt-enabled'); if (e) { e.textContent = en; }
        var d = document.getElementById('smcp-cnt-disabled'); if (d) { d.textContent = dis; }
        var g = document.getElementById('smcp-cnt-danger');
        if (g) { g.textContent = dg; g.classList.toggle('text-danger', dg > 0); g.classList.toggle('text-success', dg === 0); }
    }

    // Remplace une ligne par le HTML rafraîchi renvoyé par le serveur (sans reload).
    function replaceRow(row, html) {
        if (!html) { return; }
        var tmp = document.createElement('div');
        tmp.innerHTML = html.trim();
        var fresh = tmp.firstElementChild;
        if (fresh) { row.replaceWith(fresh); recountTiles(); }
    }

    function rowOf(el) { return el.closest('.smcp-row'); }

    function busy(btn, on) {
        if (!btn) { return; }
        if (on) {
            btn.dataset.orig = btn.innerHTML;
            btn.disabled = true;
            btn.innerHTML = '<span class="spinner-border spinner-border-sm" role="status" aria-hidden="true"></span>';
        } else if (btn.dataset.orig !== undefined) {
            btn.disabled = false; btn.innerHTML = btn.dataset.orig; delete btn.dataset.orig;
        }
    }

    // File d'attente : les installs/désinstalls/MAJ s'exécutent l'UNE APRÈS L'AUTRE
    // (jamais en parallèle), même si l'utilisateur clique vite sur plusieurs boutons.
    // Le spinner s'affiche immédiatement au clic ; seul le réseau est sérialisé.
    var chain = Promise.resolve();
    function enqueue(job) { chain = chain.then(job, job); return chain; }

    // --- Délégation : survit au remplacement des lignes -----------------------
    list.addEventListener('change', function (ev) {
        var el = ev.target;

        if (el.classList.contains('smcp-plugin') && !el.disabled) {
            var row = rowOf(el);
            el.disabled = true;
            post('ajaxTogglePlugin', { id: el.dataset.id, element: row ? row.dataset.element : '', enabled: el.checked ? 1 : 0 })
                .then(function (res) {
                    el.disabled = false;
                    if (!res || !res.ok) { el.checked = !el.checked; window.alert((res && res.error) || L.error); return; }
                    if (row && res.html) { replaceRow(row, res.html); }
                }).catch(function () { el.disabled = false; el.checked = !el.checked; });
            return;
        }

        if (el.classList.contains('smcp-tool')) {
            var on = el.checked;
            el.disabled = true;
            post('ajaxToggleTool', { tool: el.dataset.tool, enabled: on ? 1 : 0 }).then(function (res) {
                el.disabled = false;
                if (!res || !res.ok) { el.checked = !on; window.alert((res && res.error) || L.error); return; }
                recountTiles();
            }).catch(function () { el.disabled = false; el.checked = !on; });
        }
    });

    list.addEventListener('click', function (ev) {
        // Install / Réinstall / Mise à jour : un clic → download + install, ligne remplacée.
        var inst = ev.target.closest('.smcp-install');
        if (inst) {
            if (inst.dataset.busy) { return; }
            inst.dataset.busy = '1';
            var row = rowOf(inst);
            busy(inst, true);
            enqueue(function () {
                return post('ajaxInstall', { element: inst.dataset.element }).then(function (res) {
                    if (res && res.ok) { replaceRow(row, res.html); return; }
                    window.alert((res && res.error) || L.error);
                    busy(inst, false); delete inst.dataset.busy;
                }).catch(function () { window.alert(L.network); busy(inst, false); delete inst.dataset.busy; });
            });
            return;
        }

        // Désinstallation (corbeille) avec confirmation.
        var un = ev.target.closest('.smcp-uninstall');
        if (un) {
            if (un.dataset.busy) { return; }
            if (!window.confirm(L.confirmUninstall)) { return; }
            un.dataset.busy = '1';
            var row2 = rowOf(un);
            busy(un, true);
            enqueue(function () {
                return post('ajaxUninstall', { element: un.dataset.element }).then(function (res) {
                    if (res && res.ok) { replaceRow(row2, res.html); return; }
                    window.alert((res && res.error) || L.error);
                    busy(un, false); delete un.dataset.busy;
                }).catch(function () { window.alert(L.network); busy(un, false); delete un.dataset.busy; });
            });
            return;
        }

        // Clic sur les 2 premières colonnes du card-body → ouvre/ferme l'accordéon.
        // (3e colonne = boutons d'action, exclue ; l'interrupteur d'activation aussi.)
        var body = ev.target.closest('.smcp-cardbody');
        if (body && !ev.target.closest('.smcp-actions') && !ev.target.closest('.form-check')) {
            var cfg = body.parentElement.querySelector('.smcp-configure');
            if (cfg) { cfg.click(); }
        }
    });

    var refresh = document.getElementById('smcp-refresh');
    if (refresh) {
        refresh.addEventListener('click', function () {
            refresh.disabled = true;
            post('ajaxRefreshCatalog', {}).then(function () { location.reload(); })
                .catch(function () { refresh.disabled = false; });
        });
    }

    var search = document.getElementById('smcp-search');
    if (search) {
        search.addEventListener('input', function () {
            var q = search.value.trim().toLowerCase();
            list.querySelectorAll('.smcp-row').forEach(function (row) {
                row.hidden = q !== '' && row.dataset.name.indexOf(q) === -1;
            });
        });
    }
})();
</script>
