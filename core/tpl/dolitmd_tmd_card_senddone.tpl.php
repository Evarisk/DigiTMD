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
 * \file    core/tpl/dolitmd_tmd_card_senddone.tpl.php
 * \ingroup dolitmd
 * \brief   Form of the mail « request done » under the card of a TMD request: recipients, copies, subject, message and
 *          documents read again before sending (action senddone of view/tmd/tmd_card.php)
 *
 * Rendered by core/tpl/dolitmd_tmd_card_view.tpl.php (inherits its scope).
 * Expects: $object, $langs, $mailer (TmdMailer), $doneForm (to, cc, subject, html, files)
 */

require_once DOL_DOCUMENT_ROOT . '/core/class/doleditor.class.php';

$langs->load('mails');

print '<div id="dolitmd-senddone" class="dolitmd-senddone">';
print load_fiche_titre($langs->trans('TmdSendDoneTitle'), '', 'fa-paper-plane');

print '<form method="POST" action="' . dol_escape_htmltag($_SERVER['PHP_SELF'] . '?id=' . $object->id) . '#dolitmd-senddone">';
print '<input type="hidden" name="token" value="' . newToken() . '">';
print '<input type="hidden" name="action" value="senddone">';
print '<input type="hidden" name="id" value="' . ((int) $object->id) . '">';

print '<table class="border centpercent">';

print '<tr><td class="titlefieldcreate">' . $langs->trans('MailFrom') . '</td><td>' . dol_escape_htmltag($mailer->senderAddress()) . '</td></tr>';

print '<tr><td class="fieldrequired">' . $langs->trans('MailTo') . '</td><td>';
print '<input type="text" name="sendto" class="quatrevingtpercent" value="' . dol_escape_htmltag((string) $doneForm['to']) . '">';
print '</td></tr>';

print '<tr><td>' . $form->textwithpicto($langs->trans('MailCC'), $langs->trans('TmdSendDoneCopiesHelp')) . '</td><td>';
print '<textarea name="sendtocc" rows="2" class="quatrevingtpercent">' . dol_escape_htmltag((string) $doneForm['cc']) . '</textarea>';
print '</td></tr>';

print '<tr><td class="fieldrequired">' . $langs->trans('MailTopic') . '</td><td>';
print '<input type="text" name="subject" class="quatrevingtpercent" value="' . dol_escape_htmltag((string) $doneForm['subject']) . '">';
print '</td></tr>';

// The documents of the request (tab Documents), all of them attached unless unticked; the files dropped on the card
// while the form is open are added to the list (js/modules/tmd.js)
print '<tr><td class="tdtop">' . $langs->trans('MailFile') . '</td><td>';
$publicFiles = $object->getPublicFiles();
print '<div class="dolitmd-senddone-files">';
if (empty($publicFiles)) {
    print '<span class="opacitymedium dolitmd-senddone-nofile">' . $langs->trans('TmdSendDoneNoDocument') . '</span> ';
}
foreach ($publicFiles as $file) {
    print dolitmd_done_file_html($file, in_array($file['name'], (array) $doneForm['files'], true));
}
print '</div>';
print '<a class="small" href="' . dol_escape_htmltag(dol_buildpath('/saturne/view/saturne_document.php', 1) . '?id=' . $object->id . '&module_name=DoliTMD&object_type=tmd') . '">' . img_picto('', 'file', 'class="pictofixedwidth"') . $langs->trans('TmdSendDoneAddDocument') . '</a>';
print '</td></tr>';

// The message: the editor of Dolibarr when there is one, else the text with its links written out
print '<tr><td class="tdtop fieldrequired">' . $langs->trans('MailText') . '</td><td>';
$messageHtml = (string) $doneForm['html'];
if (isModEnabled('fckeditor')) {
    $editor = new DolEditor('message', $messageHtml, '', 280, 'dolibarr_mailings', 'In', true, true, true, ROWS_8, '80%');
    $editor->Create();
} else {
    $messageText = preg_replace('/<a\b[^>]*href="([^"]*)"[^>]*>(.*?)<\/a>/is', '$2 ($1)', $messageHtml);
    $messageText = preg_replace('/<(br|\/p|\/div|\/li)\b[^>]*>/i', "\n", (string) $messageText);
    $messageText = trim(html_entity_decode(dol_string_nohtmltag((string) $messageText, 0), ENT_QUOTES | ENT_HTML5, 'UTF-8'));
    print '<textarea name="message" rows="12" class="quatrevingtpercent">' . dol_escape_htmltag($messageText) . '</textarea>';
}
print '</td></tr>';

print '</table>';

print '<div class="center">';
if (empty($object->done)) {
    print '<div class="opacitymedium marginbottomonly">' . $langs->trans('TmdSendDoneSetsDone') . '</div>';
}
print '<input type="submit" class="button button-save" value="' . dol_escape_htmltag($langs->trans('TmdSendDoneButton')) . '">';
print ' <a class="button button-cancel" href="' . dol_escape_htmltag($_SERVER['PHP_SELF'] . '?id=' . $object->id) . '">' . $langs->trans('Cancel') . '</a>';
print '</div>';

print '</form>';
print '</div>';
