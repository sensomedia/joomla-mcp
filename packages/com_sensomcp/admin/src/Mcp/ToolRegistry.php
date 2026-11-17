<?php
/**
 * @copyright (C) 2026 Sensomedia. All rights reserved.
 * @license   GNU General Public License version 2 or later
 */

namespace Sensomedia\Component\Sensomcp\Administrator\Mcp;

use Joomla\CMS\User\User;

\defined('_JEXEC') or die;

/**
 * Registre des outils MCP — CONTRAT PUBLIC.
 *
 * API que les plugins du groupe « sensomcp » utilisent pour ajouter leurs
 * outils (et déclarer leurs scopes) lors de l'événement onSensomcpCollectTools.
 * Signature à garder stable entre versions mineures.
 *
 * Chaque outil porte un **scope OAuth requis** (ex. `content.write`). Le
 * filtrage par scope se fait via setGrantedScopes() : `list()` ne renvoie que
 * les outils autorisés, `call()` refuse les autres. Un scope vide = aucun
 * prérequis. La valeur `*` dans les scopes accordés = accès total (jeton
 * statique de confiance).
 */
class ToolRegistry
{
    /**
     * Composants « d'administration » : leur gestionnaire Joomla exige `core.manage`
     * sur le composant (dispatcher admin), alors que core.create/edit/edit.state/delete
     * sont hérités de la racine par Author/Editor/Publisher/Manager. Pour une ACL en
     * forme tableau `[action, composant]` visant l'un d'eux, `core.manage` est exigé en
     * plus (audit 2026-10-12). Un plugin peut refuser la règle avec `acl_manage => false`
     * (ex. yootheme : parité avec le Customizer, qui ne demande que core.edit).
     */
    public const ADMIN_COMPONENTS = [
        'com_menus', 'com_templates', 'com_languages', 'com_plugins', 'com_cache', 'com_installer',
        'com_users', 'com_modules', 'com_fields', 'com_redirect', 'com_checkin', 'com_scheduler',
        'com_config', 'com_admin', 'com_finder', 'com_banners', 'com_newsfeeds', 'com_privacy',
        'com_actionlogs', 'com_mails', 'com_messages', 'com_postinstall', 'com_joomlaupdate', 'com_sensomcp',
    ];

    /** @var array<string, array{description:string, inputSchema:array, handler:callable, scope:string}> */
    private array $tools = [];

    /** @var array<string, string> catalogue scopeId => libellé lisible */
    private array $scopeCatalog = [];

    /** @var string[]|null null = aucun filtrage (build de catalogue) ; sinon liste de scopes accordés */
    private ?array $grantedScopes = null;

    /** @var array<string,bool> overrides explicites tool => enabled (table #__sensomcp_tool_state) */
    private array $toolPolicy = [];

    /** Mode lecture seule global : seuls les outils « safe » restent actifs. */
    private bool $readOnly = false;

    /** Utilisateur authentifié du jeton MCP (pour le gate ACL). null = contexte sans ACL (catalogue/admin). */
    private ?User $user = null;

    /**
     * Fixe l'utilisateur authentifié pour l'application des ACL Joomla dans
     * list()/call(). Sans utilisateur, aucun filtrage ACL (build de catalogue,
     * écran admin).
     */
    public function setUser(?User $user): void
    {
        $this->user = $user;
    }

    /**
     * @param array{risk?:string, origin?:string, default_enabled?:bool, acl?:mixed} $meta
     *        risk : safe|write|danger (déduit du scope si absent) ;
     *        origin : plugin d'origine (déduit du scope si absent) ;
     *        default_enabled : état par défaut si aucune policy (défaut true) ;
     *        acl : exigence ACL Joomla — soit `[action, assetName]`
     *              (ex. `['core.create', 'com_content']`, assetName null = global),
     *              soit un callable `fn(User $user, array $args): bool` pour un
     *              contrôle par élément (asset de l'item, core.edit.own…). Le callable
     *              peut lever une McpToolException pour motiver le refus (message
     *              remonté au client lors de call() ; ignoré dans list()). Absent =
     *              aucune exigence (déconseillé pour les outils d'écriture).
     */
    public function register(string $name, string $description, array $inputSchema, callable $handler, string $scope = '', array $meta = []): void
    {
        $this->tools[$name] = [
            'description'     => $description,
            'inputSchema'     => $inputSchema,
            'handler'         => $handler,
            'scope'           => $scope,
            'risk'            => $meta['risk'] ?? $this->riskFromScope($scope),
            'origin'          => $meta['origin'] ?? $this->originFromScope($scope),
            'default_enabled' => \array_key_exists('default_enabled', $meta) ? (bool) $meta['default_enabled'] : true,
            'acl'             => $meta['acl'] ?? null,
            'acl_manage'      => !\array_key_exists('acl_manage', $meta) || (bool) $meta['acl_manage'],
            // Outil « à confirmer » : callable fn(array $args): array|string qui décrit ce qui
            // va changer (aperçu), SANS rien modifier. Voir call() et Confirmation.
            'confirm'         => isset($meta['confirm']) && \is_callable($meta['confirm']) ? $meta['confirm'] : null,
        ];
    }

