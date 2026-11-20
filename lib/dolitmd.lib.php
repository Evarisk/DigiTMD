<?php
/* Copyright (C) 2023-2026 EVARISK <technique@evarisk.com>
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
 * \file    lib/dolitmd.lib.php
 * \ingroup dolitmd
 * \brief   Library files with common functions for DoliTMD
 */

/**
 * Prepare admin pages header
 *
 * @return array Array of tabs
 */
function dolitmd_admin_prepare_head(): array
{
    global $conf, $langs;

    saturne_load_langs();

    $h    = 0;
    $head = [];

    $head[$h][0] = dol_buildpath('/dolitmd/admin/setup.php', 1);
    $head[$h][1] = '<i class="fas fa-cog pictofixedwidth"></i>' . $langs->trans('ModuleSettings');
    $head[$h][2] = 'settings';
    $h++;

    $head[$h][0] = dol_buildpath('/saturne/admin/about.php', 1) . '?module_name=DoliTMD';
    $head[$h][1] = '<i class="fab fa-readme pictofixedwidth"></i>' . $langs->trans('About');
    $head[$h][2] = 'about';
    $h++;

    complete_head_from_modules($conf, $langs, null, $head, $h, 'dolitmd@dolitmd');
    complete_head_from_modules($conf, $langs, null, $head, $h, 'dolitmd@dolitmd', 'remove');

    return $head;
}

/**
 * Style and script of the module, for a page that does not load them by itself (the generic list of Saturne).
 * The date of the file goes in the address: a new version is never read from the cache of the browser.
 *
 * @return string Tags for the head of the page
 */
function dolitmd_assets_html(): string
{
    $out = '';
    foreach (['css/dolitmd.min.css', 'js/dolitmd.min.js'] as $file) {
        $path = dol_buildpath('/dolitmd/' . $file, 0);
        if (!file_exists($path)) {
            continue;
        }
        $url = dol_buildpath('/dolitmd/' . $file, 1) . '?v=' . filemtime($path);
        $out .= substr($file, -3) === '.js'
            ? '<script src="' . dol_escape_htmltag($url) . '"></script>'
            : '<link rel="stylesheet" type="text/css" href="' . dol_escape_htmltag($url) . '">';
    }

    return $out;
}

/**
 * Done or invoiced of a TMD request as a picto, coloured when it is so, saved at once by js/modules/tmd.js (a real
 * checkbox lies under the picto: keyboard, screen readers)
 *
 * @param  Tmd    $object   TMD request
 * @param  string $field    done or invoiced
 * @param  bool   $canWrite The user may change it
 * @return string           HTML of the picto
 */
function dolitmd_flag_html(Tmd $object, string $field, bool $canWrite): string
{
    global $langs;

    $ticked    = !empty($object->$field);
    $dateField = 'date_' . $field;
    $title     = $langs->transnoentities($field == 'done' ? ($ticked ? 'TmdDone' : 'TmdNotDone') : ($ticked ? 'TmdInvoiced' : 'TmdNotInvoiced'));
    if ($ticked && !empty($object->$dateField)) {
        $title .= ' : ' . dol_print_date($object->$dateField, 'dayhour', 'tzuserrel');
    }
    if ($canWrite) {
        $title .= ' — ' . $langs->transnoentities($ticked ? 'TmdClickToCancel' : 'TmdClickToSet');
    }
    $icon = $field == 'done' ? 'fa-check-circle' : 'fa-file-invoice-dollar';

    $out  = '<label class="dolitmd-flag dolitmd-flag--' . dol_escape_htmltag($field) . ($ticked ? ' dolitmd-flag--on' : '') . ($canWrite ? '' : ' dolitmd-flag--readonly') . ' classfortooltip" title="' . dol_escape_htmltag($title) . '">';
    $out .= '<input type="checkbox" class="dolitmd-flag-input" data-id="' . ((int) $object->id) . '" data-field="' . dol_escape_htmltag($field) . '"';
    $out .= ' data-url="' . dol_escape_htmltag(dol_buildpath('/dolitmd/ajax/tmd.php', 1)) . '" data-error="' . dol_escape_htmltag($langs->transnoentities('TmdFlagError')) . '"';
    $out .= ($ticked ? ' checked' : '') . ($canWrite ? '' : ' disabled') . ' aria-label="' . dol_escape_htmltag($title) . '">';
    $out .= '<span class="dolitmd-flag-icon" aria-hidden="true"><i class="fas ' . $icon . '"></i></span>';
    $out .= '</label>';

    return $out;
}

