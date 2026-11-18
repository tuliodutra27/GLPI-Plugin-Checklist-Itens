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

        echo self::css();
        self::script('js/checklistitens.js');
    }

    /** O CSS do plugin (também para as abas que o plugin põe em telas do GLPI). */
    public static function css(): string
    {
        return Html::css(...self::versioned('css/checklistitens.css'));
    }

    /**
     * Script do plugin. Chamar depois de header(), que já inclui js/checklistitens.js.
     *
     * @param string $file caminho dentro do plugin, ex.: "js/delivery.js"
     */
    public static function script(string $file): void
    {
        echo Html::script(...self::versioned($file));
    }

    /**
     * Endereço e opções de um arquivo do plugin. A versão no link muda quando o arquivo muda,
     * para o navegador não usar CSS/JS antigos.
     *
     * @return array{0: string, 1: array}
     */
    private static function versioned(string $file): array
    {
        $file = ltrim($file, '/');

        return [
            Plugin::getWebDir('checklistitens', false) . '/' . $file,
            ['version' => (string) @filemtime(Plugin::getPhpDir('checklistitens') . '/' . $file)],
        ];
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

    /** URL completa (com o root_doc do GLPI), para links e redirecionamentos. */
    public static function url(string $path): string
    {
        return Plugin::getWebDir('checklistitens') . '/' . ltrim($path, '/');
    }

    /** URL com o endereço do GLPI (url_base), para links fora dele (e-mail das notificações). */
    public static function absoluteUrl(string $path): string
    {
        return Plugin::getWebDir('checklistitens', true, true) . '/' . ltrim($path, '/');
    }

    /** Caminho sem o root_doc, no formato que o menu do GLPI espera. */
    public static function path(string $path): string
    {
        return '/' . Plugin::getWebDir('checklistitens', false) . '/' . ltrim($path, '/');
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

    /**
     * Data de um filtro, só no formato AAAA-MM-DD (o valor vai direto para a consulta); senão ''.
     */
    public static function date($value): string
    {
        $value = is_string($value) ? $value : '';

        return preg_match('/^\d{4}-\d{2}-\d{2}$/', $value) ? $value : '';
    }

    /**
     * Envia um CSV para baixar e encerra: separador ";" e BOM UTF-8, para abrir direto no Excel.
     *
     * @param array<int, array> $rows
     */
    public static function sendCsv(string $filename, array $header, array $rows): void
    {
        header('Content-Type: text/csv; charset=UTF-8');
        header(sprintf('Content-Disposition: attachment; filename="%s"', $filename));

        $out = fopen('php://output', 'w');
        fwrite($out, "\xEF\xBB\xBF");
        fputcsv($out, $header, ';');
        foreach ($rows as $row) {
            fputcsv($out, $row, ';');
        }
        fclose($out);
        exit;
    }
}
