<?php
/**
 * @copyright (C) 2026 Sensomedia. All rights reserved.
 * @license   GNU General Public License version 2 or later
 */

namespace Sensomedia\Component\Sensomcp\Administrator\Helper;

use Joomla\CMS\Language\Text;

\defined('_JEXEC') or die;

/**
 * Rendu HTML d'UNE ligne de plugin de l'écran « Plugins & outils ».
 *
 * Source UNIQUE de balisage, utilisée à la fois par le template (boucle initiale)
 * et par le contrôleur (`plugins.ajaxRow`) pour remplacer une ligne après une
 * action AJAX (install / réinstall / mise à jour / désinstallation) — SANS
 * rechargement de page.
 */
final class PluginRow
{
    /** palier => [fond, texte, clé libellé] */
    private const TIER = [
        'free'    => ['#198754', '#ffffff', 'COM_SENSOMCP_TIER_FREE'],
        'pro'     => ['#8a7f72', '#ffffff', 'COM_SENSOMCP_TIER_PRO'],
        'premium' => ['#ffc107', '#212529', 'COM_SENSOMCP_TIER_PREMIUM'],
        'addon'   => ['#0d6efd', '#ffffff', 'COM_SENSOMCP_TIER_ADDON'],
    ];

    /** risque => [classe badge, clé libellé] */
    private const RISK = [
        'safe'   => ['success', 'COM_SENSOMCP_SECURITY_RISK_SAFE'],
        'write'  => ['primary', 'COM_SENSOMCP_SECURITY_RISK_WRITE'],
        'danger' => ['danger',  'COM_SENSOMCP_SECURITY_RISK_DANGER'],
    ];

