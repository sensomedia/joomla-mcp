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

$wa = $this->document->getWebAssetManager();
$wa->registerAndUseScript('com_sensomcp.chartjs', 'https://cdn.jsdelivr.net/npm/chart.js@4', [], ['defer' => true]);

$s        = $this->stats;
$settings = Route::_('index.php?option=com_sensomcp&view=settings');
$security = Route::_('index.php?option=com_sensomcp&view=security');

// Données du graphe (labels jj/mm + valeurs).
$chartLabels = [];
$chartData   = [];

foreach ($this->perDay as $day => $count) {
    $chartLabels[] = substr($day, 8, 2) . '/' . substr($day, 5, 2);
    $chartData[]   = (int) $count;
}
?>
<div class="p-4 sensomcp-ui"<?php echo ($this->theme !== '' ? ' data-smcp-theme="' . $this->escape($this->theme) . '"' : '') . ($this->themeMode !== '' ? ' data-smcp-mode="' . $this->escape($this->themeMode) . '"' : ''); ?>>
    <ul class="nav nav-tabs mb-4">
        <li class="nav-item"><span class="nav-link active"><?php echo Text::_('COM_SENSOMCP_DASHBOARD'); ?></span></li>
        <li class="nav-item"><a class="nav-link" href="<?php echo $settings; ?>"><?php echo Text::_('COM_SENSOMCP_SETTINGS'); ?></a></li>
        <li class="nav-item"><a class="nav-link" href="<?php echo $security; ?>"><?php echo Text::_('COM_SENSOMCP_SECURITY'); ?></a></li>
        <li class="nav-item"><a class="nav-link" href="<?php echo Route::_('index.php?option=com_sensomcp&view=plugins'); ?>"><?php echo Text::_('COM_SENSOMCP_PLUGINS'); ?></a></li>
    </ul>
    <a class="sensomcp-logo" href="https://www.sensomcp.com" target="_blank" rel="noopener noreferrer" aria-label="Senso MCP"></a>

    <?php echo Updates::banner(); ?>

    <?php // --- Section Licence (AJAX vers la boutique) --- ?>
    <div class="card mb-4" id="smcp-license">
        <div class="card-header d-flex justify-content-between align-items-center">
            <span><?php echo Text::_('COM_SENSOMCP_LICENSE'); ?></span>
            <span id="smcp-lic-tier" class="badge bg-secondary">…</span>
        </div>
        <div class="card-body">
            <div id="smcp-lic-banner"></div>
            <div class="row g-2 align-items-end mb-2">
                <div class="col-md-8">
                    <label class="form-label" for="smcp-lic-key"><?php echo Text::_('COM_SENSOMCP_LICENSE_KEY'); ?></label>
                    <input type="text" id="smcp-lic-key" class="form-control" placeholder="<?php echo Text::_('COM_SENSOMCP_LICENSE_KEY_PLACEHOLDER'); ?>" autocomplete="off" spellcheck="false">
                </div>
                <div class="col-md-4">
                    <button type="button" id="smcp-lic-save" class="btn btn-primary w-100"><?php echo Text::_('COM_SENSOMCP_LICENSE_SAVE'); ?></button>
                </div>
            </div>
            <div id="smcp-lic-status" class="small text-muted"><?php echo Text::_('COM_SENSOMCP_LICENSE_LOADING'); ?></div>
            <div id="smcp-lic-actions" class="mt-3"></div>
        </div>
    </div>

    <?php
    $cards = [
        ['COM_SENSOMCP_STAT_TOTAL', $s['total'], 'primary'],
        ['COM_SENSOMCP_STAT_LAST7', $s['last7'], 'info'],
        ['COM_SENSOMCP_STAT_ERROR_RATE', $s['error_rate'] . ' %', $s['error_rate'] > 0 ? 'warning' : 'success'],
        ['COM_SENSOMCP_STAT_AUTH_FAILS', $s['auth_failures'], $s['auth_failures'] > 0 ? 'danger' : 'success'],
        ['COM_SENSOMCP_STAT_CLIENTS', $s['clients'], 'secondary'],
        ['COM_SENSOMCP_STAT_TOKENS', $s['active_tokens'], 'secondary'],
    ];
    ?>
    <div class="row">
        <?php foreach ($cards as $c) : ?>
            <div class="col-6 col-md-4 col-xl-2 mb-4">
                <div class="card text-center h-100">
                    <div class="card-body p-2">
                        <div class="fs-2 fw-bold text-<?php echo $c[2]; ?>"><?php echo htmlspecialchars((string) $c[1], ENT_QUOTES); ?></div>
                        <div class="small text-muted"><?php echo Text::_($c[0]); ?></div>
                    </div>
                </div>
            </div>
        <?php endforeach; ?>
    </div>

    <div class="card mb-4">
        <div class="card-header"><?php echo Text::_('COM_SENSOMCP_REQUESTS_PER_DAY'); ?></div>
        <div class="card-body">
            <div style="position:relative;height:280px;">
                <canvas id="senso-chart-perday"></canvas>
            </div>
        </div>
    </div>

    <div class="row">
        <div class="col-md-6 mb-4">
            <div class="card h-100">
                <div class="card-header"><?php echo Text::_('COM_SENSOMCP_TOP_TOOLS'); ?></div>
                <ul class="list-group list-group-flush">
                    <?php foreach ($this->topTools as $tool => $count) : ?>
                        <li class="list-group-item d-flex justify-content-between">
                            <code><?php echo htmlspecialchars((string) $tool, ENT_QUOTES); ?></code>
                            <span class="badge bg-primary rounded-pill"><?php echo (int) $count; ?></span>
                        </li>
                    <?php endforeach; ?>
                    <?php if (!$this->topTools) : ?><li class="list-group-item text-muted"><?php echo Text::_('COM_SENSOMCP_NO_DATA'); ?></li><?php endif; ?>
                </ul>
            </div>
        </div>
        <div class="col-md-6 mb-4">
            <div class="card h-100">
                <div class="card-header"><?php echo Text::_('COM_SENSOMCP_TOP_METHODS'); ?></div>
                <ul class="list-group list-group-flush">
                    <?php foreach ($this->topMethods as $method => $count) : ?>
                        <li class="list-group-item d-flex justify-content-between">
                            <code><?php echo htmlspecialchars((string) $method, ENT_QUOTES); ?></code>
                            <span class="badge bg-info rounded-pill"><?php echo (int) $count; ?></span>
                        </li>
                    <?php endforeach; ?>
                    <?php if (!$this->topMethods) : ?><li class="list-group-item text-muted"><?php echo Text::_('COM_SENSOMCP_NO_DATA'); ?></li><?php endif; ?>
                </ul>
            </div>
        </div>
    </div>

    <div class="card">
        <div class="card-header"><?php echo Text::_('COM_SENSOMCP_RECENT_REQUESTS'); ?></div>
        <div class="table-responsive mb-3">
            <table class="table table-sm table-striped mb-0">
                <thead>
                    <tr>
                        <th><?php echo Text::_('COM_SENSOMCP_COL_DATE'); ?></th>
                        <th><?php echo Text::_('COM_SENSOMCP_COL_USER'); ?></th>
                        <th><?php echo Text::_('COM_SENSOMCP_COL_METHOD'); ?></th>
                        <th><?php echo Text::_('COM_SENSOMCP_COL_TOOL'); ?></th>
                        <th><?php echo Text::_('COM_SENSOMCP_COL_STATUS'); ?></th>
                        <th>IP</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($this->recent as $r) : ?>
                        <tr>
                            <td class="small text-nowrap"><?php echo HTMLHelper::_('date', $r->created, 'Y-m-d H:i:s'); ?></td>
                            <td class="small"><?php echo htmlspecialchars((string) ($r->username ?? ('#' . $r->user_id)), ENT_QUOTES); ?></td>
                            <td class="small"><code><?php echo htmlspecialchars((string) $r->method, ENT_QUOTES); ?></code></td>
                            <td class="small"><?php echo $r->tool ? '<code>' . htmlspecialchars((string) $r->tool, ENT_QUOTES) . '</code>' : ''; ?></td>
                            <td>
                                <?php if ((int) $r->success === 1) : ?>
                                    <span class="badge bg-success">OK</span>
                                <?php else : ?>
                                    <span class="badge bg-danger" title="<?php echo htmlspecialchars((string) $r->error, ENT_QUOTES); ?>">ERR</span>
                                <?php endif; ?>
                            </td>
                            <td class="small text-muted"><?php echo htmlspecialchars((string) $r->ip, ENT_QUOTES); ?></td>
                        </tr>
                    <?php endforeach; ?>
                    <?php if (!$this->recent) : ?>
                        <tr><td colspan="6" class="text-muted text-center py-3"><?php echo Text::_('COM_SENSOMCP_NO_DATA'); ?></td></tr>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>