    /** @var array<string, array> uri => ressource déclarée par un plugin */
    private array $resources = [];

    /** @var array<string, array> nom => prompt déclaré par un plugin */
    private array $prompts = [];

    /**
     * Déclare une ressource MCP (contenu en lecture seule joint au contexte par
     * l'utilisateur). $reader : fn(): string|array (un tableau est rendu en JSON).
     * Mêmes contrôles que les outils : scope accordé + meta['acl'].
     */
    public function registerResource(string $uri, string $name, string $description, callable $reader, string $scope = '', array $meta = []): void
    {
        $this->resources[$uri] = [
            'name'        => $name,
            'description' => $description,
            'reader'      => $reader,
            'scope'       => $scope,
            'mimeType'    => (string) ($meta['mimeType'] ?? 'application/json'),
            'acl'         => $meta['acl'] ?? null,
        ];
    }

    /**
     * Déclare un prompt MCP (scénario guidé lancé par l'utilisateur).
     * $arguments : liste de {name, description, required}. $builder : fn(array $args): string.
     * Le prompt n'est proposé que si TOUS les scopes de $scopes sont accordés et l'ACL passe.
     *
     * @param string[] $scopes
     */
    public function registerPrompt(string $name, string $description, array $arguments, callable $builder, array $scopes = [], array $meta = []): void
    {
        $this->prompts[$name] = [
            'description' => $description,
            'arguments'   => $arguments,
            'builder'     => $builder,
            'scopes'      => $scopes,
            'acl'         => $meta['acl'] ?? null,
        ];
    }

    /** @return list<array{uri:string,name:string,description:string,mimeType:string}> */
    public function resources(): array
    {
        $out = [];

        foreach ($this->resources as $uri => $r) {
            if ($this->isGranted($r['scope']) && $this->passesAcl($r['acl'])) {
                $out[] = ['uri' => $uri, 'name' => $r['name'], 'description' => $r['description'], 'mimeType' => $r['mimeType']];
            }
        }

        return $out;
    }

    public function hasResource(string $uri): bool
    {
        return isset($this->resources[$uri]);
    }

    /** @return array{contents: list<array{uri:string,mimeType:string,text:string}>} */
    public function readResource(string $uri): array
    {
        $r = $this->resources[$uri] ?? null;

        if ($r === null) {
            throw new McpToolException('Unknown resource URI: ' . $uri);
        }

        if (!$this->isGranted($r['scope']) || !$this->passesAcl($r['acl'])) {
            throw new McpToolException('Insufficient permission for resource ' . $uri . '.');
        }

        $data = ($r['reader'])();
        $text = \is_string($data) ? $data : (string) json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

        return ['contents' => [['uri' => $uri, 'mimeType' => $r['mimeType'], 'text' => $text]]];
    }

    /** @return list<array{name:string,description:string,arguments:array}> */
    public function prompts(): array
    {
        $out = [];

        foreach ($this->prompts as $name => $p) {
            if ($this->promptAvailable($p)) {
                $out[] = ['name' => $name, 'description' => $p['description'], 'arguments' => $p['arguments']];
            }
        }

        return $out;
    }

    public function hasPrompt(string $name): bool
    {
        return isset($this->prompts[$name]);
    }

