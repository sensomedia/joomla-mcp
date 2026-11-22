<?php
/**
 * @copyright (C) 2026 Sensomedia. All rights reserved.
 * @license   GNU General Public License version 2 or later
 */

namespace Sensomedia\Plugin\Sensomcp\Content\Support;

use Joomla\CMS\Factory;

\defined('_JEXEC') or die;

/**
 * Accès au modèle Administrator « Article » de com_content et à son save(),
 * partagé par les outils articles et versions. Compatible J5 et J6.
 */
trait SavesArticleModel
{
    /** Modèle Administrator « Article » de com_content. */
    private function articleModel()
    {
        return Factory::getApplication()->bootComponent('com_content')
            ->getMVCFactory()->createModel('Article', 'Administrator', ['ignore_request' => true]);
    }

    /**
     * Exécute le save() du modèle Article en exposant les liens A/B/C comme le
     * formulaire admin les poste (`jform[urls]`).
     *
     * ArticleModel::save() (J5 et J6) relit `$input->post->get('jform')['urls']`
     * pour garder telles quelles les ancres (#section) que le filtre URL du
     * formulaire réécrirait, et convertit les autres liens en punycode. En
     * contexte API ce POST n'existe pas (avertissements PHP sur `$check['urls']`
     * dès qu'un lien est renseigné) : on le pose le temps du save, puis on
     * restaure la valeur précédente.
     *
     * @param array<string,string>|null $urls liens bruts (avant filtre) ; null = non fournis →
     *                                        on poste les liens que FormGate a remis dans $data
     */
    private function saveWithPostedUrls($model, array $data, ?array $urls = null): bool
    {
        $urls ??= \is_array($data['urls'] ?? null) ? $data['urls'] : null;

        if ($urls === null) {
            return (bool) $model->save($data);
        }

        $post     = Factory::getApplication()->getInput()->post;
        $previous = $post->get('jform', null, 'raw');
        $post->set('jform', array_merge(\is_array($previous) ? $previous : [], ['urls' => $urls]));

        try {
            return (bool) $model->save($data);
        } finally {
            $post->set('jform', $previous);
        }
    }

    /** Messages d'erreur d'un modèle du cœur. */
    private function modelErrors($model): string
    {
        $errors = method_exists($model, 'getErrors') ? $model->getErrors() : [];

        if (!$errors && method_exists($model, 'getError')) {
            $errors = [$model->getError()];
        }

        $errors = array_filter(array_map(
            static fn ($e) => $e instanceof \Throwable ? $e->getMessage() : (string) $e,
            $errors
        ));

        return $errors ? implode(' | ', $errors) : 'unknown error';
    }
}
