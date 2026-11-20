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
 * \file    core/tpl/dolitmd_tmd_card_dropzone.tpl.php
 * \ingroup dolitmd
 * \brief   Cover of the card of a TMD request while files are dragged over it: dropped anywhere, they go to the tab
 *          Documents (js/modules/tmd.js, action upload_files of ajax/tmd.php); with a mail to the customer open (a
 *          message or « request done »), they are attached to it too
 *
 * Rendered by core/tpl/dolitmd_tmd_card_view.tpl.php (inherits its scope).
 * Expects: $object, $langs, $action, $publicUrl, $dropZone (maxSize in bytes, 0 = no limit; cardUrl)
 */

$dropTitle = $langs->trans(in_array($action, ['presendmessage', 'presenddone'], true) ? 'TmdDropTitleMail' : 'TmdDropTitle', $object->ref);

print '<div class="dolitmd-dropzone" aria-hidden="true"';
print ' data-url="' . dol_escape_htmltag(dol_buildpath('/dolitmd/ajax/tmd.php', 1)) . '"';
print ' data-id="' . ((int) $object->id) . '"';
print ' data-card-url="' . dol_escape_htmltag($dropZone['cardUrl']) . '"';
print ' data-max-size="' . ((int) $dropZone['maxSize']) . '"';
print ' data-label-sending="' . dol_escape_htmltag($langs->trans('TmdDropSending', '%s')) . '"';
print ' data-label-too-big="' . dol_escape_htmltag($langs->trans('TmdDropTooBig', dol_print_size((int) $dropZone['maxSize']))) . '"';
print ' data-label-folder="' . dol_escape_htmltag($langs->trans('TmdDropFolder', '%s')) . '"';
print ' data-label-error="' . dol_escape_htmltag($langs->trans('TmdDropError')) . '">';
print '<div class="dolitmd-dropzone-box">';
print '<i class="fas fa-cloud-upload-alt dolitmd-dropzone-icon"></i>';
print '<div class="dolitmd-dropzone-title">' . dol_escape_htmltag($dropTitle) . '</div>';
// The documents of the tab are on the public page of the request
if ($publicUrl !== '') {
    print '<div class="dolitmd-dropzone-help">' . $langs->trans('TmdPublicDocumentsNotice') . '</div>';
}
print '</div>';
print '</div>';