    /** @return array{description:string, messages:list<array>} */
    public function getPrompt(string $name, array $args): array
    {
        $p = $this->prompts[$name] ?? null;

        if ($p === null) {
            throw new McpToolException('Unknown prompt: ' . $name);
        }

        if (!$this->promptAvailable($p)) {
            throw new McpToolException('Insufficient permission for prompt ' . $name . '.');
        }

        foreach ($p['arguments'] as $arg) {
            if (!empty($arg['required']) && trim((string) ($args[$arg['name']] ?? '')) === '') {
                throw new McpToolException('Missing required argument "' . $arg['name'] . '" for prompt ' . $name . '.');
            }
        }

        return [
            'description' => $p['description'],
            'messages'    => [[
                'role'    => 'user',
                'content' => ['type' => 'text', 'text' => (string) ($p['builder'])($args)],
            ]],
        ];
    }

    private function promptAvailable(array $p): bool
    {
        foreach ($p['scopes'] as $scope) {
            if (!$this->isGranted((string) $scope)) {
                return false;
            }
        }

        return $this->passesAcl($p['acl']);
    }

    /** ACL d'une ressource / d'un prompt : null = libre ; callable fn(User): bool ; [action, asset]. */
    private function passesAcl(mixed $acl): bool
    {
        if ($acl === null || $this->user === null) {
            return true;
        }

        try {
            if (\is_callable($acl)) {
                return (bool) $acl($this->user, []);
            }

            return (bool) $this->user->authorise((string) ($acl[0] ?? ''), $acl[1] ?? null);
        } catch (\Throwable) {
            return false;
        }
    }

    /** Risque déduit du suffixe de scope (surchargeable via meta['risk']). */
    private function riskFromScope(string $scope): string
    {
        if (str_ends_with($scope, '.read')) {
            return 'safe';
        }

        if (str_ends_with($scope, '.install')) {
            return 'danger';
        }

        return 'write';
    }

    /** Plugin d'origine déduit du préfixe de scope (associations → multilingual). */
    private function originFromScope(string $scope): string
    {
        $prefix = $scope !== '' ? explode('.', $scope)[0] : 'core';

        return ['associations' => 'multilingual'][$prefix] ?? $prefix;
    }

    /** Déclare un scope et son libellé lisible (pour métadonnées + consentement). */
    public function declareScope(string $scope, string $label): void
    {
        $this->scopeCatalog[$scope] = $label;
    }

    /** @return array<string,string> scopeId => libellé */
    public function scopes(): array
    {
        return $this->scopeCatalog;
    }

    /**
     * Scopes dont l'utilisateur courant peut réellement utiliser AU MOINS un outil
     * (ACL Joomla). Pour l'écran de consentement OAuth : ne proposer que ce que
     * l'utilisateur a le droit de faire. Le gate borne déjà l'exécution — ceci
     * nettoie l'UX et évite d'accorder un scope inutilisable. Sans utilisateur
     * fixé, aucun filtrage (retourne tout le catalogue).
     *
     * @return array<string,string> scopeId => libellé (sous-ensemble de scopes())
     */
    public function authorisedScopes(): array
    {
        if ($this->user === null) {
            return $this->scopeCatalog;
        }

        $allowed = [];

        foreach ($this->tools as $name => $tool) {
            $scope = (string) $tool['scope'];

            if ($scope === '' || isset($allowed[$scope])) {
                continue;
            }

            if ($this->isAllowed($name)) {
                $allowed[$scope] = true;
            }
        }

        return array_intersect_key($this->scopeCatalog, $allowed);
    }

    /**
     * Scopes effectivement accordés au porteur du jeton, résolus pour exposition
     * (ex. get_site_info) : `*` (jeton statique) ou null (build de catalogue)
     * deviennent la liste concrète de tous les scopes déclarés ; sinon on
     * n'expose que les scopes accordés réellement déclarés (filtre le bruit).
     *
     * @return string[]
     */
    public function grantedScopes(): array
    {
        if ($this->grantedScopes === null || \in_array('*', $this->grantedScopes, true)) {
            return array_keys($this->scopeCatalog);
        }

        return array_values(array_intersect($this->grantedScopes, array_keys($this->scopeCatalog)));
    }

    /**
     * Fixe les scopes accordés au porteur du jeton. null = pas de filtrage.
     *
     * @param string[]|null $scopes
     */
    public function setGrantedScopes(?array $scopes): void
    {
        $this->grantedScopes = $scopes;
    }

