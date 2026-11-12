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
 * Expects: $object, $form, $extrafields, $hookmanager, $langs, $action, $title, $permissiontoadd, $permissiontodelete
 */

saturne_get_fiche_head($object, 'card', $title);
saturne_banner_tab($object);

$formConfirm = '';
if ($action == 'delete') {
    $formConfirm = $form->formconfirm($_SERVER['PHP_SELF'] . '?id=' . $object->id, $langs->trans('DeleteObject', $langs->transnoentities('TheTmd')), $langs->trans('ConfirmDeleteObject', $langs->transnoentities('TheTmd')), 'confirm_delete', '', 'yes', 1);
}
$parameters = ['formConfirm' => $formConfirm];
$resHook    = $hookmanager->executeHooks('formConfirm', $parameters, $object, $action);
if (empty($resHook)) {
    $formConfirm .= $hookmanager->resPrint;
} elseif ($resHook > 0) {
    $formConfirm = $hookmanager->resPrint;
}
print $formConfirm;

print '<div class="fichecenter">';
print '<div class="fichehalfleft">';
print '<table class="border centpercent tableforfield">';

// The subject is the title of the banner already
unset($object->fields['label']);

require DOL_DOCUMENT_ROOT . '/core/tpl/commonfields_view.tpl.php';

print '</table>';
print '</div>';

// Customer tracking: the public page of the request (link of the mails), its number, the documents the customer sees
$publicUrl = $object->getPublicUrl();
if ($publicUrl !== '') {
    $nbPublicFiles = count($object->getPublicFiles());
    print '<div class="fichehalfright">';
    print '<div class="underbanner clearboth"></div>';
    print '<table class="border centpercent tableforfield">';
    print '<tr><td class="titlefield">' . $form->textwithpicto($langs->trans('TmdTrackId'), $langs->trans('TmdPublicTrackingHelp')) . '</td><td><span class="dolitmd-tracking-number">' . showValueWithClipboardCPButton((string) $object->track_id, 0) . '</span></td></tr>';
    print '<tr><td>' . $langs->trans('TmdPublicPage') . '</td><td>';
    print '<a href="' . dol_escape_htmltag($publicUrl) . '" target="_blank" rel="noopener"><i class="fas fa-globe pictofixedwidth"></i>' . $langs->trans('TmdPublicOpen') . '</a>';
    print ' <span class="opacitymedium marginleftonly">' . showValueWithClipboardCPButton($publicUrl, 0, $langs->transnoentities('TmdPublicCopyLink')) . '</span>';
    print '</td></tr>';
    print '<tr><td>' . $langs->trans('Documents') . '</td><td>';
    print '<a href="' . dol_escape_htmltag(dol_buildpath('/saturne/view/saturne_document.php', 1) . '?id=' . $object->id . '&module_name=DoliTMD&object_type=tmd') . '">' . img_picto('', 'file', 'class="pictofixedwidth"') . $langs->trans('TmdPublicDocumentsShared', $nbPublicFiles) . '</a>';
    print '</td></tr>';
    print '</table>';
    print '</div>';
}

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
    print dolGetButtonAction('<i class="fas fa-edit pictofixedwidth"></i>' . $langs->trans('Modify'), '', 'default', $_SERVER['PHP_SELF'] . '?id=' . $object->id . '&action=edit&token=' . newToken(), '', $permissiontoadd);
    print dolGetButtonAction('<i class="fas fa-trash pictofixedwidth"></i>' . $langs->trans('Delete'), '', 'delete', $_SERVER['PHP_SELF'] . '?id=' . $object->id . '&action=delete&token=' . newToken(), '', $permissiontodelete);
}
print '</div>';
