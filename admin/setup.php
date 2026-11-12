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
 * \file    admin/setup.php
 * \ingroup dolitmd
 * \brief   DoliTMD setup page: reception (mailbox, person assigned), delay, invoicing, automatic mails
 */

// Load DoliTMD environment
if (file_exists('../dolitmd.main.inc.php')) {
    require_once __DIR__ . '/../dolitmd.main.inc.php';
} elseif (file_exists('../../dolitmd.main.inc.php')) {
    require_once __DIR__ . '/../../dolitmd.main.inc.php';
} else {
    die('Include of dolitmd main fails');
}

// Load Dolibarr libraries
require_once DOL_DOCUMENT_ROOT . '/core/lib/admin.lib.php';

// Load DoliTMD libraries
require_once __DIR__ . '/../lib/dolitmd.lib.php';
require_once __DIR__ . '/../class/tmdmailer.class.php';

// Global variables definitions
global $conf, $db, $langs, $user;

// Load translation files required by the page
saturne_load_langs(['admin', 'bills']);

// Get parameters
$action     = GETPOST('action', 'aZ09');
$backtopage = GETPOST('backtopage', 'alpha');

// Initialize view objects
$form = new Form($db);

// Security check - Protection if external user
$permissiontoread = $user->hasRight('dolitmd', 'adminpage', 'read');
saturne_check_access($permissiontoread);

$mailboxes = dolitmd_inbox_mailboxes();

/*
 * Actions
 */

if ($action == 'set_config' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $mailboxId = GETPOSTINT('mailbox');
    if ($mailboxId > 0 && !array_key_exists($mailboxId, $mailboxes)) {
        $mailboxId = 0;
    }
    $assignUserId = GETPOSTINT('assign_user');
    if ($assignUserId > 0) {
        $assignUser = new User($db);
        if ($assignUser->fetch($assignUserId) <= 0 || !empty($assignUser->socid) || empty($assignUser->statut)) {
            $assignUserId = 0;
        }
    }
    $delay     = max(0, min(365, GETPOSTINT('delay_days')));
    $productId = GETPOSTINT('invoice_product');

    // A mailbox newly chosen: only the mails received from now on become requests, never its history
    if ($mailboxId > 0 && $mailboxId !== getDolGlobalInt('DOLITMD_INBOX_ACCOUNT')) {
        dolibarr_set_const($db, 'DOLITMD_INBOX_SINCE', (string) dol_now(), 'chaine', 0, '', $conf->entity);
    }
    dolibarr_set_const($db, 'DOLITMD_INBOX_ACCOUNT', $mailboxId > 0 ? (string) $mailboxId : '', 'chaine', 0, '', $conf->entity);
    dolibarr_set_const($db, 'DOLITMD_TICKET_USER', $assignUserId > 0 ? (string) $assignUserId : '', 'chaine', 0, '', $conf->entity);
    dolibarr_set_const($db, 'DOLITMD_DELAY_DAYS', (string) $delay, 'chaine', 0, '', $conf->entity);
    if (GETPOSTISSET('invoice_product')) {
        dolibarr_set_const($db, 'DOLITMD_INVOICE_PRODUCT', $productId > 0 ? (string) $productId : '', 'chaine', 0, '', $conf->entity);
    }

    setEventMessages($langs->trans('TmdSetupSaved'), []);
    header('Location: ' . $_SERVER['PHP_SELF']);
    exit;
}

/*
 * View
 */

$title    = $langs->trans('ModuleSetup', 'DoliTMD');
$help_url = 'FR:Module_DoliTMD';

saturne_header(0, '', $title, $help_url);

// Subheader
$linkback = '<a href="' . dol_escape_htmltag($backtopage ?: DOL_URL_ROOT . '/admin/modules.php?restore_lastsearch_values=1') . '">' . $langs->trans('BackToModuleList') . '</a>';
print load_fiche_titre($title, $linkback, 'title_setup');

// Configuration header
$head = dolitmd_admin_prepare_head();
print dol_get_fiche_head($head, 'settings', $title, -1, 'dolitmd_color@dolitmd');

$mailboxId = getDolGlobalInt('DOLITMD_INBOX_ACCOUNT');
if (!isModEnabled('inbox')) {
    print '<div class="warning">' . $langs->trans('TmdSetupInboxDisabled') . '</div>';
} elseif (empty($mailboxes)) {
    print '<div class="warning">' . $langs->trans('TmdSetupNoInboxMailbox') . '</div>';
} elseif ($mailboxId > 0) {
    $status = $db->getRow('SELECT status FROM ' . $db->prefix() . 'inbox_account WHERE rowid = ' . $mailboxId);
    if (!is_object($status) || (int) $status->status !== 1) {
        print '<div class="warning">' . $langs->trans('TmdSetupMailboxNotActive') . '</div>';
    }
}

print '<form method="POST" action="' . $_SERVER['PHP_SELF'] . '">';
print '<input type="hidden" name="token" value="' . newToken() . '">';
print '<input type="hidden" name="action" value="set_config">';

