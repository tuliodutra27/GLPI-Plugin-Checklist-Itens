<?php

use GlpiPlugin\Checklistitens\Input;
use GlpiPlugin\Checklistitens\PendingReturn;
use GlpiPlugin\Checklistitens\Ui;
use GlpiPlugin\Checklistitens\UsageNote;

include('../../../inc/includes.php');

Session::checkLoginUser();
if (!PendingReturn::canUse()) {
    Html::displayRightError();
}

if (isset($_POST['close']) || isset($_POST['save_note'])) {
    $note   = Input::comment('note', UsageNote::MAX_LENGTH);
    $result = isset($_POST['close'])
        ? PendingReturn::close(Input::list('usages'), $note, Input::text('confirm') === '1')
        : PendingReturn::saveNote(Input::int('save_note'), $note);
    Session::addMessageAfterRedirect(htmlspecialchars($result['message']), false, $result['ok'] ? INFO : ERROR);
    Html::redirect(Ui::url('front/pendingreturn.php'));
}

Ui::header(PendingReturn::getTypeName(), 'pendingreturn');
Ui::render('pendingreturn.html.twig', PendingReturn::getPageData() + [
    'post_url' => Ui::url('front/pendingreturn.php'),
    'home_url' => Ui::url('front/home.php'),
]);
Ui::footer();
