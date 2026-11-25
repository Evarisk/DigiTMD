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
 * \file    core/tpl/dolitmd_tmd_card_view.tpl.php
 * \ingroup dolitmd
 * \brief   Screen « view » of the card of a TMD request: the ticks are saved at once (js/modules/tmd.js)
 *
 * Rendered by view/tmd/tmd_card.php (inherits its scope).
 * Expects: $object, $db, $form, $extrafields, $hookmanager, $langs, $action, $title, $permissiontoadd, $permissiontodelete,
 *          $dropZone (null when no file may be dropped), $fileToDelete (document whose deletion is confirmed, empty when
 *          none)
 */

saturne_get_fiche_head($object, 'card', $title);
saturne_banner_tab($object);

$formConfirm = '';
if ($action == 'delete') {
    // Its ticket is cancelled with it (Tmd::delete())
    $formConfirm = $form->formconfirm($_SERVER['PHP_SELF'] . '?id=' . $object->id, $langs->trans('DeleteObject', $langs->transnoentities('TheTmd')), $langs->trans('TmdConfirmDelete', dol_escape_htmltag($object->ref)), 'confirm_delete', '', 'yes', 1);
} elseif ($action == 'deletefile' && $fileToDelete !== '') {
    // A document of the line « Attached files », deleted once confirmed (action confirm_deletefile of the card)
    $formConfirm = $form->formconfirm($_SERVER['PHP_SELF'] . '?id=' . $object->id . '&urlfile=' . urlencode($fileToDelete), $langs->trans('TmdDeleteDocument'), $langs->trans('TmdConfirmDeleteDocument', dol_escape_htmltag($fileToDelete)), 'confirm_deletefile', '', 'yes', 1);
} elseif ($action == 'splitmessage' && $permissiontoadd) {
    // A message of the thread taken out as a request of its own: its subject given, the one of its mail proposed
    require_once __DIR__ . '/../../class/tmdmerger.class.php';
    $merger    = new TmdMerger($db);
    $messageId = GETPOSTINT('messageid');
    if ($merger->threadMessage($object, $messageId) === null) {
        setEventMessages($langs->trans('TmdSplitMessageNotFound'), null, 'errors');
    } else {
        $question    = [
            ['type' => 'hidden', 'name' => 'messageid', 'value' => $messageId],
            ['type' => 'text', 'name' => 'split_subject', 'label' => $langs->trans('TmdSubject'), 'value' => dol_escape_htmltag($merger->splitSubject($object, $messageId)), 'morecss' => 'minwidth400 widthcentpercentminusx'],
        ];
        $formConfirm = $form->formconfirm($_SERVER['PHP_SELF'] . '?id=' . $object->id, $langs->trans('TmdSplitTitle'), $langs->trans('TmdSplitQuestion', $object->ref), 'confirm_splitmessage', $question, 'yes', 1, 0, 700);
    }
}
$parameters = ['formConfirm' => $formConfirm];
$resHook    = $hookmanager->executeHooks('formConfirm', $parameters, $object, $action);
if (empty($resHook)) {
    $formConfirm .= $hookmanager->resPrint;
} elseif ($resHook > 0) {
    $formConfirm = $hookmanager->resPrint;
}
print $formConfirm;

// A document of the tab Documents with the nomeref ID of another request, or of another site of the customer: warned,
// never refused (another name may be wanted)
foreach ($object->getMismatchedDocuments() as $fileName) {
    print dolitmd_document_mismatch_html($object, $fileName);
}

print '<div class="fichecenter">';
print '<div class="fichehalfleft">';
print '<table class="border centpercent tableforfield">';

// The subject is the title of the banner already
unset($object->fields['label']);

require DOL_DOCUMENT_ROOT . '/core/tpl/commonfields_view.tpl.php';

print '</table>';
print '</div>';

