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
 * \file    core/triggers/interface_99_modDoliTMD_DoliTMDTriggers.class.php
 * \ingroup dolitmd
 * \brief   DoliTMD trigger: an invoice deleted puts its requests back to done. The mails of the TMD mailbox are handled
 *          by the scheduled job TmdCronCatchUp for now (the part on INBOX_MAIL_RECEIVED is switched off below)
 */

// Load Dolibarr libraries
require_once DOL_DOCUMENT_ROOT . '/core/triggers/dolibarrtriggers.class.php';

/**
 * Class of triggers for DoliTMD module
 */
class InterfaceDoliTMDTriggers extends DolibarrTriggers
{
    /**
     * Constructor
     *
     * @param DoliDB $db Database handler
     */
    public function __construct($db)
    {
        parent::__construct($db);

        $this->name        = preg_replace('/^Interface/i', '', get_class($this));
        $this->family      = 'demo';
        $this->description = 'DoliTMD triggers: an invoice deleted puts its TMD requests back to done.';
        $this->version     = '1.1.0';
        $this->picto       = 'dolitmd_color@dolitmd';
    }

    /**
     * Function called when a Dolibarr business event is done
     *
     * @param  string       $action Event action code
     * @param  CommonObject $object Object
     * @param  User         $user   Object user
     * @param  Translate    $langs  Object langs
     * @param  Conf         $conf   Object conf
     * @return int                  0 < if KO, 0 if no triggered ran, > 0 if OK
     */
    public function runTrigger($action, $object, User $user, Translate $langs, Conf $conf): int
    {
        if (!isModEnabled('dolitmd')) {
            return 0;
        }

        // An invoice deleted (a draft made from the list): its requests are done again, without invoice
        if ($action === 'BILL_DELETE' && !empty($object->id)) {
            require_once __DIR__ . '/../../class/tmdinvoicer.class.php';
            (new TmdInvoicer($this->db))->forgetInvoice($user, (int) $object->id);
            return 0;
        }

        // Mail received on the TMD mailbox: switched off for now. The trigger INBOX_MAIL_RECEIVED is not in the module
        // Inbox yet (local patch only): the scheduled job « DoliTMD: TMD mails without a request »
        // (TmdMailHandler::cronCatchUp) fetches the mailbox itself and makes the tickets and the requests. To switch
        // back on once Inbox sends the trigger (the job may stay, as a catch-up)
        /*
        if ($action !== 'INBOX_MAIL_RECEIVED') {
            return 0;
        }

        // Only the mailbox chosen in the setup of the module
        $mailboxId = getDolGlobalInt('DOLITMD_INBOX_ACCOUNT');
        if ($mailboxId <= 0 || (int) $object->id !== $mailboxId) {
            return 0;
        }

        // Called during the synchronisation of Inbox: whatever happens here, it goes on
        try {
            require_once __DIR__ . '/../../class/tmdmailhandler.class.php';

            $handler = new TmdMailHandler($this->db);
            $created = $handler->handleReceived($object, (array) ($object->context['inbox']['ids'] ?? []), $user);
            dol_syslog('DoliTMD: ' . $created . ' request(s) created from mailbox ' . $mailboxId . ($handler->errors ? ', errors: ' . implode(', ', $handler->errors) : ''), $handler->errors ? LOG_WARNING : LOG_INFO);
        } catch (Throwable $e) {
            dol_syslog('DoliTMD: mails of mailbox ' . $mailboxId . ' not handled: ' . $e->getMessage(), LOG_ERR);
        }
        */

        return 0;
    }
}
