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
 * \file    core/tpl/dolitmd_tmd_card_request.tpl.php
 * \ingroup dolitmd
 * \brief   The request read on its card: the mail of the customer, then the messages of its ticket, with their files
 *          (the timeline of the messages of a ticket)
 *
 * Rendered by core/tpl/dolitmd_tmd_card_view.tpl.php (inherits its scope).
 * Expects: $object, $langs, $user, $conf
 */

$thread = $object->getRequestThread();
if (empty($thread)) {
    return;
}

$canOpenFiles = $user->hasRight('ticket', 'read');
$icons        = ['dolitmd_request' => 'fa-envelope', 'ticket_msg' => 'fa-comment', 'ticket_msg_private' => 'fa-lock', 'ticket_msg_sentbymail' => 'fa-paper-plane', 'ticket_msg_private_sentbymail' => 'fa-paper-plane'];

print '<div class="dolitmd-request">';
print load_fiche_titre($langs->trans('TmdRequestContent'), '', 'fa-envelope-open-text');
print '<ul class="timeline dolitmd-request-thread">';
foreach ($thread as $item) {
    print '<li class="timeline-code-' . dol_escape_htmltag($item['code']) . '">';
    print '<i class="fa ' . ($icons[$item['code']] ?? 'fa-comment') . '"></i>';
    print '<div class="timeline-item">';
    print '<span class="time"><i class="fa fa-clock valignmiddle"></i> <span class="valignmiddle">' . dol_print_date($item['date'], 'day', 'tzuserrel') . ' ' . dol_print_date($item['date'], 'hour', 'tzuserrel', null, false, 'opacitymedium') . '</span></span>';
    print '<h3 class="timeline-header">' . dol_escape_htmltag($item['title']) . '</h3>';
    print '<div class="timeline-body wordbreak">';
    if (trim(dol_string_nohtmltag($item['message'])) !== '') {
        print dolPrintHTML($item['message'], 0, ['pre', 'code']);
    } else {
        print '<span class="opacitymedium">' . $langs->trans('TmdRequestNoText') . '</span>';
    }
    print '</div>';
    if (!empty($item['files'])) {
        print '<div class="timeline-footer dolitmd-request-files">';
        foreach ($item['files'] as $file) {
            $label = img_mime($file['name'], '', 'pictofixedwidth') . dol_escape_htmltag($file['name']) . ' <span class="opacitymedium">(' . dol_print_size($file['size']) . ')</span>';
            if ($canOpenFiles) {
                $url = DOL_URL_ROOT . '/document.php?modulepart=ticket&attachment=0&entity=' . ((int) $conf->entity) . '&file=' . urlencode($file['path']);
                print '<a class="dolitmd-request-file" href="' . dol_escape_htmltag($url) . '" target="_blank" rel="noopener">' . $label . '</a>';
            } else {
                print '<span class="dolitmd-request-file">' . $label . '</span>';
            }
        }
        print '</div>';
    }
    print '</div>';
    print '</li>';
}
print '</ul>';
print '</div>';
