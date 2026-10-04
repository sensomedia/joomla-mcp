<?php
/**
 * @copyright (C) 2026 Sensomedia. All rights reserved.
 * @license   GNU General Public License version 2 or later
 */

namespace Sensomedia\Component\Sensomcp\Administrator\OAuth;

\defined('_JEXEC') or die;

/**
 * Erreur OAuth 2.1 portant un code d'erreur normalisé (RFC 6749 §5.2)
 * et un statut HTTP.
 */
class OAuthException extends \RuntimeException
{
    public function __construct(
        private string $errorCode,
        string $description = '',
        private int $httpStatus = 400
    ) {
        parent::__construct($description !== '' ? $description : $errorCode);
    }

    public function getErrorCode(): string
    {
        return $this->errorCode;
    }

    public function getHttpStatus(): int
    {
        return $this->httpStatus;
    }
}
