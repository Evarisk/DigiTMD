<?php
/* Copyright (C) 2026 EVARISK <technique@evarisk.com>
 *
 * This program is free software; you can redistribute it and/or modify
 * it under the terms of the GNU General Public License as published by
 * the Free Software Foundation; either version 3 of the License, or
 * (at your option) any later version.
 *
 * This program is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
 * GNU General Public License for more details.
 *
 * You should have received a copy of the GNU General Public License
 * along with this program. If not, see <https://www.gnu.org/licenses/>.
 */

/**
 * \file    view/tmd/tmd_inbox_link.php
 * \ingroup dolitmd
 * \brief   « Link to a TMD request » of a mail of Inbox, opened in a window of Inbox: controller, the screen is
 *          core/tpl/dolitmd_tmd_inbox_link.tpl.php
 */

// Load DoliTMD environment
if (file_exists('../../dolitmd.main.inc.php')) {
    require_once __DIR__ . '/../../dolitmd.main.inc.php';
} elseif (file_exists('../../../dolitmd.main.inc.php')) {
    require_once __DIR__ . '/../../../dolitmd.main.inc.php';
} else {
    die('Include of dolitmd main fails');
}

// Load DoliTMD libraries
require_once __DIR__ . '/../../class/tmdinbox.class.php';

// Global variables definitions
global $db, $hookmanager, $langs, $user;

// Load translation files required by the page
saturne_load_langs(['mails']);

// Get parameters
$mailId = GETPOSTINT('mail');
$action = GETPOST('action', 'aZ09');
$tmdId  = GETPOSTINT('tmdid');

$hookmanager->initHooks(['tmdinboxlink', 'globalcard']);

// Security check
$permissiontoadd = $user->hasRight('dolitmd', 'tmd', 'write');
saturne_check_access($permissiontoadd && isModEnabled('inbox'));

// The mail, read again with the rights of the user in Inbox
$inbox = new TmdInbox($db);
$row   = $inbox->mailRow($mailId, $user);
// Menus of Dolibarr hidden again after the form is sent: the page lives in a window of Inbox
$pageUrl = $_SERVER['PHP_SELF'] . '?mail=' . $mailId . '&dol_hide_topmenu=1&dol_hide_leftmenu=1';

/*
 * Actions
 */

$parameters = ['mail' => $mailId];
$resHook    = $hookmanager->executeHooks('doActions', $parameters, $inbox, $action);
if ($resHook < 0) {
    setEventMessages($hookmanager->error, $hookmanager->errors, 'errors');
}

if (empty($resHook) && $action == 'link' && $permissiontoadd && $row !== null) {
    $tmd = new Tmd($db);
    if ($tmdId <= 0 || $tmd->fetch($tmdId) <= 0 || (int) $tmd->status < 0) {
        setEventMessages($langs->trans('TmdInboxChooseRequest'), [], 'errors');
    } else {
        $handler = new TmdMailHandler($db);
        // The core 24 prints warnings while it reads the contacts of a ticket (Ticket::listeContact()): never before a redirect
        ob_start();
        $result = $handler->attachMail($tmd, $row, $user);
        ob_end_clean();
        if ($result >= 0) {
            // The screen of the page says it is done: no message of the core on top of it
            header('Location: ' . $pageUrl . '&done=1');
            exit;
        }
        setEventMessages($langs->trans($handler->error ?: 'TmdInboxLinkError'), [], 'errors');
    }
}

/*
 * View
 */

$title = $langs->trans('TmdInboxLinkTitle');

saturne_header(0, '', $title, '', '', 0, 0, [], [], '', 'dolitmd-inbox-window');

if ($row === null) {
    print '<div class="error">' . dol_escape_htmltag($langs->trans('TmdInboxMailNotFound')) . '</div>';
} else {
    $relation = $inbox->relationOf($row);
    $current  = new Tmd($db);
    if ($relation['id'] <= 0 || $current->fetch($relation['id']) <= 0) {
        $current = null;
    }
    $choices = $relation['in'] ? [] : $inbox->choices($row, $relation['id']);
    // Back from the link: Inbox is told, it closes the window and reads the panel of the mail again
    $done = GETPOSTINT('done') === 1 && $relation['in'];

    include __DIR__ . '/../../core/tpl/dolitmd_tmd_inbox_link.tpl.php';
}

// End of page
llxFooter();
$db->close();
