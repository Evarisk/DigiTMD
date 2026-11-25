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

// A deleted request is never shown: one merged into another request leads to that one
if ($object->id > 0 && (int) $object->status === Tmd::STATUS_DELETED) {
    require_once __DIR__ . '/../../class/tmdmerger.class.php';
    $mergedInto = TmdMerger::mergedInto($object);
    $target     = new Tmd($db);
    if ($mergedInto > 0 && $user->hasRight('dolitmd', 'tmd', 'read') && $target->fetch($mergedInto) > 0) {
        setEventMessages($langs->trans('TmdMergedInto', $object->ref, $target->ref), null, 'warnings');
        header('Location: ' . $_SERVER['PHP_SELF'] . '?id=' . $target->id);
        exit;
    }
    accessforbidden($langs->trans('ErrorRecordNotFound'));
}

// Security check
$permissiontoread   = $user->hasRight('dolitmd', 'tmd', 'read');
$permissiontoadd    = $user->hasRight('dolitmd', 'tmd', 'write');
$permissiontodelete = $user->hasRight('dolitmd', 'tmd', 'delete');
saturne_check_access($permissiontoread, $object);

// Still without nomeref ID, a document of the tab Documents with one in its name: taken at the display of the card,
// when the trigger of the document did not (document added before this was done, or by another way than the tab)
if ($object->id > 0 && $permissiontoadd && in_array($action, ['', 'view'], true)) {
    $object->fillSoftwareRefFromDocuments($user);
}

/*
 * Actions
 */