/**
 * Done and invoiced side by side: the column « Follow-up » of the list, then the invoice of an invoiced request. Its
 * state too, for js/modules/tmd.js (row of a subcontracted request greyed, column « State » hidden or not)
 *
 * @param  Tmd    $object   TMD request
 * @param  bool   $canWrite The user may change them
 * @return string           HTML of the two pictos (and of the link to the invoice)
 */
function dolitmd_flags_html(Tmd $object, bool $canWrite): string
{
    return '<span class="dolitmd-flags" data-dolitmd-state="' . ((int) $object->status) . '">' . dolitmd_flag_html($object, 'done', $canWrite) . dolitmd_flag_html($object, 'invoiced', $canWrite)
        . dolitmd_invoice_html($object) . '</span>';
}

/**
 * Link to the invoice of an invoiced request (made by « Create the invoices »), for those who may read the invoices
 *
 * @param  Tmd    $object TMD request
 * @return string         HTML of the link, empty when none
 */
function dolitmd_invoice_html(Tmd $object): string
{
    global $db, $user;

    static $invoices = [];

    $invoiceId = (int) $object->fk_facture;
    if (empty($object->invoiced) || $invoiceId <= 0 || !isModEnabled('invoice') || !$user->hasRight('facture', 'lire')) {
        return '';
    }
    if (!array_key_exists($invoiceId, $invoices)) {
        require_once DOL_DOCUMENT_ROOT . '/compta/facture/class/facture.class.php';
        $invoice               = new Facture($db);
        $invoices[$invoiceId]  = $invoice->fetch($invoiceId) > 0 ? $invoice->getNomUrl(1, '', 0, 0, '', 0, 0, 0) : '';
    }

    return $invoices[$invoiceId] !== '' ? '<span class="dolitmd-invoice-link">' . $invoices[$invoiceId] . '</span>' : '';
}

/**
 * Deadline of a TMD request in the list: red « Late » when passed and not done, orange within two days (neither for a
 * subcontracted request, never late)
 *
 * @param  Tmd    $object TMD request
 * @return string         HTML of the deadline
 */
function dolitmd_due_html(Tmd $object): string
{
    global $langs;

    if (empty($object->date_due)) {
        return '';
    }
    $due   = is_numeric($object->date_due) ? (int) $object->date_due : (int) $object->db->jdate($object->date_due);
    $today = dol_get_first_hour(dol_now());
    $date  = dol_escape_htmltag(dol_print_date($due, 'day', 'tzuserrel'));

    if (!empty($object->done)) {
        return '<span class="dolitmd-due dolitmd-due--done">' . $date . '</span>';
    }
    if ((int) $object->status === Tmd::STATUS_SUBCONTRACTED) {
        return '<span class="dolitmd-due">' . $date . '</span>';
    }
    if ($due < $today) {
        $days = (int) floor(($today - $due) / 86400) + 1;
        return '<span class="dolitmd-due dolitmd-due--late classfortooltip" title="' . dol_escape_htmltag($langs->transnoentities('TmdLateSince', $days)) . '">'
            . $date . ' <span class="dolitmd-due-badge"><i class="fas fa-exclamation-triangle"></i> ' . dol_escape_htmltag($langs->transnoentities('TmdLate')) . '</span></span>';
    }
    if ($due < $today + 3 * 86400) {
        return '<span class="dolitmd-due dolitmd-due--soon classfortooltip" title="' . dol_escape_htmltag($langs->transnoentities('TmdDueSoon')) . '">' . $date . '</span>';
    }

    return '<span class="dolitmd-due">' . $date . '</span>';
}

