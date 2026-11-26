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
 * \file    core/tpl/dolitmd_tmd_inbox_link.tpl.php
 * \ingroup dolitmd
 * \brief   « Link to a TMD request » of a mail of Inbox: the mail, then the request it is in, or the choice of a request
 *          (action link of view/tmd/tmd_inbox_link.php). js/modules/inbox.js tells Inbox when it is done
 *
 * Rendered by view/tmd/tmd_inbox_link.php (inherits its scope).
 * Expects: $row (row of llx_inbox_mail), $relation (TmdInbox::relationOf()), $current (Tmd|null: the request of the
 * mail or of its thread), $choices (TmdInbox::choices()), $done, $pageUrl, $db, $langs
 */

$cardUrl  = dol_buildpath('/dolitmd/view/tmd/tmd_card.php', 1) . '?id=';
$from     = trim((string) $row->from_name) !== '' ? trim((string) $row->from_name) . ' <' . $row->from_email . '>' : (string) $row->from_email;
$dateMail = !empty($row->date_received) ? $db->jdate($row->date_received) : (!empty($row->date_mail) ? $db->jdate($row->date_mail) : '');

print '<div class="dolitmd-inbox-link"' . ($done ? ' data-dolitmd-inbox-done="1"' : '') . '>';
print load_fiche_titre($langs->trans('TmdInboxLinkTitle'), '', 'fontawesome_fa-link_fas_#E67E22');

print '<table class="border centpercent tableforfield dolitmd-inbox-link__mail">';
print '<tr><td class="titlefield">' . $langs->trans('MailFrom') . '</td><td>' . dol_escape_htmltag($from) . '</td></tr>';
print '<tr><td>' . $langs->trans('MailTopic') . '</td><td>' . dol_escape_htmltag((string) $row->subject) . '</td></tr>';
if ($dateMail !== '') {
    print '<tr><td>' . $langs->trans('Date') . '</td><td>' . dol_print_date($dateMail, 'dayhour', 'tzuserrel') . '</td></tr>';
}
print '</table>';

if ($relation['in'] && $current !== null) {
    // Already in a request: shown, never moved (two requests are merged from the list)
    print '<div class="' . ($done ? 'ok' : 'info') . ' dolitmd-inbox-link__state">';
    print dol_escape_htmltag($langs->transnoentities($done ? 'TmdInboxLinked' : 'TmdInboxAlreadyIn', $current->ref));
    print ' <a href="' . dol_escape_htmltag($cardUrl . $current->id) . '" target="_blank" rel="noopener">' . $langs->trans('TmdInboxOpenRequest') . '</a>';
    print '</div>';
    print '<div class="center"><button type="button" class="button button-cancel dolitmd-inbox-close">' . $langs->trans('Close') . '</button></div>';
} elseif (empty($choices)) {
    print '<div class="warning dolitmd-inbox-link__state">' . dol_escape_htmltag($langs->trans('TmdInboxNoRequestToLink')) . '</div>';
    print '<div class="center"><button type="button" class="button button-cancel dolitmd-inbox-close">' . $langs->trans('Close') . '</button></div>';
} else {
    print '<form method="POST" action="' . dol_escape_htmltag($pageUrl) . '" class="dolitmd-inbox-link__form">';
    print '<input type="hidden" name="token" value="' . newToken() . '">';
    print '<input type="hidden" name="action" value="link">';
    print '<input type="hidden" name="mail" value="' . ((int) $row->rowid) . '">';

    print '<table class="border centpercent tableforfield">';
    print '<tr><td class="titlefield fieldrequired"><label for="tmdid">' . $langs->trans('TmdInboxRequest') . '</label></td><td>';
    print '<select id="tmdid" name="tmdid" class="minwidth300 widthcentpercentminusx">';
    print '<option value="0"></option>';
    // The request of the thread, then the ones of the sender or of his thirdparty first, the first one chosen. No
    // optgroup: the search of the select2 of Dolibarr (ajax_combobox) never looks into their options
    $selected = $relation['id'] > 0 ? (int) $relation['id'] : (!empty($choices[0]['suggested']) ? (int) $choices[0]['id'] : 0);
    foreach ($choices as $choice) {
        print '<option value="' . $choice['id'] . '"' . ($choice['id'] === $selected ? ' selected' : '') . '>' . dol_escape_htmltag($choice['label']) . '</option>';
    }
    print '</select>';
    print ajax_combobox('tmdid');
    print '</td></tr>';
    print '</table>';

    print '<div class="opacitymedium dolitmd-inbox-link__help">' . dol_escape_htmltag($langs->trans('TmdInboxLinkHelp')) . '</div>';

    print '<div class="center">';
    print '<input type="submit" class="button button-save" value="' . dol_escape_htmltag($langs->trans('TmdInboxLinkButton')) . '">';
    print ' <button type="button" class="button button-cancel dolitmd-inbox-close">' . $langs->trans('Cancel') . '</button>';
    print '</div>';
    print '</form>';
}

print '</div>';
