<?php
/**
 * @copyright (C) 2026 Sensomedia. All rights reserved.
 * @license   GNU General Public License version 2 or later
 */

use Joomla\CMS\HTML\HTMLHelper;
use Joomla\CMS\Language\Text;
use Joomla\CMS\Router\Route;

\defined('_JEXEC') or die;

$o = $this->oauth;
?>
<div class="com-sensomcp-authorize" style="max-width:560px;margin:3rem auto;font-family:system-ui,sans-serif;">
    <h1 style="font-size:1.4rem;"><?php echo Text::_('COM_SENSOMCP_OAUTH_CONSENT_TITLE'); ?></h1>

    <p><?php echo Text::sprintf(
        'COM_SENSOMCP_OAUTH_CONSENT_INTRO',
        '<strong>' . htmlspecialchars($this->clientName, ENT_QUOTES) . '</strong>',
        '<strong>' . htmlspecialchars($this->username, ENT_QUOTES) . '</strong>'
    ); ?></p>

    <p style="padding:.6rem .8rem;border:1px solid #d9d9d9;border-radius:6px;background:#fafafa;"><?php echo Text::sprintf(
        'COM_SENSOMCP_OAUTH_CONSENT_REDIRECT',
        '<code style="font-size:1.05em;">' . htmlspecialchars((string) ($this->redirectHost ?? ''), ENT_QUOTES) . '</code>'
    ); ?></p>

    <form action="<?php echo Route::_('index.php?option=com_sensomcp&task=oauth.authorize'); ?>" method="post">
        <?php foreach (['response_type','client_id','redirect_uri','state','code_challenge','code_challenge_method','resource'] as $k) : ?>
            <input type="hidden" name="<?php echo $k; ?>" value="<?php echo htmlspecialchars((string) ($o[$k] ?? ''), ENT_QUOTES); ?>">
        <?php endforeach; ?>

        <p style="margin-bottom:.5rem;"><?php echo Text::_('COM_SENSOMCP_OAUTH_CONSENT_SCOPES_INTRO'); ?></p>

        <?php if ($this->availableScopes === []) : ?>
            <p style="color:#a00;"><?php echo Text::_('COM_SENSOMCP_OAUTH_CONSENT_NO_SCOPES'); ?></p>
        <?php else : ?>
            <ul style="list-style:none;padding:0;border:1px solid #ddd;border-radius:8px;">
                <?php foreach ($this->availableScopes as $id => $label) : ?>
                    <li style="padding:.6rem .9rem;border-bottom:1px solid #eee;">
                        <label style="display:flex;gap:.6rem;align-items:center;cursor:pointer;">
                            <input type="checkbox" name="scopes[]"
                                   value="<?php echo htmlspecialchars($id, ENT_QUOTES); ?>"
                                   <?php echo \in_array($id, $this->requestedScopes, true) ? 'checked' : ''; ?>>
                            <span>
                                <strong><?php echo htmlspecialchars(Text::_($label), ENT_QUOTES); ?></strong>
                                <br><code style="color:#888;font-size:.8rem;"><?php echo htmlspecialchars($id, ENT_QUOTES); ?></code>
                            </span>
                        </label>
                    </li>
                <?php endforeach; ?>
            </ul>
        <?php endif; ?>

        <div style="display:flex;gap:1rem;margin-top:1.5rem;">
            <button type="submit" name="authorize_decision" value="allow"
                    class="btn btn-primary" style="padding:.6rem 1.2rem;">
                <?php echo Text::_('COM_SENSOMCP_OAUTH_CONSENT_ALLOW'); ?>
            </button>
            <button type="submit" name="authorize_decision" value="deny"
                    class="btn btn-secondary" style="padding:.6rem 1.2rem;">
                <?php echo Text::_('COM_SENSOMCP_OAUTH_CONSENT_DENY'); ?>
            </button>
        </div>

        <?php echo HTMLHelper::_('form.token'); ?>
    </form>
</div>