/**
 * Mass actions of the states of the list, invoiced first
 *
 * @return array<string,array{state:int,label:string,stateLabel:string,icon:string}> Code of the mass action => state
 */
function dolitmd_mass_states(): array
{
    return [
        'tmdinvoiced'      => ['state' => Tmd::STATUS_INVOICED, 'label' => 'TmdMassToInvoiced', 'stateLabel' => 'TmdStatusInvoiced', 'icon' => 'fa-file-invoice-dollar'],
        'tmddone'          => ['state' => Tmd::STATUS_DONE, 'label' => 'TmdMassToDone', 'stateLabel' => 'TmdStatusDone', 'icon' => 'fa-check-circle'],
        'tmdsubcontracted' => ['state' => Tmd::STATUS_SUBCONTRACTED, 'label' => 'TmdMassToSubcontracted', 'stateLabel' => 'TmdStatusSubcontracted', 'icon' => 'fa-people-arrows'],
        'tmdpending'       => ['state' => Tmd::STATUS_PENDING, 'label' => 'TmdMassToPending', 'stateLabel' => 'TmdStatusPending', 'icon' => 'fa-hourglass-half'],
    ];
}

/**
 * State of a TMD request in the list: a choice in the colours of its badge, saved at once by js/modules/tmd.js (the
 * badge alone for a user who may not modify the requests)
 *
 * @param  Tmd    $object   TMD request
 * @param  bool   $canWrite The user may change the state
 * @return string           HTML of the state
 */
function dolitmd_state_html(Tmd $object, bool $canWrite): string
{
    global $langs;

    $state = (int) $object->status;
    // The state, read by js/modules/tmd.js: row of a subcontracted request greyed
    if (!$canWrite) {
        return '<span class="dolitmd-state-badge" data-dolitmd-state="' . $state . '">' . $object->getLibStatut(5) . '</span>';
    }

    $out  = '<select class="dolitmd-state dolitmd-state--' . $state . '" data-id="' . ((int) $object->id) . '" data-dolitmd-state="' . $state . '"';
    $out .= ' data-url="' . dol_escape_htmltag(dol_buildpath('/dolitmd/ajax/tmd.php', 1)) . '" data-error="' . dol_escape_htmltag($langs->transnoentities('TmdFlagError')) . '"';
    $out .= ' aria-label="' . dol_escape_htmltag($langs->transnoentities('TmdState')) . '" title="' . dol_escape_htmltag($langs->transnoentities('TmdStateChangeHelp')) . '">';
    foreach (Tmd::STATE_LABELS as $key => $label) {
        $out .= '<option value="' . $key . '"' . ($key === $state ? ' selected' : '') . '>' . dol_escape_htmltag($langs->transnoentities($label)) . '</option>';
    }
    $out .= '</select>';

    return $out;
}

/**
 * The user may create the thirdparty of an unknown sender from the list or the card of the requests
 *
 * @param  User $user User
 * @return bool
 */
function dolitmd_can_create_thirdparty(User $user): bool
{
    return isModEnabled('societe') && empty($user->socid) && $user->hasRight('societe', 'creer') && $user->hasRight('dolitmd', 'tmd', 'write');
}

/**
 * Sender of a request without thirdparty: its address, then the button that creates its thirdparty (modal of
 * core/tpl/dolitmd_thirdparty_create_modal.tpl.php, js/modules/tmd.js)
 *
 * @param  Tmd    $object    TMD request
 * @param  bool   $canCreate The user may create the thirdparty
 * @return string            HTML of the sender
 */