    public static function render(array $p): string
    {
        $esc = static fn ($v): string => htmlspecialchars((string) $v, ENT_QUOTES, 'UTF-8');

        [$bg, $fg, $tkey] = self::TIER[$p['tier']] ?? self::TIER['addon'];

        $el     = (string) ($p['element'] ?? '');
        // Le cœur gratuit (content, site) fait partie du pack free : pas de désinstallation.
        $core   = \in_array($el, ['content', 'site'], true);
        $tid    = 'tools_' . $el;
        $tools  = $p['tools'] ?? [];
        $name   = (string) ($p['name'] ?? '') ?: $el;
        $instV  = (string) ($p['installed_version'] ?? '');
        $latest = (string) ($p['latest_version'] ?? '');
        $update = !empty($p['update_available']);

        ob_start();
        ?>
        <div class="smcp-row card mb-2" data-element="<?php echo $esc($el); ?>"
             data-name="<?php echo $esc(strtolower($name . ' ' . $el)); ?>">
            <div class="card-body smcp-cardbody d-flex align-items-center gap-3 py-2 flex-wrap">
                <div class="smcp-col-toggle d-flex align-items-center gap-2 flex-shrink-0 text-nowrap">
                    <div class="form-check form-switch mb-0" title="<?php echo Text::_('COM_SENSOMCP_CATALOG_ENABLE_PLUGIN'); ?>">
                        <input class="form-check-input smcp-plugin" type="checkbox" role="switch"
                               <?php if (!empty($p['installed'])) : ?>data-id="<?php echo (int) ($p['id'] ?? 0); ?>" <?php echo !empty($p['enabled']) ? 'checked' : ''; ?><?php else : ?>disabled<?php endif; ?>>
                    </div>
                    <span class="fw-semibold"><?php echo $esc($name); ?></span>
                </div>

                <div class="smcp-col-badges flex-grow-1">
                    <?php if ($instV !== '') : ?>
                        <span class="badge bg-secondary smcp-ver">v<?php echo $esc($instV); ?></span>
                    <?php endif; ?>
                    <span class="badge" style="background:<?php echo $bg; ?>;color:<?php echo $fg; ?>"><?php echo Text::_($tkey); ?></span>
                </div>

                <div class="smcp-actions d-flex gap-1 flex-shrink-0 text-nowrap ms-auto">
                    <?php if (!empty($p['installed'])) : ?>
                        <?php if ($update) : ?>
                            <button type="button" class="btn btn-sm btn-warning smcp-install" data-element="<?php echo $esc($el); ?>">
                                <span class="icon-download me-1" aria-hidden="true"></span><?php echo Text::sprintf('COM_SENSOMCP_CATALOG_UPDATE_TO', $esc($latest)); ?>
                            </button>
                        <?php elseif (!empty($p['stream_id'])) : ?>
                            <button type="button" class="btn btn-sm btn-outline-secondary smcp-install" data-element="<?php echo $esc($el); ?>">
                                <?php echo Text::_('COM_SENSOMCP_CATALOG_REINSTALL'); ?>
                            </button>
                        <?php endif; ?>
                        <button type="button" class="btn btn-sm btn-outline-primary smcp-configure" data-bs-toggle="collapse" data-bs-target="#<?php echo $tid; ?>" aria-expanded="false">
                            <?php echo Text::_('COM_SENSOMCP_CATALOG_CONFIGURE'); ?>
                            <span class="badge bg-secondary rounded-pill ms-1"><?php echo \count($tools); ?></span>
                        </button>
                        <?php if (!$core) : ?>
                            <button type="button" class="btn btn-sm btn-outline-danger smcp-uninstall" data-element="<?php echo $esc($el); ?>" data-id="<?php echo (int) ($p['id'] ?? 0); ?>" title="<?php echo Text::_('COM_SENSOMCP_CATALOG_UNINSTALL'); ?>" aria-label="<?php echo Text::_('COM_SENSOMCP_CATALOG_UNINSTALL'); ?>">
                                <span class="icon-trash" aria-hidden="true"></span>
                            </button>
                        <?php endif; ?>
                    <?php elseif (!empty($p['installable'])) : ?>
                        <button type="button" class="btn btn-sm btn-primary smcp-install" data-element="<?php echo $esc($el); ?>">
                            <span class="icon-download me-1" aria-hidden="true"></span><?php echo Text::_('COM_SENSOMCP_CATALOG_INSTALL'); ?>
                        </button>
                    <?php else : ?>
                        <a class="btn btn-sm btn-outline-primary" href="<?php echo $esc($p['buy_url'] ?? ''); ?>" target="_blank" rel="noopener noreferrer">
                            <span class="icon-cart me-1" aria-hidden="true"></span><?php echo Text::sprintf('COM_SENSOMCP_CATALOG_BUY', Text::_($tkey)); ?>
                        </a>
                    <?php endif; ?>
                </div>
            </div>

            <?php if (!empty($p['installed'])) : ?>
                <div id="<?php echo $tid; ?>" class="collapse border-top">
                    <div class="p-2">
                        <?php if (!$tools) : ?>
                            <p class="text-muted small mb-0"><?php echo Text::_('COM_SENSOMCP_SECURITY_NO_TOOLS'); ?></p>
                        <?php else : ?>
                            <table class="table table-sm align-middle mb-0"><tbody>
                                <?php foreach ($tools as $t) :
                                    [$cls, $key] = self::RISK[$t['risk']] ?? self::RISK['write']; ?>
                                    <tr>
                                        <td style="width:3rem">
                                            <div class="form-check form-switch mb-0">
                                                <input class="form-check-input smcp-tool" type="checkbox" role="switch"
                                                       data-tool="<?php echo $esc($t['name']); ?>" data-risk="<?php echo $esc($t['risk']); ?>" <?php echo !empty($t['enabled']) ? 'checked' : ''; ?>>
                                            </div>
                                        </td>
                                        <td style="width:11rem"><code><?php echo $esc($t['name']); ?></code></td>
                                        <td style="width:7rem"><span class="badge bg-<?php echo $cls; ?>"><?php echo Text::_($key); ?></span></td>
                                        <td class="small text-muted smcp-tool-desc"><?php echo $esc($t['description']); ?></td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody></table>
                        <?php endif; ?>
                    </div>
                </div>
            <?php endif; ?>
        </div>
        <?php
        return (string) ob_get_clean();
    }
}
