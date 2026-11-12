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
 * \file    core/tpl/dolitmd_tmd_card_edit.tpl.php
 * \ingroup dolitmd
 * \brief   Screen « edit » of the card of a TMD request
 *
 * Rendered by view/tmd/tmd_card.php (inherits its scope).
 * Expects: $object, $form, $extrafields, $langs, $permissiontoadd, $backtopage, $backtopageforcancel
 */

if (empty($permissiontoadd)) {
    accessforbidden($langs->trans('NotEnoughPermissions'), 0);
    exit;
}

print load_fiche_titre($langs->trans('ModifyTmd'), '', 'object_' . $object->picto);

print '<form method="POST" action="' . $_SERVER['PHP_SELF'] . '">';
print '<input type="hidden" name="token" value="' . newToken() . '">';
print '<input type="hidden" name="action" value="update">';
print '<input type="hidden" name="id" value="' . ((int) $object->id) . '">';
if ($backtopage) {
    print '<input type="hidden" name="backtopage" value="' . dol_escape_htmltag($backtopage) . '">';
}
if ($backtopageforcancel) {
    print '<input type="hidden" name="backtopageforcancel" value="' . dol_escape_htmltag($backtopageforcancel) . '">';
}

print dol_get_fiche_head();

print '<table class="border centpercent tableforfieldedit">';

require DOL_DOCUMENT_ROOT . '/core/tpl/commonfields_edit.tpl.php';

print '</table>';

print dol_get_fiche_end();

print $form->buttonsSaveCancel();

print '</form>';