function dolitmd_sender_html(Tmd $object, bool $canCreate): string
{
    global $langs;

    $email = (string) $object->email_from;

    $out  = '<span class="dolitmd-sender" data-id="' . ((int) $object->id) . '">';
    $out .= '<span class="dolitmd-sender-email opacitymedium classfortooltip" title="' . dol_escape_htmltag($langs->transnoentities('TmdUnknownSender')) . '">';
    $out .= img_picto('', 'email', 'class="pictofixedwidth"') . dol_escape_htmltag($email) . '</span>';
    if ($canCreate && (int) $object->status !== Tmd::STATUS_DELETED) {
        $out .= '<button type="button" class="dolitmd-sender-create classfortooltip" title="' . dol_escape_htmltag($langs->transnoentities('TmdCreateThirdpartyHelp')) . '"';
        $out .= ' data-id="' . ((int) $object->id) . '" data-email="' . dol_escape_htmltag($email) . '" data-name="' . dol_escape_htmltag(dolitmd_sender_company_name($object)) . '"';
        $out .= ' aria-label="' . dol_escape_htmltag($langs->transnoentities('TmdCreateThirdparty')) . '"><i class="fas fa-plus"></i></button>';
    }
    $out .= '</span>';

    return $out;
}

/**
 * Name proposed for the thirdparty of a sender: the domain of a company address (kleterrier@staci.com → STACI), the
 * name the sender gave for an address of a mail provider
 *
 * @param  Tmd    $object TMD request
 * @return string         Name, empty when none
 */
function dolitmd_sender_company_name(Tmd $object): string
{
    global $db;

    $label = dolitmd_company_domain_label((string) $object->email_from);
    if ($label !== '') {
        return dol_strtoupper(str_replace('-', ' ', $label));
    }

    // A mail provider: the name of the sender, kept by Inbox with the mail
    if (!isModEnabled('inbox') || empty($object->message_id)) {
        return '';
    }
    $row = $db->getRow('SELECT from_name FROM ' . $db->prefix() . "inbox_mail WHERE message_id = '" . $db->escape((string) $object->message_id) . "' ORDER BY rowid ASC");

    return is_object($row) ? trim((string) $row->from_name) : '';
}

/**
 * Name of the company in the domain of an address, without its top level domains (staci.com → staci), empty for the
 * address of a mail provider (gmail, orange...), which never tells the company of a person
 *
 * @param  string $email Address
 * @return string        Lower case, empty when none
 */
function dolitmd_company_domain_label(string $email): string
{
    $domain = substr((string) strrchr(strtolower(trim($email)), '@'), 1);
    $labels = array_values(array_filter(explode('.', $domain), 'strlen'));
    // Top level domain, then second level ones (co.uk, gouv.fr...)
    array_pop($labels);
    if (count($labels) > 1 && in_array(end($labels), ['co', 'com', 'gouv', 'asso', 'org', 'net', 'ac'], true)) {
        array_pop($labels);
    }
    $label     = (string) end($labels);
    $providers = ['gmail', 'googlemail', 'yahoo', 'ymail', 'hotmail', 'outlook', 'live', 'msn', 'icloud', 'me', 'mac', 'aol', 'gmx', 'proton', 'protonmail', 'orange', 'wanadoo', 'free', 'sfr', 'neuf', 'laposte', 'bbox', 'numericable', 'club-internet', 'aliceadsl', 'yandex', 'mail', 'zoho'];

    return in_array($label, $providers, true) ? '' : $label;
}

/**
 * Mailboxes of the module Inbox, to choose the one of the TMD requests
 *
 * @return array<int,string> Id of the mailbox => address (and name)
 */
function dolitmd_inbox_mailboxes(): array
{
    global $conf, $db;

    $out = [];
    if (!isModEnabled('inbox')) {
        return $out;
    }
    $sql   = 'SELECT rowid, email, label, status FROM ' . $db->prefix() . 'inbox_account WHERE entity = ' . ((int) $conf->entity) . ' ORDER BY email';
    $resql = $db->query($sql);
    while ($resql && ($obj = $db->fetch_object($resql))) {
        $label = (string) $obj->email;
        if (!empty($obj->label) && $obj->label !== $obj->email) {
            $label .= ' (' . $obj->label . ')';
        }
        $out[(int) $obj->rowid] = $label;
    }

    return $out;
}

