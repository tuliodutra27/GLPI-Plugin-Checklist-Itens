<?php

namespace GlpiPlugin\Checklistitens;

use Html;
use Session;

/**
 * Tela inicial depois do login: usuários dos perfis configurados (padrão: "operador") abrem
 * direto a tela do plugin em vez da página inicial do GLPI.
 *
 * Roda no hook post_init (toda requisição), então sai o mais cedo possível. Só age na primeira
 * abertura da página inicial do GLPI na sessão; depois disso o "Início" do GLPI funciona normal.
 * Links diretos (ex.: link de chamado num e-mail) não são afetados, porque não passam pela
 * página inicial.
 */
class Landing
{
    private const SESSION_FLAG = 'plugin_checklistitens_landed';

    public static function redirectAfterLogin(): void
    {
        if (isCommandLine() || !empty($_SESSION[self::SESSION_FLAG]) || !Session::getLoginUserID()) {
            return;
        }
        if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'GET' || count($_GET) > 0) {
            // ex.: helpdesk.public.php?create_ticket=1 (perfil configurado para abrir chamado ao entrar)
            return;
        }
        if (!preg_match('#/front/(helpdesk\.public|central)\.php$#', (string) ($_SERVER['SCRIPT_NAME'] ?? ''))) {
            return;
        }

        // Primeira página inicial da sessão: decide uma vez só
        $_SESSION[self::SESSION_FLAG] = true;

        $profile = (int) ($_SESSION['glpiactiveprofile']['id'] ?? 0);
        if (!in_array($profile, Config::getLandingProfiles(), true) || !Profile::canUse()) {
            return;
        }

        Html::redirect(Ui::url('front/home.php'));
    }
}