    /**
     * Overrides explicites d'état par outil (depuis #__sensomcp_tool_state).
     *
     * @param array<string,mixed> $policy tool => enabled
     */
    public function setToolPolicy(array $policy): void
    {
        $this->toolPolicy = array_map(static fn ($v) => (bool) $v, $policy);
    }

    /** Active le mode lecture seule global (désactive tout outil non « safe »). */
    public function setReadOnly(bool $readOnly): void
    {
        $this->readOnly = $readOnly;
    }

    /**
     * Un outil est-il actif ? Priorité : mode read-only (coupe non-safe) →
     * override explicite (policy) → état par défaut de l'outil.
     */
    private function isEnabled(string $name): bool
    {
        $tool = $this->tools[$name] ?? null;

        if ($tool === null) {
            return false;
        }

        if ($this->readOnly && $tool['risk'] !== 'safe') {
            return false;
        }

        if (\array_key_exists($name, $this->toolPolicy)) {
            return $this->toolPolicy[$name];
        }

        return (bool) $tool['default_enabled'];
    }

    /**
     * L'utilisateur du jeton a-t-il l'ACL Joomla requise pour l'outil ?
     * Sans exigence `acl` ou sans utilisateur (catalogue/admin) → autorisé.
     * `list()` appelle sans args (contrôle grossier au niveau composant) ;
     * `call()` passe les args (contrôle par élément via callable).
     *
     * @param array<string,mixed> $args
     */
    private function isAllowed(string $name, array $args = []): bool
    {
        $tool = $this->tools[$name] ?? null;

        if ($tool === null) {
            return false;
        }

        $acl = $tool['acl'];

        if ($acl === null || $this->user === null) {
            return true;
        }

        if (\is_callable($acl)) {
            try {
                return (bool) $acl($this->user, $args);
            } catch (McpToolException $e) {
                // Refus motivé par le plugin (ex. règle Super User) : remonté tel quel
                // dans call() ; en contexte list()/scopes (args vides) = simple refus.
                if ($args === []) {
                    return false;
                }

                throw $e;
            } catch (\Throwable) {
                return false;
            }
        }

        // Forme [action, assetName] : assetName null/absent = contrôle global.
        $action = (string) ($acl[0] ?? '');
        $asset  = $acl[1] ?? null;

        if ($action === '') {
            return true;
        }

        // Composant d'administration : l'accès au gestionnaire (core.manage) est requis
        // en plus de l'action, comme dans le back-office.
        if (\is_string($asset) && ($tool['acl_manage'] ?? true)
            && \in_array(strtolower($asset), self::ADMIN_COMPONENTS, true)
            && !$this->user->authorise('core.manage', $asset)) {
            return false;
        }

        return (bool) $this->user->authorise($action, $asset);
    }

    private function isGranted(string $scope): bool
    {
        if ($scope === '') {
            return true;
        }

        if ($this->grantedScopes === null) {
            return true;
        }

        if (\in_array('*', $this->grantedScopes, true)) {
            return true;
        }

        return \in_array($scope, $this->grantedScopes, true);
    }

    /**
     * Descripteurs pour `tools/list` — filtrés par scope accordé.
     *
     * @return list<array{name:string, description:string, inputSchema:object}>
     */
    public function list(): array
    {
        $out = [];

        foreach ($this->tools as $name => $tool) {
            if (!$this->isGranted($tool['scope']) || !$this->isEnabled($name) || !$this->isAllowed($name)) {
                continue;
            }

            $schema = $tool['inputSchema'];

            // Outil à confirmer : argument du second appel ajouté au schéma + mode d'emploi.
            if ($tool['confirm'] !== null) {
                $props = $schema['properties'] ?? [];
                $props = $props instanceof \stdClass ? (array) $props : (array) $props;
                $props[Confirmation::ARG] = [
                    'type'        => 'string',
                    'description' => 'Leave empty on the first call: you get a preview of the changes and a token. '
                        . 'Show the preview to the user; once they agree, call again with the SAME arguments and this token '
                        . '(single use, valid ' . (Confirmation::TTL / 60) . ' minutes).',
                ];
                $schema['properties'] = $props;
            }

            $out[] = [
                'name'        => $name,
                'description' => $tool['description'],
                'inputSchema' => (object) $schema,
                // Indications MCP pour le client (2025-06-18) : lecture seule / destructif.
                'annotations' => (object) ($tool['risk'] === 'safe'
                    ? ['readOnlyHint' => true]
                    : ['readOnlyHint' => false, 'destructiveHint' => $tool['confirm'] !== null]),
            ];
        }

        return $out;
    }