/**
 * A document of the tab Documents on one line of the card: the picto of its type and its name, opened in the preview of
 * Dolibarr (images, PDF, text, videos: the window of document_preview, a new tab on a phone) or downloaded for the
 * other types, then its size and the buttons preview (types the preview shows), download and delete (confirmed first,
 * action deletefile of the card)
 *
 * @param  Tmd                         $object    TMD request
 * @param  array{name:string,size:int} $file      Document (Tmd::getPublicFiles())
 * @param  bool                        $canDelete The user may delete it
 * @return string                                 HTML of the line
 */
function dolitmd_document_line_html(Tmd $object, array $file, bool $canDelete): string
{
    global $conf, $langs;

    $name        = dol_escape_htmltag($file['name']);
    $path        = $object->element . '/' . dol_sanitizeFileName($object->ref) . '/' . $file['name'];
    $entityParam = 'entity=' . ((int) $conf->entity);
    $download    = DOL_URL_ROOT . '/document.php?modulepart=dolitmd&' . $entityParam . '&file=' . urlencode($path) . '&attachment=1';
    $preview     = getAdvancedPreviewUrl('dolitmd', $path, 1, $entityParam);

    $out = '<div class="dolitmd-document" title="' . $name . '">';
    if (!empty($preview)) {
        $out .= '<a class="dolitmd-document-open ' . $preview['css'] . '" href="' . dol_escape_htmltag($preview['url']) . '" mime="' . dol_escape_htmltag($preview['mime']) . '" data-title="' . $name . '" target="_blank" rel="noopener">';
    } else {
        $out .= '<a class="dolitmd-document-open" href="' . dol_escape_htmltag($download) . '">';
    }
    $out .= img_mime($file['name'], '', 'pictofixedwidth') . '<span class="dolitmd-document-name">' . $name . '</span></a>';
    $out .= '<span class="dolitmd-document-size">' . dol_print_size($file['size']) . '</span>';
    if (!empty($preview)) {
        $out .= '<a class="dolitmd-document-action ' . $preview['css'] . '" href="' . dol_escape_htmltag($preview['url']) . '" mime="' . dol_escape_htmltag($preview['mime']) . '" data-title="' . $name . '" target="_blank" rel="noopener" title="' . dol_escape_htmltag($langs->trans('Preview')) . '"><i class="fa fa-search-plus"></i></a>';
    }
    $out .= '<a class="dolitmd-document-action" href="' . dol_escape_htmltag($download) . '" title="' . dol_escape_htmltag($langs->trans('Download')) . '"><i class="fa fa-download"></i></a>';
    if ($canDelete) {
        $delete = dol_buildpath('/dolitmd/view/tmd/tmd_card.php', 1) . '?id=' . ((int) $object->id) . '&action=deletefile&urlfile=' . urlencode($file['name']) . '&token=' . newToken();
        $out   .= '<a class="dolitmd-document-action dolitmd-document-delete" href="' . dol_escape_htmltag($delete) . '" title="' . dol_escape_htmltag($langs->trans('TmdDeleteDocument')) . '"><i class="fa fa-trash"></i></a>';
    }
    $out .= '</div>';

    return $out;
}

/**
 * A document of the request in the mail « request done »: ticked to go as an attachment (form of the card, and the
 * files dropped on the card while the form is open)
 *
 * @param  array{name:string,size:int} $file    Document of the tab Documents (Tmd::getPublicFiles())
 * @param  bool                        $checked Attached
 * @return string                               HTML of the line
 */
function dolitmd_done_file_html(array $file, bool $checked): string
{
    $fileId = 'dolitmd-file-' . dol_hash($file['name'], 'md5');

    $out  = '<div><input type="checkbox" id="' . $fileId . '" name="files[]" value="' . dol_escape_htmltag($file['name']) . '"' . ($checked ? ' checked' : '') . '>';
    $out .= ' <label for="' . $fileId . '">' . img_mime($file['name'], '', 'pictofixedwidth') . dol_escape_htmltag($file['name']) . ' <span class="opacitymedium">(' . dol_print_size($file['size']) . ')</span></label></div>';

    return $out;
}