// Right column: the customer tracking (public page of the request, link of the mails, its number, the documents the
// customer sees), then the mail of the customer and the messages of its ticket
$publicUrl = $object->getPublicUrl();
print '<div class="fichehalfright">';
if ($publicUrl !== '') {
    $publicFiles = $object->getPublicFiles();
    print '<div class="underbanner clearboth"></div>';
    print '<table class="border centpercent tableforfield">';
    print '<tr><td class="titlefield">' . $form->textwithpicto($langs->trans('TmdTrackId'), $langs->trans('TmdPublicTrackingHelp')) . '</td><td><span class="dolitmd-tracking-number">' . showValueWithClipboardCPButton((string) $object->track_id, 0) . '</span></td></tr>';
    print '<tr><td>' . $langs->trans('TmdPublicPage') . '</td><td>';
    print '<a href="' . dol_escape_htmltag($publicUrl) . '" target="_blank" rel="noopener"><i class="fas fa-globe pictofixedwidth"></i>' . $langs->trans('TmdPublicOpen') . '</a>';
    print ' <span class="opacitymedium marginleftonly">' . showValueWithClipboardCPButton($publicUrl, 0, $langs->transnoentities('TmdPublicCopyLink')) . '</span>';
    print '</td></tr>';
    // The documents themselves, a line each, opened in the preview without going to the tab
    if (!empty($publicFiles)) {
        print '<tr><td class="tdtop">' . $langs->trans('Documents') . '</td><td>';
        print '<div class="dolitmd-documents">';
        foreach ($publicFiles as $file) {
            print dolitmd_document_line_html($object, $file, $permissiontoadd);
        }
        print '</div>';
        print '</td></tr>';
    }
    print '</table>';
}
// The mail of the customer and the messages of its ticket, without opening the ticket
include __DIR__ . '/dolitmd_tmd_card_request.tpl.php';
print '</div>';

print '</div>';
print '<div class="clearboth"></div>';

print dol_get_fiche_end();

// Buttons for actions
print '<div class="tabsAction">';
$parameters = [];
$resHook    = $hookmanager->executeHooks('addMoreActionsButtons', $parameters, $object, $action);
if ($resHook < 0) {
    setEventMessages($hookmanager->error, $hookmanager->errors, 'errors');
}
if (empty($resHook)) {
    if ($object->fk_ticket > 0 && $user->hasRight('ticket', 'read')) {
        print dolGetButtonAction('<i class="fas fa-ticket-alt pictofixedwidth"></i>' . $langs->trans('TmdOpenTicket'), '', 'default', DOL_URL_ROOT . '/ticket/card.php?id=' . ((int) $object->fk_ticket), '', 1);
    }
    if ($publicUrl !== '') {
        print dolGetButtonAction('<i class="fas fa-globe pictofixedwidth"></i>' . $langs->trans('TmdPublicPage'), '', 'default', $publicUrl, '', 1, ['attr' => ['target' => '_blank', 'rel' => 'noopener']]);
    }
    // A message to the customer while the request is being done (a question, a piece missing...): the state is kept
    print dolGetButtonAction('<i class="fas fa-reply pictofixedwidth"></i>' . $langs->trans('TmdSendMessage'), '', 'default', $_SERVER['PHP_SELF'] . '?id=' . $object->id . '&action=presendmessage&token=' . newToken() . '#dolitmd-sendmail', '', $permissiontoadd);
    print dolGetButtonAction('<i class="fas fa-paper-plane pictofixedwidth"></i>' . $langs->trans('TmdSendDone'), '', 'default', $_SERVER['PHP_SELF'] . '?id=' . $object->id . '&action=presenddone&token=' . newToken() . '#dolitmd-sendmail', '', $permissiontoadd);
    print dolGetButtonAction('<i class="fas fa-edit pictofixedwidth"></i>' . $langs->trans('Modify'), '', 'default', $_SERVER['PHP_SELF'] . '?id=' . $object->id . '&action=edit&token=' . newToken(), '', $permissiontoadd);    print dolGetButtonAction('<i class="fas fa-trash pictofixedwidth"></i>' . $langs->trans('Delete'), '', 'delete', $_SERVER['PHP_SELF'] . '?id=' . $object->id . '&action=delete&token=' . newToken(), '', $permissiontodelete);
}
print '</div>';

// Mail to the customer (a message, or « request done »), read again before sending it
if (in_array($action, ['presendmessage', 'presenddone'], true) && !empty($mailForm) && $permissiontoadd) {
    include __DIR__ . '/dolitmd_tmd_card_sendmail.tpl.php';
}

// Unknown sender: the modal of the button « + » of its address
if (empty($object->fk_soc) && !empty($object->email_from) && dolitmd_can_create_thirdparty($user)) {
    include __DIR__ . '/dolitmd_thirdparty_create_modal.tpl.php';
}

// Files dropped anywhere on the card: the cover shown while they are dragged
if (!empty($dropZone)) {
    include __DIR__ . '/dolitmd_tmd_card_dropzone.tpl.php';
}