// Reception
print load_fiche_titre($langs->trans('TmdSetupReception'), '', '');
print '<table class="noborder centpercent">';
print '<tr class="liste_titre"><td>' . $langs->trans('Parameters') . '</td><td>' . $langs->trans('Description') . '</td><td>' . $langs->trans('Value') . '</td></tr>';

print '<tr class="oddeven"><td><label for="mailbox">' . $langs->trans('TmdSetupMailbox') . '</label></td>';
print '<td>' . $langs->trans('TmdSetupMailboxHelp') . '</td>';
print '<td>' . $form->selectarray('mailbox', $mailboxes, $mailboxId, $langs->transnoentities('TmdSetupNoMailbox'), 0, 0, '', 0, 0, isModEnabled('inbox') ? 0 : 1, '', 'minwidth300') . '</td>';
print '</tr>';

print '<tr class="oddeven"><td><label for="assign_user">' . $langs->trans('TmdSetupAssignUser') . '</label></td>';
print '<td>' . $langs->trans('TmdSetupAssignUserHelp') . '</td>';
print '<td>' . img_picto('', 'user', 'class="pictofixedwidth"') . $form->select_dolusers(getDolGlobalInt('DOLITMD_TICKET_USER') ?: -1, 'assign_user', 1, null, 0, '', '', (string) $conf->entity, 0, 0, '', 0, '', 'minwidth300', 1) . '</td>';
print '</tr>';
print '</table>';

// Delay
print load_fiche_titre($langs->trans('TmdSetupDelay'), '', '');
print '<table class="noborder centpercent">';
print '<tr class="liste_titre"><td>' . $langs->trans('Parameters') . '</td><td>' . $langs->trans('Description') . '</td><td>' . $langs->trans('Value') . '</td></tr>';
print '<tr class="oddeven"><td><label for="delay_days">' . $langs->trans('TmdSetupDelayDays') . '</label></td>';
print '<td>' . $langs->trans('TmdSetupDelayDaysHelp') . '</td>';
print '<td><input type="number" min="0" max="365" class="maxwidth75" id="delay_days" name="delay_days" value="' . getDolGlobalInt('DOLITMD_DELAY_DAYS', 15) . '"> ' . $langs->trans('Days') . '</td>';
print '</tr>';
print '</table>';

// Invoicing
print load_fiche_titre($langs->trans('TmdSetupInvoicing'), '', '');
print '<table class="noborder centpercent">';
print '<tr class="liste_titre"><td>' . $langs->trans('Parameters') . '</td><td>' . $langs->trans('Description') . '</td><td>' . $langs->trans('Value') . '</td></tr>';
print '<tr class="oddeven"><td><label for="invoice_product">' . $langs->trans('TmdSetupInvoiceProduct') . '</label></td>';
print '<td>' . $langs->trans('TmdSetupInvoiceProductHelp') . '</td>';
print '<td>';
if (isModEnabled('invoice') && (isModEnabled('service') || isModEnabled('product'))) {
    print img_picto('', 'service', 'class="pictofixedwidth"') . $form->select_produits(getDolGlobalInt('DOLITMD_INVOICE_PRODUCT'), 'invoice_product', isModEnabled('service') ? 1 : '', 0, 0, 1, 2, '', 0, [], 0, '1', 0, 'minwidth300 maxwidth400 widthcentpercentminusx', 0, '', null, 1);
} else {
    print '<span class="opacitymedium">' . $langs->trans('TmdSetupInvoiceModules') . '</span>';
}
print '</td>';
print '</tr>';
print '</table>';

print $form->buttonsSaveCancel('Save', '');

print '</form>';

// Automatic mails: off until switched on, each one written from its template
print load_fiche_titre($langs->trans('TmdSetupMails'), '<a href="' . dol_escape_htmltag(dolBuildUrl(DOL_URL_ROOT . '/admin/mails_templates.php', ['search_type_template' => TmdMailer::TEMPLATE_TYPE])) . '">' . img_picto('', 'email', 'class="pictofixedwidth"') . $langs->trans('TmdSetupMailsTemplates') . '</a>', '');
print '<span class="opacitymedium">' . $langs->trans('TmdSetupMailsHelp') . '</span>';
print '<table class="noborder centpercent margintoponly">';
print '<tr class="liste_titre"><td>' . $langs->trans('Parameters') . '</td><td>' . $langs->trans('Description') . '</td><td class="center">' . $langs->trans('Status') . '</td></tr>';
foreach (['ack' => 'TmdSetupMailAck', 'done' => 'TmdSetupMailDone', 'late' => 'TmdSetupMailLate'] as $mail => $label) {
    print '<tr class="oddeven"><td><span class="nowraponall">' . $langs->trans($label) . '</span></td>';
    print '<td><span class="wordbreak">' . $langs->trans($label . 'Help') . '</span></td>';
    print '<td class="center">' . ajax_constantonoff(TmdMailer::MAILS[$mail]['const']) . '</td>';
    print '</tr>';
}
print '</table>';

print dol_get_fiche_end();

// End of page
llxFooter();
$db->close();