$fileToDelete = '';
$parameters   = ['id' => $id];
$resHook      = $hookmanager->executeHooks('doActions', $parameters, $object, $action);
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

    // Mails of the card, read again then sent to the sender and the people in copy of his mail: a message while the
    // request is being done (its state kept), or « request done » with the documents of the request attached (the
    // request is done once it is sent)
    $mailForm  = null;
    $cardMails = ['presendmessage' => 'message', 'sendmessage' => 'message', 'presenddone' => 'done', 'senddone' => 'done'];
    if (isset($cardMails[$action]) && $permissiontoadd && $object->id > 0) {
        require_once __DIR__ . '/../../class/tmdmailer.class.php';
        $mailer = new TmdMailer($db);
        $mail   = $cardMails[$action];
        if (strpos($action, 'presend') === 0) {
            $draft    = $mailer->cardDraft($object, $mail);
            $mailForm = ['mail' => $mail, 'to' => $draft['to'], 'cc' => implode(', ', array_column($draft['cc'], 'email')), 'subject' => $draft['subject'], 'html' => $draft['html'], 'files' => $draft['attach']];
        } else {
            // Addresses with their names (« Name <a@b.c> »): read as typed, each one checked by parseAddresses()
            $mailForm = ['mail' => $mail, 'to' => GETPOST('sendto', 'none'), 'cc' => GETPOST('sendtocc', 'none'), 'subject' => GETPOST('subject', 'alphanohtml'), 'html' => GETPOST('message', 'restricthtml'), 'files' => GETPOST('files', 'array')];
            $bad      = [];
            $to       = TmdMailer::parseAddresses((string) $mailForm['to'], $bad);
            $cc       = TmdMailer::parseAddresses((string) $mailForm['cc'], $bad);
            $html     = dol_textishtml($mailForm['html']) ? $mailForm['html'] : dol_nl2br(dol_escape_htmltag($mailForm['html']));
            $error    = '';
            if (empty($to)) {
                $error = $langs->trans('ErrorFieldRequired', $langs->transnoentities('MailTo'));
            } elseif (!empty($bad)) {
                $error = $langs->trans('TmdMailBadAddresses', implode(', ', $bad));
            } elseif (trim($mailForm['subject']) === '') {
                $error = $langs->trans('ErrorFieldRequired', $langs->transnoentities('MailTopic'));
            } elseif (trim(dol_string_nohtmltag($html)) === '') {
                $error = $langs->trans('ErrorFieldRequired', $langs->transnoentities('MailText'));
            } elseif (!$mailer->sendCardMail($mail, $object, $user, $to, $cc, trim($mailForm['subject']), $html, array_map('strval', (array) $mailForm['files']))) {
                $error = $langs->trans('TmdMailNotSent', $mailer->error);
            }

            if ($error === '') {
                // « Request done » sent: the request is done (the ticket closed); a message leaves the state as it is
                if ($mail == 'done' && empty($object->done)) {
                    // Closing the ticket prints warnings of the core 24 (Ticket::listeContact): never before the redirect
                    ob_start();
                    $result = $object->setFlag($user, 'done', 1);
                    ob_end_clean();
                    if ($result < 0) {
                        setEventMessages($object->error, $object->errors, 'warnings');
                    }
                }
                setEventMessages($langs->trans(TmdMailer::CARD_MAILS[$mail] . 'Ok', implode(', ', array_column(array_merge($to, $cc), 'email'))), null);
                header('Location: ' . $_SERVER['PHP_SELF'] . '?id=' . $object->id);
                exit;
            }
            setEventMessages($error, null, 'errors');
            $action = 'presend' . $mail;
        }
    }

    // A message of the thread taken out as a request of its own (a new request written as an answer to this one), once
    // confirmed. Merging requests is done from the list (mass action, hook doActions)
    if ($action == 'confirm_splitmessage' && $confirm == 'yes' && $permissiontoadd && $object->id > 0) {
        require_once __DIR__ . '/../../class/tmdmerger.class.php';
        $merger = new TmdMerger($db);
        $newId  = $merger->split($object, GETPOSTINT('messageid'), $user, GETPOST('split_subject', 'alphanohtml'));
        if ($newId > 0) {
            $created = new Tmd($db);
            $created->fetch($newId);
            setEventMessages($langs->trans('TmdSplitDone', $created->ref, $object->ref), null);
            header('Location: ' . $_SERVER['PHP_SELF'] . '?id=' . $newId);
            exit;
        }
        setEventMessages($merger->error, $merger->errors, 'errors');
        $action = '';
    }

    // Every attachment of a message of the thread at once (0: the mail of the request), in a ZIP archive made for the
    // download then removed (the files of the ticket: for those who may read the tickets, as each file of the thread)
    if ($action == 'downloadall' && $object->id > 0 && $user->hasRight('ticket', 'read')) {
        $tempDir = (string) ($conf->dolitmd->dir_temp ?? (DOL_DATA_ROOT . '/dolitmd/temp'));
        $zipPath = $tempDir . '/' . dol_sanitizeFileName($object->ref) . '-' . dol_now() . '-' . $user->id . '.zip';
        $added   = dol_mkdir($tempDir) >= 0 ? $object->zipRequestFiles($zipPath, GETPOSTINT('messageid')) : -1;
        if ($added > 0) {
            $zipName = dol_sanitizeFileName($object->ref . '-' . $langs->transnoentities('TmdAttachmentsZip') . '.zip');
            // Nothing before the archive: an output kept in a buffer would break it
            while (ob_get_level() > 0) {
                ob_end_clean();
            }
            header('Content-Type: application/zip');
            header('Content-Disposition: attachment; filename="' . $zipName . '"');
            header('Content-Length: ' . filesize($zipPath));
            header('Cache-Control: private, no-store');
            readfile($zipPath);
            dol_delete_file($zipPath);
            exit;
        }
        dol_delete_file($zipPath);
        setEventMessages($added === 0 ? $langs->trans('TmdNoAttachment') : $langs->trans('TmdZipError', $object->error), null, $added === 0 ? 'warnings' : 'errors');
        header('Location: ' . $_SERVER['PHP_SELF'] . '?id=' . $object->id);
        exit;
    }

    // A document of the line « Attached files » deleted once confirmed, as the tab Documents deletes it: the file, its
    // thumbnails and its index (only its name is read, never a path)
    if (in_array($action, ['deletefile', 'confirm_deletefile'], true) && $permissiontoadd && $object->id > 0) {
        $urlfile = basename((string) GETPOST('urlfile', 'alpha', 0, null, null, 1));
        if (in_array($urlfile, array_column($object->getPublicFiles(), 'name'), true)) {
            $fileToDelete = $urlfile;
        }
        if ($action == 'confirm_deletefile' && $fileToDelete !== '') {
            // Messages of the deletion (FileWasRemoved...)
            $langs->load('other');
            $upload_dir = $object->getUploadDir();
            include DOL_DOCUMENT_ROOT . '/core/actions_linkedfiles.inc.php';
        }
    }

    require_once DOL_DOCUMENT_ROOT . '/core/actions_addupdatedelete.inc.php';
}

// Files dropped anywhere on the card: added to the tab Documents (ajax/tmd.php), as its button « Send file » does.
// Their total size is checked before sending: over post_max_size, PHP would drop the whole POST, token included
$dropZone = null;
if ($object->id > 0 && $permissiontoadd && getDolGlobalString('MAIN_UPLOAD_DOC')) {
    $dropZone = [
        'maxSize' => max(0, (int) getMaxFileSizeArray()['maxmin']) * 1024,
        'cardUrl' => $_SERVER['PHP_SELF'] . '?id=' . $object->id,
    ];
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
