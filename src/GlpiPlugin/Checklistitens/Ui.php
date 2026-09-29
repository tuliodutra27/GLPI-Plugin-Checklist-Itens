<?php

namespace GlpiPlugin\Checklistitens;

use Glpi\Application\View\TemplateRenderer;
use Glpi\Toolbox\Sanitizer;
use Html;
use Plugin;
use Session;

/**
 * Utilidades de tela: cabeçalho/rodapé na interface certa (simplificada ou padrão), templates,
 * URLs do plugin e conversão de valores do banco para exibição.
 */
class Ui
{
    public static function isHelpdesk(): bool
    {
        return Session::getCurrentInterface() === 'helpdesk';
    }

    /**
     * @param string $option chave da opção do menu (Menu::getMenuContent) destacada na interface padrão
     */
    public static function header(string $title, string $option = 'home'): void
    {
        if (self::isHelpdesk()) {
            Html::helpHeader($title, 'plugins', 'checklistitens');
        } else {
            Html::header($title, $_SERVER['PHP_SELF'], 'assets', Menu::class, $option);
        }

        $dir = Plugin::getWebDir('checklistitens', false);
        echo Html::css($dir . '/css/checklistitens.css');
        echo Html::script($dir . '/js/checklistitens.js');
    }

    public static function footer(): void
    {
        if (self::isHelpdesk()) {
            Html::helpFooter();
        } else {
            Html::footer();
        }
    }

    public static function render(string $template, array $params = []): void
    {
        TemplateRenderer::getInstance()->display('@checklistitens/' . $template, $params);
    }

    public static function url(string $path): string
    {
        return Plugin::getWebDir('checklistitens') . '/' . ltrim($path, '/');
    }

    public static function logoutUrl(): string
    {
        global $CFG_GLPI;

        return $CFG_GLPI['root_doc'] . '/front/logout.php?noAUTO=1';
    }

    /**
     * Valor vindo do banco (o GLPI 10 grava com caracteres HTML codificados) para texto puro.
     * O Twig escapa de novo na saída.
     */
    public static function text($value): string
    {
        return Sanitizer::getVerbatimValue((string) $value);
    }

    public static function datetime(?string $value): string
    {
        return $value ? (string) Html::convDateTime($value) : '';
    }
}
