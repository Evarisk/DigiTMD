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
 * \file    view/tmd/tmd_card.php
 * \ingroup dolitmd
 * \brief   Card of a TMD request: controller, the screens are in core/tpl/dolitmd_tmd_card_*.tpl.php
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
require_once __DIR__ . '/../../class/tmd.class.php';
require_once __DIR__ . '/../../lib/dolitmd.lib.php';
require_once __DIR__ . '/../../lib/dolitmd_tmd.lib.php';

// Global variables definitions
global $conf, $db, $hookmanager, $langs, $user;

// Load translation files required by the page
saturne_load_langs(['ticket']);

// Get parameters
$id                  = GETPOSTINT('id');
$ref                 = GETPOST('ref', 'alpha');
$action              = GETPOST('action', 'aZ09');
$confirm             = GETPOST('confirm', 'alpha');
$cancel              = GETPOST('cancel', 'aZ09');
$backtopage          = GETPOST('backtopage', 'alpha');
$backtopageforcancel = GETPOST('backtopageforcancel', 'alpha');

// Initialize technical objects
$object      = new Tmd($db);
$extrafields = new ExtraFields($db);
$form        = new Form($db);

$hookmanager->initHooks(['tmdcard', 'globalcard']);

if (empty($action) && empty($id) && empty($ref)) {
    $action = 'view';
}

include DOL_DOCUMENT_ROOT . '/core/actions_fetchobject.inc.php'; // Must be included, not include_once

// A deleted request is never shown
if ($object->id > 0 && (int) $object->status === Tmd::STATUS_DELETED) {
    accessforbidden($langs->trans('ErrorRecordNotFound'));
}

// Security check
$permissiontoread   = $user->hasRight('dolitmd', 'tmd', 'read');
$permissiontoadd    = $user->hasRight('dolitmd', 'tmd', 'write');
$permissiontodelete = $user->hasRight('dolitmd', 'tmd', 'delete');
saturne_check_access($permissiontoread, $object);

/*
 * Actions
 */

$parameters = ['id' => $id];
$resHook    = $hookmanager->executeHooks('doActions', $parameters, $object, $action);
if ($resHook < 0) {
    setEventMessages($hookmanager->error, $hookmanager->errors, 'errors');
}

if (empty($resHook)) {
    $listUrl = dol_buildpath('/saturne/view/saturne_list.php', 1) . '?object_type=tmd&mainmenu=dolitmd&leftmenu=tmd';
    if (empty($backtopage) || ($cancel && empty($id))) {
        if (empty($id) && (($action != 'add' && $action != 'create') || $cancel)) {
            $backtopage = $listUrl;
        } else {
            $backtopage = dol_buildpath('/dolitmd/view/tmd/tmd_card.php', 1) . '?id=' . ($id > 0 ? $id : '__ID__');
        }
    }
    $backurlforlist = $listUrl;
    $triggermodname = 'TMD_MODIFY';

    // Mail « request done », read again on the card then sent: to the sender and the people in copy of his mail, the
    // documents of the request attached; the request is done once it is sent
    $doneForm = null;
    if (in_array($action, ['presenddone', 'senddone'], true) && $permissiontoadd && $object->id > 0) {
        require_once __DIR__ . '/../../class/tmdmailer.class.php';
        $mailer = new TmdMailer($db);
        if ($action == 'presenddone') {
            $draft    = $mailer->doneDraft($object);
            $doneForm = ['to' => $draft['to'], 'cc' => implode(', ', array_column($draft['cc'], 'email')), 'subject' => $draft['subject'], 'html' => $draft['html'], 'files' => array_column($draft['files'], 'name')];
        } else {
            // Addresses with their names (« Name <a@b.c> »): read as typed, each one checked by parseAddresses()
            $doneForm = ['to' => GETPOST('sendto', 'none'), 'cc' => GETPOST('sendtocc', 'none'), 'subject' => GETPOST('subject', 'alphanohtml'), 'html' => GETPOST('message', 'restricthtml'), 'files' => GETPOST('files', 'array')];
            $bad      = [];
            $to       = TmdMailer::parseAddresses((string) $doneForm['to'], $bad);
            $cc       = TmdMailer::parseAddresses((string) $doneForm['cc'], $bad);
            $html     = dol_textishtml($doneForm['html']) ? $doneForm['html'] : dol_nl2br(dol_escape_htmltag($doneForm['html']));
            $error    = '';
            if (empty($to)) {
                $error = $langs->trans('ErrorFieldRequired', $langs->transnoentities('MailTo'));
            } elseif (!empty($bad)) {
                $error = $langs->trans('TmdMailBadAddresses', implode(', ', $bad));
            } elseif (trim($doneForm['subject']) === '') {
                $error = $langs->trans('ErrorFieldRequired', $langs->transnoentities('MailTopic'));
            } elseif (trim(dol_string_nohtmltag($html)) === '') {
                $error = $langs->trans('ErrorFieldRequired', $langs->transnoentities('MailText'));
            } elseif (!$mailer->sendDoneMail($object, $user, $to, $cc, trim($doneForm['subject']), $html, array_map('strval', (array) $doneForm['files']))) {
                $error = $langs->trans('TmdMailNotSent', $mailer->error);
            }

            if ($error === '') {
                // Sent: the request is done (the ticket closed)
                if (empty($object->done)) {
                    // Closing the ticket prints warnings of the core 24 (Ticket::listeContact): never before the redirect
                    ob_start();
                    $result = $object->setFlag($user, 'done', 1);
                    ob_end_clean();
                    if ($result < 0) {
                        setEventMessages($object->error, $object->errors, 'warnings');
                    }
                }
                setEventMessages($langs->trans('TmdMailDoneSentOk', implode(', ', array_column(array_merge($to, $cc), 'email'))), null);
                header('Location: ' . $_SERVER['PHP_SELF'] . '?id=' . $object->id);
                exit;
            }
            setEventMessages($error, null, 'errors');
            $action = 'presenddone';
        }
    }

    require_once DOL_DOCUMENT_ROOT . '/core/actions_addupdatedelete.inc.php';
}

/*
 * View
 */

$title = $langs->trans('Tmd');
saturne_header(0, '', $title, '', '', 0, 0, [], [], '', 'mod-tmd page-card');

// One screen per state of the page
if ($action == 'create') {
    $showScreen = 'create';
} elseif (($id || $ref) && $action == 'edit') {
    $showScreen = 'edit';
} elseif ($object->id > 0) {
    $showScreen = 'view';
} else {
    $showScreen = '';
}

$screenFile = __DIR__ . '/../../core/tpl/dolitmd_tmd_card_' . $showScreen . '.tpl.php';
if ($showScreen !== '' && is_readable($screenFile)) {
    include $screenFile;
}

// End of page
llxFooter();
$db->close();