<script>
(function () {
    function initChart() {
        if (typeof Chart === 'undefined') {
            return window.requestAnimationFrame(initChart);
        }

        var el = document.getElementById('senso-chart-perday');
        if (!el) {
            return;
        }

        var styles = getComputedStyle(document.body);
        var primary = styles.getPropertyValue('--bs-primary').trim() || '#0d6efd';
        var grid = styles.getPropertyValue('--bs-border-color').trim() || 'rgba(128,128,128,0.2)';
        var text = styles.getPropertyValue('--bs-body-color').trim() || '#666';

        new Chart(el.getContext('2d'), {
            type: 'line',
            data: {
                labels: <?php echo json_encode($chartLabels, JSON_UNESCAPED_UNICODE); ?>,
                datasets: [{
                    label: <?php echo json_encode(Text::_('COM_SENSOMCP_REQUESTS_PER_DAY'), JSON_UNESCAPED_UNICODE); ?>,
                    data: <?php echo json_encode($chartData); ?>,
                    borderColor: primary,
                    backgroundColor: 'rgba(13,110,253,0.15)',
                    tension: 0.3,
                    fill: true,
                    pointRadius: 3,
                    pointHoverRadius: 5
                }]
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                interaction: {mode: 'index', intersect: false},
                plugins: {legend: {display: false}},
                scales: {
                    y: {beginAtZero: true, ticks: {precision: 0, color: text}, grid: {color: grid}},
                    x: {ticks: {color: text}, grid: {display: false}}
                }
            }
        });
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', initChart);
    } else {
        initChart();
    }
})();
</script>

