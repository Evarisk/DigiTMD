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
 * Expects: $object, $langs, $user, $conf, $permissiontoadd (an answer may be taken out as a request of its own)
 */

$thread = $object->getRequestThread();
if (empty($thread)) {
    return;
}

require_once DOL_DOCUMENT_ROOT . '/core/lib/images.lib.php';

$canOpenFiles = $user->hasRight('ticket', 'read');
$entityParam  = 'entity=' . ((int) $conf->entity);
$icons       = ['dolitmd_request' => 'fa-envelope', 'ticket_msg' => 'fa-comment', 'ticket_msg_private' => 'fa-lock', 'ticket_msg_sentbymail' => 'fa-paper-plane', 'ticket_msg_private_sentbymail' => 'fa-paper-plane'];

print '<div class="dolitmd-request">';
print load_fiche_titre($langs->trans('TmdRequestContent'), '', 'fa-envelope-open-text');
print '<ul class="timeline dolitmd-request-thread">';
foreach ($thread as $item) {
    print '<li class="timeline-code-' . dol_escape_htmltag($item['code']) . '">';
    print '<i class="fa ' . ($icons[$item['code']] ?? 'fa-comment') . '"></i>';
    print '<div class="timeline-item">';
    print '<span class="time"><i class="fa fa-clock valignmiddle"></i> <span class="valignmiddle">' . dol_print_date($item['date'], 'day', 'tzuserrel') . ' ' . dol_print_date($item['date'], 'hour', 'tzuserrel', null, false, 'opacitymedium') . '</span></span>';
    print '<h3 class="timeline-header">' . dol_escape_htmltag($item['title']);
    // An answer received that is a new request (written as an answer to this one): taken out as a request of its own
    if (!empty($permissiontoadd) && $item['code'] === 'ticket_msg' && (int) $item['id'] > 0) {
        print '<a class="dolitmd-request-split" href="' . dol_escape_htmltag($_SERVER['PHP_SELF'] . '?id=' . $object->id . '&action=splitmessage&messageid=' . ((int) $item['id']) . '&token=' . newToken()) . '" title="' . dol_escape_htmltag($langs->trans('TmdSplitHelp')) . '">';
        print '<i class="fas fa-file-export"></i> ' . $langs->trans('TmdSplit') . '</a>';
    }
    print '</h3>';
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
            // A tile per file: the image itself or the picto of its type, opened in the preview of Dolibarr (images, PDF,
            // text, videos: the window of document_preview, a new tab on a phone), the others downloaded
            $name    = dol_escape_htmltag($file['name']);
            $query   = 'modulepart=ticket&' . $entityParam . '&file=' . urlencode($file['path']);
            $isImage = image_format_supported($file['name']) > 0;
            $thumb   = ($canOpenFiles && $isImage) ? '<img src="' . dol_escape_htmltag(DOL_URL_ROOT . '/viewimage.php?' . $query) . '" alt="" loading="lazy">' : img_mime($file['name'], '', 'dolitmd-file-icon');
            $info    = '<span class="dolitmd-file-info"><span class="dolitmd-file-name">' . $name . '</span><span class="dolitmd-file-size">' . dol_print_size($file['size']) . '</span></span>';

            print '<div class="dolitmd-file' . ($isImage ? ' dolitmd-file-image' : '') . '" title="' . $name . '">';
            if ($canOpenFiles) {
                $download = DOL_URL_ROOT . '/document.php?' . $query . '&attachment=1';
                $preview  = getAdvancedPreviewUrl('ticket', $file['path'], 1, $entityParam);
                if (!empty($preview)) {
                    print '<a class="dolitmd-file-open ' . $preview['css'] . '" href="' . dol_escape_htmltag($preview['url']) . '" mime="' . dol_escape_htmltag($preview['mime']) . '" data-title="' . $name . '" target="_blank" rel="noopener">';
                    print '<span class="dolitmd-file-thumb">' . $thumb . '<span class="dolitmd-file-zoom"><i class="fa fa-search-plus"></i> ' . $langs->trans('Preview') . '</span></span>';
                } else {
                    print '<a class="dolitmd-file-open" href="' . dol_escape_htmltag($download) . '">';
                    print '<span class="dolitmd-file-thumb">' . $thumb . '</span>';
                }
                print $info . '</a>';
                print '<a class="dolitmd-file-download" href="' . dol_escape_htmltag($download) . '" title="' . dol_escape_htmltag($langs->trans('Download')) . '"><i class="fa fa-download"></i></a>';
            } else {
                print '<span class="dolitmd-file-open"><span class="dolitmd-file-thumb">' . $thumb . '</span>' . $info . '</span>';
            }
            print '</div>';
        }
        print '</div>';
    }
    print '</div>';
    print '</li>';
}
print '</ul>';
print '</div>';