    public function has(string $name): bool
    {
        return isset($this->tools[$name]);
    }

    /**
     * Descriptif complet des outils (nom, description, scope), sans filtrage.
     * Pour l'affichage admin.
     *
     * @return list<array{name:string, description:string, scope:string}>
     */
    public function describe(): array
    {
        $out = [];

        foreach ($this->tools as $name => $tool) {
            $out[] = [
                'name'            => $name,
                'description'     => $tool['description'],
                'scope'           => $tool['scope'],
                'risk'            => $tool['risk'],
                'origin'          => $tool['origin'],
                'default_enabled' => (bool) $tool['default_enabled'],
                'enabled'         => $this->isEnabled($name),
            ];
        }

        return $out;
    }

    /**
     * Exécute un outil et renvoie un résultat `tools/call` MCP.
     *
     * @return array{content: list<array{type:string, text:string}>, isError?: bool}
     */
    public function call(string $name, array $arguments): array
    {
        if (!isset($this->tools[$name])) {
            throw new McpToolException('Unknown tool: ' . $name);
        }

        $tool = $this->tools[$name];

        if (!$this->isGranted($tool['scope'])) {
            throw new McpToolException("Insufficient scope for tool '$name' (requires: {$tool['scope']}).");
        }

        if (!$this->isEnabled($name)) {
            throw new McpToolException(
                $this->readOnly && $tool['risk'] !== 'safe'
                    ? "Tool '$name' is unavailable: the server is in read-only mode."
                    : "Tool '$name' has been disabled by the site administrator."
            );
        }

        // Gate ACL Joomla : refuse si l'utilisateur du jeton n'a pas les droits.
        if (!$this->isAllowed($name, $arguments)) {
            throw new McpToolException("Insufficient permission for tool '$name'.");
        }

        // Journal de débogage optionnel (param `debug`, off par défaut) : trace
        // l'appel AVANT exécution (paramètres rédigés + tronqués), utile même si
        // le handler échoue ensuite.
        DebugLog::toolCall($name, $arguments);

        // Confirmation en deux temps : 1er appel = aperçu + jeton ; 2e appel (mêmes
        // arguments + jeton valide, à usage unique) = exécution.
        if ($tool['confirm'] !== null) {
            $userId = (int) ($this->user->id ?? 0);
            $token  = trim((string) ($arguments[Confirmation::ARG] ?? ''));
            unset($arguments[Confirmation::ARG]);

            if ($token === '') {
                $preview = ($tool['confirm'])($arguments);

                return $this->textResult([
                    'confirmation_required' => true,
                    'preview'               => $preview,
                    Confirmation::ARG       => Confirmation::issue($userId, $name, $arguments),
                    'expires_in_seconds'    => Confirmation::TTL,
                    'next_step'             => 'Nothing has been changed yet. Show this preview to the user and ask for '
                        . 'confirmation. If they agree, call ' . $name . ' again with exactly the same arguments plus '
                        . Confirmation::ARG . '.',
                ]);
            }

            if (!Confirmation::consume($token, $userId, $name, $arguments)) {
                throw new McpToolException('Invalid confirmation: the token is unknown, expired, already used, or the '
                    . 'arguments differ from the previewed call. Call ' . $name . ' again without '
                    . Confirmation::ARG . ' to get a new preview.');
            }
        }

        try {
            $result = ($tool['handler'])($arguments);
        } catch (\Throwable $e) {
            DebugLog::toolError($name, $e);

            throw $e;
        }

        return $this->textResult($result);
    }

    /** Résultat MCP texte (un tableau / objet est rendu en JSON lisible). */
    private function textResult(mixed $result): array
    {
        if (!\is_string($result)) {
            $result = json_encode(
                $result,
                JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
            );
        }

        return [
            'content' => [
                ['type' => 'text', 'text' => (string) $result],
            ],
        ];
    }
}