<script>
(function () {
    var T = <?php echo json_encode(\Joomla\CMS\Session\Session::getFormToken()); ?>;
    var VERSION = <?php echo json_encode(Updates::componentVersion()); ?>;
    var L = {
        loading: <?php echo json_encode(Text::_('COM_SENSOMCP_LICENSE_LOADING')); ?>,
        free: <?php echo json_encode(Text::_('COM_SENSOMCP_LICENSE_FREE_MODE')); ?>,
        unreachable: <?php echo json_encode(Text::_('COM_SENSOMCP_LICENSE_STORE_UNREACHABLE')); ?>,
        invalid: <?php echo json_encode(Text::_('COM_SENSOMCP_LICENSE_INVALID')); ?>,
        covered: <?php echo json_encode(Text::_('COM_SENSOMCP_LICENSE_COVERED')); ?>,
        notCovered: <?php echo json_encode(Text::_('COM_SENSOMCP_LICENSE_NOT_COVERED')); ?>,
        expiredCover: <?php echo json_encode(Text::_('COM_SENSOMCP_LICENSE_EXPIRED_COVER')); ?>,
        noSeats: <?php echo json_encode(Text::_('COM_SENSOMCP_LICENSE_NO_SEATS')); ?>,
        activate: <?php echo json_encode(Text::_('COM_SENSOMCP_LICENSE_ACTIVATE')); ?>,
        choose: <?php echo json_encode(Text::_('COM_SENSOMCP_LICENSE_CHOOSE')); ?>,
        seatsLeft: <?php echo json_encode(Text::_('COM_SENSOMCP_LICENSE_SEATS_LEFT')); ?>,
        expiresOn: <?php echo json_encode(Text::_('COM_SENSOMCP_LICENSE_EXPIRES_ON')); ?>,
        perpetual: <?php echo json_encode(Text::_('COM_SENSOMCP_LICENSE_PERPETUAL')); ?>,
        keySaved: <?php echo json_encode(Text::_('COM_SENSOMCP_LICENSE_KEY_SAVED')); ?>
    };
    var tierEl = document.getElementById('smcp-lic-tier');
    var statusEl = document.getElementById('smcp-lic-status');
    var actionsEl = document.getElementById('smcp-lic-actions');
    var bannerEl = document.getElementById('smcp-lic-banner');
    var keyInput = document.getElementById('smcp-lic-key');
    var saveBtn = document.getElementById('smcp-lic-save');
    if (!tierEl) { return; }

    function post(task, data) {
        data = data || {}; data.task = task; data[T] = '1';
        return fetch('index.php?option=com_sensomcp', {
            method: 'POST',
            headers: {'X-Requested-With': 'XMLHttpRequest'},
            body: new URLSearchParams(data)
        }).then(function (r) { return r.json(); });
    }

    function badge(tier) {
        var map = {premium: 'warning text-dark', pro: 'secondary', free: 'light text-dark', unknown: 'dark'};
        var t = String(tier || 'free');
        tierEl.className = 'badge bg-' + (map[tier] || 'secondary');
        // Palier en capitale initiale + version installée du composant (ex. « Premium 1.0.5 »).
        tierEl.textContent = t.charAt(0).toUpperCase() + t.slice(1) + (VERSION ? ' ' + VERSION : '');
    }

    function licLabel(c) {
        var exp = c.expires ? L.expiresOn.replace('%s', String(c.expires).substring(0, 10)) : L.perpetual;
        return c.tier.charAt(0).toUpperCase() + c.tier.slice(1) + ' — ' + exp + ' — ' + L.seatsLeft.replace('%d', c.seats_left);
    }

    function toast(text, ok) {
        if (!text || !window.Joomla) { return; }
        var m = {}; m[ok ? 'message' : 'error'] = [text]; Joomla.renderMessages(m);
    }

    function render(st) {
        actionsEl.innerHTML = ''; bannerEl.innerHTML = '';
        if (!st) { statusEl.textContent = L.unreachable; return; }
        badge(st.effective_tier || 'free');

        // Pré-remplit le champ avec la clé semi-masquée (4 derniers caractères en clair).
        keyInput.value = st.key_set ? (st.key_masked || '') : '';
        keyInput.dataset.original = keyInput.value;

        if (!st.key_set) { statusEl.textContent = L.free; return; }
        if (st.unreachable) { statusEl.textContent = L.unreachable; return; }
        if (st.invalid) { statusEl.innerHTML = '<span class="text-danger">' + L.invalid + '</span>'; return; }

        var host = st.host || '';
        if (st.action === 'none' && st.effective_tier !== 'free') {
            statusEl.innerHTML = '<span class="text-success">✓ ' + host + ' — ' + L.covered + ' (' + st.effective_tier + ')</span>';
            return;
        }

        var msg = st.action === 'rebind' ? L.expiredCover : L.notCovered;
        bannerEl.innerHTML = '<div class="alert alert-warning py-2 mb-2">' + msg.replace('%s', host) + '</div>';

        var cands = st.candidates || [];
        if (st.action === 'no_seats' || cands.length === 0) {
            statusEl.innerHTML = '<span class="text-muted">' + L.noSeats + '</span>';
            return;
        }
        statusEl.textContent = '';

        var mode = st.action === 'rebind' ? 'rebind' : 'declare';
        var wrap = document.createElement('div');

        if (cands.length === 1) {
            var b = document.createElement('button');
            b.className = 'btn btn-success';
            b.textContent = L.activate + ' — ' + licLabel(cands[0]);
            b.onclick = function () { activate(cands[0].license_id, mode, b); };
            wrap.appendChild(b);
        } else {
            var p = document.createElement('div'); p.className = 'mb-2 fw-bold'; p.textContent = L.choose;
            wrap.appendChild(p);
            cands.forEach(function (c, i) {
                var id = 'smcp-cand-' + c.license_id;
                var div = document.createElement('div'); div.className = 'form-check';
                var inp = document.createElement('input');
                inp.className = 'form-check-input'; inp.type = 'radio'; inp.name = 'smcp-cand'; inp.id = id; inp.value = c.license_id;
                if (i === 0) { inp.checked = true; }
                var lab = document.createElement('label'); lab.className = 'form-check-label'; lab.setAttribute('for', id); lab.textContent = licLabel(c);
                div.appendChild(inp); div.appendChild(lab); wrap.appendChild(div);
            });
            var b2 = document.createElement('button'); b2.className = 'btn btn-success mt-2'; b2.textContent = L.activate;
            b2.onclick = function () {
                var sel = wrap.querySelector('input[name="smcp-cand"]:checked');
                if (sel) { activate(parseInt(sel.value, 10), mode, b2); }
            };
            wrap.appendChild(b2);
        }
        actionsEl.appendChild(wrap);
    }

    function activate(licenseId, mode, btn) {
        if (btn) { btn.disabled = true; }
        post('license.activate', {license_id: licenseId, mode: mode}).then(function (st) {
            render(st); toast(st && st.message, !!(st && st.ok));
        }).catch(function () { if (btn) { btn.disabled = false; } });
    }

    function refresh() {
        statusEl.textContent = L.loading;
        post('license.status').then(render).catch(function () { statusEl.textContent = L.unreachable; });
    }

    saveBtn.addEventListener('click', function () {
        var val = keyInput.value.trim();
        // Champ inchangé (masque encore affiché) → simple re-vérification, jamais d'écrasement par le masque.
        if (val === (keyInput.dataset.original || '')) { refresh(); return; }
        saveBtn.disabled = true;
        post('license.saveKey', {key: val}).then(function (st) {
            render(st); toast(L.keySaved, true);
        }).finally(function () { saveBtn.disabled = false; });
    });

    refresh();
})();
</script>
