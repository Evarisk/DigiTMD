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
 * \file    class/tmdmailhandler.class.php
 * \ingroup dolitmd
 * \brief   A mail received on the TMD mailbox (module Inbox) becomes a ticket and a TMD request
 */

// Load Dolibarr libraries
require_once DOL_DOCUMENT_ROOT . '/societe/class/societe.class.php';
require_once DOL_DOCUMENT_ROOT . '/ticket/class/ticket.class.php';
require_once DOL_DOCUMENT_ROOT . '/core/lib/files.lib.php';

// Load DoliTMD libraries
require_once __DIR__ . '/tmd.class.php';

/**
 * Class TmdMailHandler
 *
 * Two ways in, the same work: the trigger INBOX_MAIL_RECEIVED of Inbox (at once) and the scheduled job cronCatchUp()
 * (every 5 minutes, for an Inbox without this trigger or a mail missed). One request per mail: the Message-ID is
 * unique among the requests.
 */
class TmdMailHandler
{
    /**
     * @var DoliDB Database handler
     */
    public $db;

    /**
     * @var string Error
     */
    public $error = '';

    /**
     * @var string[] Errors
     */
    public $errors = [];

    /**
     * @var string Output of the scheduled job
     */
    public $output = '';

    /**
     * Constructor
     *
     * @param DoliDB $db Database handler
     */
    public function __construct(DoliDB $db)
    {
        $this->db = $db;
    }

    /**
     * Mailbox chosen in the setup of the module, when it is still a mailbox of Inbox
     *
     * @return object|null Row of llx_inbox_account (rowid, email, status)
     */
    public static function configuredMailbox(DoliDB $db): ?object
    {
        global $conf;

        $mailboxId = getDolGlobalInt('DOLITMD_INBOX_ACCOUNT');
        if ($mailboxId <= 0 || !isModEnabled('inbox')) {
            return null;
        }
        $row = $db->getRow('SELECT rowid, email, status FROM ' . $db->prefix() . 'inbox_account WHERE rowid = ' . $mailboxId . ' AND entity = ' . ((int) $conf->entity));

        return is_object($row) ? $row : null;
    }

    /**
     * People the tickets are assigned to (setup, comma separated), in their order, active internal users only
     *
     * @param  DoliDB $db Database handler
     * @return int[]      Ids of the users, the first one first
     */
    public static function assignUserIds(DoliDB $db): array
    {
        $out = [];
        foreach (array_unique(array_map('intval', explode(',', getDolGlobalString('DOLITMD_TICKET_USER')))) as $userId) {
            if ($userId <= 0) {
                continue;
            }
            $assignUser = new User($db);
            if ($assignUser->fetch($userId) > 0 && !empty($assignUser->statut) && empty($assignUser->socid)) {
                $out[] = $userId;
            }
        }

        return $out;
    }

    /**
     * Hosts closing the Message-ID of the mails sent by this Dolibarr (CMailFile: <uniqid>.<mode>-dolibarr-<trackid>@<host>,
     * the host being dol_getprefix('email')), in each way Dolibarr may have computed it: prefix of the setup, unique id
     * of the instance or its paths, sent from a page or from a scheduled job
     *
     * @return string[] Hosts, in lower case
     */
    public static function ownMailHosts(): array
    {
        global $conf, $dolibarr_main_url_root;

        $hosts  = [dol_getprefix('email')];
        $prefix = getDolGlobalString('MAIL_PREFIX_FOR_EMAIL_ID');
        if ($prefix === 'SERVER_NAME') {
            $hosts[] = (string) parse_url((string) $dolibarr_main_url_root, PHP_URL_HOST);
        } elseif ($prefix !== '') {
            $hosts[] = $prefix;
        }
        if (!empty($conf->file->instance_unique_id)) {
            $hosts[] = sha1('dolibarr' . $conf->file->instance_unique_id);
        }
        $hosts[] = sha1(DOL_DOCUMENT_ROOT . DOL_URL_ROOT);

        return array_values(array_unique(array_filter(array_map('strtolower', $hosts))));
    }

    /**
     * Mail sent by this very Dolibarr: notification of a new ticket, acknowledgement of a request, answer written from a
     * ticket. Never a request: received again on the mailbox, the notification of the ticket of a request would make
     * another request, whose own notification would make another one (issue #29)
     *
     * @param  string $messageId Message-ID
     * @return bool
     */
    public static function isOwnMail(string $messageId): bool
    {
        $messageId = strtolower(trim($messageId, " \t\r\n<>"));
        if (strpos($messageId, '-dolibarr-') === false) {
            return false;
        }
        foreach (self::ownMailHosts() as $host) {
            if (substr($messageId, -strlen('@' . $host)) === '@' . $host) {
                return true;
            }
        }

        return false;
    }

    /**
     * Mails of a mailbox received: a ticket and a request for each one (trigger INBOX_MAIL_RECEIVED)
     *
     * @param  object $mailbox Mailbox (InboxAccount or its row: id or rowid, email)
     * @param  int[]  $mailIds Rows of llx_inbox_mail
     * @param  User   $user    User running the synchronisation
     * @return int             Requests created
     */
    public function handleReceived(object $mailbox, array $mailIds, User $user): int
    {
        $created = 0;
        foreach ($mailIds as $mailId) {
            // A mail that fails never stops the others nor the synchronisation of Inbox
            try {
                if ($this->handleMail($mailbox, (int) $mailId, $user) > 0) {
                    $created++;
                }
            } catch (Throwable $e) {
                $this->errors[] = $e->getMessage();
                dol_syslog(__METHOD__ . ' mail ' . ((int) $mailId) . ' : ' . $e->getMessage(), LOG_ERR);
            }
        }

        return $created;
    }

    /**
     * One mail received: its ticket (assigned, linked to the thirdparty and the contact of the sender, the mail and its
     * attachments with it) and its TMD request
     *
     * @param  object $mailbox Mailbox (InboxAccount or its row: id or rowid, email)
     * @param  int    $mailId  Row of llx_inbox_mail
     * @param  User   $user    User running the synchronisation
     * @return int             Id of the request, 0 when nothing to do, < 0 on error
     */
    public function handleMail(object $mailbox, int $mailId, User $user): int
    {
        global $conf, $langs;

        $langs->load('dolitmd@dolitmd');
        $p         = $this->db->prefix();
        $mailboxId = (int) ($mailbox->id ?? $mailbox->rowid ?? 0);
        $row       = $this->db->getRow('SELECT rowid, fk_account, message_id, in_reply_to, refs, date_mail, date_received, from_email, from_name, subject, snippet, requester_email FROM ' . $p . 'inbox_mail WHERE rowid = ' . $mailId . ' AND fk_account = ' . $mailboxId);
        if (!is_object($row)) {
            return 0;
        }

        $messageId = (string) $row->message_id;
        $tmd       = new Tmd($this->db);
        if ($tmd->fetchIdByMessageId($messageId) > 0 || $this->replyKnown($messageId)) {
            return 0;
        }

        // Never a request: a mail of the mailbox itself, a report of a mail server, a mail sent by this Dolibarr
        $fromEmail = strtolower(trim((string) $row->from_email));
        if ($fromEmail === strtolower(trim((string) ($mailbox->email ?? ''))) || preg_match('/^(mailer-daemon|postmaster)@/i', $fromEmail) || self::isOwnMail($messageId)) {
            return 0;
        }

        $author = $this->author($user);
        if ($author === null) {
            $this->error = 'NoAuthor';
            dol_syslog(__METHOD__ . ' no user to create the ticket of mail ' . $mailId, LOG_ERR);
            return -1;
        }

        // The requester of a site (data source of Inbox) is the real sender
        $sender               = strtolower(trim((string) ($row->requester_email ?: $row->from_email)));
        $message              = $this->readMessage((string) ($mailbox->email ?? ''), $messageId, $author);
        $dateMail             = $row->date_received ? $this->db->jdate($row->date_received) : ($row->date_mail ? $this->db->jdate($row->date_mail) : dol_now());

        // An answer about a request: a message of its ticket, never a new request
        $parentId = $this->findParentRequest($row);
        if ($parentId > 0) {
            $this->appendReply($parentId, $row, $message, $sender, $dateMail, $author);
            return 0;
        }

        [$socId, $contactId]  = $this->findSender($sender);
        $subject              = trim((string) $row->subject) !== '' ? trim((string) $row->subject) : $langs->transnoentities('TmdMailNoSubject');

        $this->db->begin();

        $ticket = $this->createTicket($row, $message, $subject, $sender, $socId, $contactId, $dateMail, $author);
        if ($ticket === null) {
            $this->db->rollback();
            return -1;
        }

        $tmd->label        = dol_trunc($subject, 255, 'right', 'UTF-8', 1);
        $tmd->fk_soc       = $socId > 0 ? $socId : null;
        $tmd->fk_socpeople = $contactId > 0 ? $contactId : null;
        $tmd->fk_ticket    = $ticket->id;
        $tmd->email_from   = $sender;
        $tmd->message_id   = $messageId !== '' ? $messageId : null;
        $tmd->date_mail    = $dateMail;
        $tmd->entity       = $conf->entity;
        if ($tmd->create($author) <= 0) {
            $this->error  = $tmd->error;
            $this->errors = array_merge($this->errors, $tmd->errors);
            dol_syslog(__METHOD__ . ' request of mail ' . $mailId . ' not created: ' . $tmd->error . ' ' . implode(', ', $tmd->errors), LOG_ERR);
            $this->db->rollback();
            return -1;
        }

        $this->db->commit();

        // Out of the transaction: a failure here keeps the ticket and the request
        $this->saveAttachments($ticket, $message);
        $this->linkMail($row, $ticket);
        require_once __DIR__ . '/tmdmailer.class.php';
        (new TmdMailer($this->db))->sendAck($tmd, $author);

        return (int) $tmd->id;
    }

    /**
     * Mail already added as an answer to a request
     *
     * @param  string $messageId Message-ID
     * @return bool
     */
    protected function replyKnown(string $messageId): bool
    {
        if ($messageId === '') {
            return false;
        }

        return is_object($this->db->getRow('SELECT rowid FROM ' . $this->db->prefix() . "dolitmd_tmd_reply WHERE message_id = '" . $this->db->escape($messageId) . "' AND entity IN (" . getEntity('tmd') . ')'));
    }

    /**
     * Request an answer is about: the Message-ID of a request in its references, the track id of Dolibarr of a ticket
     * of a request (a mail written from the ticket), else the reference of a ticket or of a request in its subject
     *
     * @param  object $row Row of llx_inbox_mail (message_id, in_reply_to, refs, subject)
     * @return int         Id of the request, 0 when the mail is a new request
     */
    public function findParentRequest(object $row): int
    {
        $p      = $this->db->prefix();
        $entity = getEntity('tmd');

        $ids = [];
        foreach ([(string) $row->in_reply_to, (string) $row->refs] as $value) {
            if (preg_match_all('/<([^<>\s]+)>/', $value, $matches)) {
                $ids = array_merge($ids, $matches[1]);
            } elseif (strpos($value, '@') !== false) {
                $ids = array_merge($ids, preg_split('/\s+/', trim($value)));
            }
        }
        $ids = array_values(array_diff(array_unique(array_filter(array_map('trim', $ids))), [(string) $row->message_id]));

        if ($ids) {
            $in  = implode(',', array_map(function ($id) {
                return "'" . $this->db->escape($id) . "'";
            }, array_slice($ids, 0, 50)));
            $obj = $this->db->getRow('SELECT rowid FROM ' . $p . 'dolitmd_tmd WHERE entity IN (' . $entity . ') AND status >= 0 AND message_id IN (' . $in . ') ORDER BY rowid DESC');
            if (is_object($obj)) {
                return (int) $obj->rowid;
            }
            foreach ($ids as $id) {
                if (preg_match('/-tic(\d+)@/', $id, $match)) {
                    $obj = $this->db->getRow('SELECT rowid FROM ' . $p . 'dolitmd_tmd WHERE entity IN (' . $entity . ') AND status >= 0 AND fk_ticket = ' . ((int) $match[1]) . ' ORDER BY rowid DESC');
                    if (is_object($obj)) {
                        return (int) $obj->rowid;
                    }
                }
            }
        }

        $subject = (string) $row->subject;
        if (preg_match('/\b(TMD\d{4}-\d{4,})\b/', $subject, $match)) {
            $obj = $this->db->getRow('SELECT rowid FROM ' . $p . "dolitmd_tmd WHERE entity IN (" . $entity . ") AND status >= 0 AND ref = '" . $this->db->escape($match[1]) . "'");
            if (is_object($obj)) {
                return (int) $obj->rowid;
            }
        }
        if (preg_match_all('/\[([A-Za-z0-9_\-\/]{3,30})\]/', $subject, $matches)) {
            $in  = implode(',', array_map(function ($ref) {
                return "'" . $this->db->escape($ref) . "'";
            }, array_slice(array_unique($matches[1]), 0, 10)));
            $obj = $this->db->getRow('SELECT t.rowid FROM ' . $p . 'dolitmd_tmd AS t INNER JOIN ' . $p . 'ticket AS k ON k.rowid = t.fk_ticket WHERE t.entity IN (' . $entity . ') AND t.status >= 0 AND k.ref IN (' . $in . ') ORDER BY t.rowid DESC');
            if (is_object($obj)) {
                return (int) $obj->rowid;
            }
        }

        return 0;
    }

    /**
     * An answer added to the ticket of its request: message of the ticket, attachments, mail linked in Inbox, and the
     * request counts it
     *
     * @param  int               $parentId Request
     * @param  object            $row      Row of llx_inbox_mail
     * @param  InboxMessage|null $message  Mail parsed
     * @param  string            $sender   Address of the sender
     * @param  int               $dateMail Date of the mail
     * @param  User              $author   User
     * @return bool
     */
    protected function appendReply(int $parentId, object $row, $message, string $sender, int $dateMail, User $author): bool
    {
        global $langs;

        global $conf;

        $parent = new Tmd($this->db);
        if ($parent->fetch($parentId) <= 0) {
            return false;
        }

        // Once per mail: the unique key refuses a second row (synchronisation and scheduled job at the same time)
        $p   = $this->db->prefix();
        $sql = 'INSERT INTO ' . $p . 'dolitmd_tmd_reply (entity, fk_tmd, message_id, email_from, date_mail, date_creation) VALUES (' . ((int) $conf->entity) . ', ' . ((int) $parent->id) . ', ';
        $sql .= "'" . $this->db->escape((string) $row->message_id) . "', '" . $this->db->escape($sender) . "', '" . $this->db->idate($dateMail) . "', '" . $this->db->idate(dol_now()) . "')";
        if (empty($row->message_id) || !$this->db->query($sql)) {
            return false;
        }
        $this->db->query('UPDATE ' . $p . 'dolitmd_tmd SET nb_replies = nb_replies + 1, date_last_reply = ' . "'" . $this->db->idate($dateMail) . "'" . ' WHERE rowid = ' . ((int) $parent->id));

        $ticket = new Ticket($this->db);
        if (empty($parent->fk_ticket) || $ticket->fetch((int) $parent->fk_ticket) <= 0) {
            return true;
        }
        $ticket->message = $this->bodyText($message, (string) $row->snippet);
        $ticket->private = 0;
        if ($ticket->createTicketMessage($author, 0, [], [], [], false, 0, $langs->transnoentities('TmdReplyFrom', $sender)) < 0) {
            dol_syslog(__METHOD__ . ' answer not added to ticket ' . $ticket->id . ': ' . $ticket->error, LOG_WARNING);
        }
        $this->saveAttachments($ticket, $message);
        $this->linkMail($row, $ticket);

        return true;
    }

    /**
     * Scheduled job: fetches the mails of the TMD mailbox (Inbox), then makes the ticket and the request of each mail
     * received since the mailbox was chosen that has none yet. Enough by itself: neither the scheduled job of Inbox
     * nor its trigger INBOX_MAIL_RECEIVED are needed
     *
     * @return int 0 if OK, < 0 if KO
     */
    public function cronCatchUp(): int
    {
        global $conf, $user;

        $this->output = '';
        $mailbox      = self::configuredMailbox($this->db);
        if ($mailbox === null) {
            $this->output = 'No TMD mailbox';
            return 0;
        }
        $fetched = $this->syncMailbox((int) $mailbox->rowid);
        $since   = getDolGlobalInt('DOLITMD_INBOX_SINCE');
        $p     = $this->db->prefix();
        $sql   = 'SELECT m.rowid FROM ' . $p . 'inbox_mail AS m';
        $sql  .= ' INNER JOIN ' . $p . 'inbox_folder AS f ON f.rowid = m.fk_folder';
        $sql  .= ' LEFT JOIN ' . $p . 'dolitmd_tmd AS t ON t.message_id = m.message_id AND t.entity = ' . ((int) $conf->entity);
        $sql  .= ' LEFT JOIN ' . $p . 'dolitmd_tmd_reply AS r ON r.message_id = m.message_id AND r.entity = ' . ((int) $conf->entity);
        $sql  .= ' WHERE m.fk_account = ' . ((int) $mailbox->rowid) . " AND f.role = 'inbox' AND t.rowid IS NULL AND r.rowid IS NULL AND m.message_id IS NOT NULL";
        // Never kept as requests (handleMail()): left out here, so that they never take the place of the others
        $sql  .= " AND LOWER(COALESCE(m.from_email, '')) <> '" . $this->db->escape(strtolower((string) $mailbox->email)) . "'";
        $sql  .= " AND LOWER(COALESCE(m.from_email, '')) NOT LIKE 'mailer-daemon@%' AND LOWER(COALESCE(m.from_email, '')) NOT LIKE 'postmaster@%'";
        foreach (self::ownMailHosts() as $host) {
            $host  = $this->db->escape($this->db->escapeforlike($host));
            $sql  .= " AND LOWER(m.message_id) NOT LIKE '%-dolibarr-%@" . $host . "' AND LOWER(m.message_id) NOT LIKE '%-dolibarr-%@" . $host . ">'";
        }
        // Only the mails received since the mailbox was chosen: never the history of the mailbox
        $sql  .= " AND m.date_received >= '" . $this->db->idate($since > 0 ? $since : dol_now()) . "'";
        $sql  .= ' ORDER BY m.date_received ASC LIMIT 50';
        $rows  = $this->db->getRows($sql);
        if (!is_array($rows) || empty($rows)) {
            $this->output = $fetched . ', 0 request';
            return 0;
        }

        $mailbox->id = (int) $mailbox->rowid;
        $created     = $this->handleReceived($mailbox, array_map(static function ($r) {
            return (int) $r->rowid;
        }, $rows), $user);
        $this->output = $fetched . ', ' . $created . ' request(s) created' . ($this->errors ? ', errors: ' . implode(', ', $this->errors) : '');

        return $this->errors ? -1 : 0;
    }

    /**
     * Fetch the new mails of the TMD mailbox now: its inbox and sent folders, as the first phase of the scheduled job
     * of Inbox. A failure does not stop the job: the mails already fetched are handled anyway
     *
     * @param  int    $mailboxId Mailbox of Inbox
     * @return string            What was done, for the output of the job
     */
    protected function syncMailbox(int $mailboxId): string
    {
        if (!dol_include_once('/inbox/class/inboxaccount.class.php') || !dol_include_once('/inbox/class/inboxsync.class.php') || !class_exists('InboxSync')) {
            return 'mailbox not fetched (Inbox missing)';
        }
        try {
            $account = new InboxAccount($this->db);
            if ($account->fetch($mailboxId) <= 0) {
                return 'mailbox not fetched (not found)';
            }
            $sync   = new InboxSync($this->db);
            $result = $sync->run($account, microtime(true) + 30, ['phase' => 1]);
            if ($result < 0) {
                dol_syslog(__METHOD__ . ' mailbox ' . $mailboxId . ' not fetched: ' . $sync->error, LOG_WARNING);
                return 'mailbox not fetched: ' . $sync->error;
            }

            return $result > 0 ? ((int) ($sync->stats['new'] ?? 0)) . ' new mail(s) fetched' : 'mailbox not fetched now (busy or waiting after an error)';
        } catch (Throwable $e) {
            dol_syslog(__METHOD__ . ' mailbox ' . $mailboxId . ' not fetched: ' . $e->getMessage(), LOG_ERR);
            return 'mailbox not fetched: ' . $e->getMessage();
        }
    }

    /**
     * Thirdparty and contact of the sender: a thirdparty with this address, else the thirdparty of a contact with it
     *
     * @param  string $email Address of the sender
     * @return int[]         [thirdparty id, contact id], 0 when not found
     */
    public function findSender(string $email): array
    {
        if ($email === '' || !isValidEmail($email)) {
            return [0, 0];
        }
        $p     = $this->db->prefix();
        $email = $this->db->escape($email);

        $socId = 0;
        if (isModEnabled('societe')) {
            $soc   = $this->db->getRow('SELECT rowid FROM ' . $p . "societe WHERE entity IN (" . getEntity('societe') . ") AND LOWER(email) = '" . $email . "' ORDER BY status DESC, rowid ASC");
            $socId = is_object($soc) ? (int) $soc->rowid : 0;
        }

        // A contact of the thirdparty found first, an active one, the oldest one
        $contact   = $this->db->getRow('SELECT rowid, fk_soc FROM ' . $p . "socpeople WHERE entity IN (" . getEntity('contact') . ") AND LOWER(email) = '" . $email . "' ORDER BY " . ($socId > 0 ? '(fk_soc = ' . $socId . ') DESC, ' : '') . 'statut DESC, rowid ASC');
        $contactId = is_object($contact) ? (int) $contact->rowid : 0;
        if ($socId <= 0 && is_object($contact) && (int) $contact->fk_soc > 0) {
            $socId = (int) $contact->fk_soc;
        }

        return [$socId, $contactId];
    }

    /**
     * User creating the ticket: the one running the synchronisation, else the person the tickets are assigned to
     *
     * @param  User      $user User running the synchronisation
     * @return User|null
     */
    protected function author(User $user): ?User
    {
        if ($user->id > 0) {
            return $user;
        }
        $assignees = self::assignUserIds($this->db);
        if (!empty($assignees)) {
            $author = new User($this->db);
            if ($author->fetch($assignees[0]) > 0) {
                return $author;
            }
        }

        return null;
    }

    /**
     * Mail parsed, read by Inbox in its cache or on the mail server
     *
     * @param  string            $mailboxEmail Address of the mailbox
     * @param  string            $messageId    Message-ID
     * @param  User              $user         User asking
     * @return InboxMessage|null
     */
    protected function readMessage(string $mailboxEmail, string $messageId, User $user)
    {
        if ($mailboxEmail === '' || $messageId === '' || !dol_include_once('/inbox/class/inbox.class.php') || !class_exists('Inbox')) {
            return null;
        }
        $inbox   = new Inbox($this->db);
        $message = $inbox->getMessage($mailboxEmail, $messageId, $user, 'dolitmd', true);
        if ($message === null) {
            dol_syslog(__METHOD__ . ' mail ' . $messageId . ' not read: ' . $inbox->error, LOG_WARNING);
        }

        return $message;
    }

    /**
     * Ticket of the mail, assigned to the person of the setup, the contact of the sender added
     *
     * @param  object                $row       Row of llx_inbox_mail
     * @param  InboxMessage|null     $message   Mail parsed
     * @param  string                $subject   Subject
     * @param  string                $sender    Address of the sender
     * @param  int                   $socId     Thirdparty
     * @param  int                   $contactId Contact
     * @param  int                   $dateMail  Date of the mail
     * @param  User                  $author    User creating
     * @return Ticket|null
     */
    protected function createTicket(object $row, $message, string $subject, string $sender, int $socId, int $contactId, int $dateMail, User $author): ?Ticket
    {
        global $conf, $langs;

        $ticket = new Ticket($this->db);

        $thirdparty = null;
        if ($socId > 0) {
            $thirdparty = new Societe($this->db);
            if ($thirdparty->fetch($socId) <= 0) {
                $thirdparty = null;
            }
        }

        $ticket->ref            = $ticket->getDefaultRef($thirdparty);
        $ticket->subject        = dol_trunc($subject, 255, 'right', 'UTF-8', 1);
        $ticket->message        = $this->bodyText($message, (string) $row->snippet);
        $ticket->type_code      = getDolGlobalString('MAIN_EMAILCOLLECTOR_TICKET_TYPE_CODE', (string) dol_getIdFromCode($this->db, 1, 'c_ticket_type', 'use_default', 'code', 1));
        $ticket->category_code  = getDolGlobalString('MAIN_EMAILCOLLECTOR_TICKET_CATEGORY_CODE', (string) dol_getIdFromCode($this->db, 1, 'c_ticket_category', 'use_default', 'code', 1));
        $ticket->severity_code  = getDolGlobalString('MAIN_EMAILCOLLECTOR_TICKET_SEVERITY_CODE', (string) dol_getIdFromCode($this->db, 1, 'c_ticket_severity', 'use_default', 'code', 1));
        $ticket->origin_email   = $sender !== '' ? $sender : null;
        $ticket->fk_soc         = $socId > 0 ? $socId : 0;
        $ticket->fk_user_create = $author->id;
        $ticket->datec          = dol_now();
        $ticket->email_msgid    = (string) $row->message_id;
        $ticket->email_date     = $dateMail;
        $ticket->entity         = $conf->entity;
        $ticket->notify_tiers_at_create = getDolGlobalInt('TICKET_CHECK_NOTIFY_THIRDPARTY_AT_CREATION');

        // Several people: the first one is « Assigned to » of the ticket, all of them are its support users (tab Contacts)
        $assignees = self::assignUserIds($this->db);
        if (!empty($assignees)) {
            $ticket->fk_user_assign = $assignees[0];
            $ticket->status         = Ticket::STATUS_ASSIGNED;
        }
        if (empty($ticket->fk_user_assign)) {
            $ticket->status = Ticket::STATUS_NOT_READ;
        }

        $ticket->context['actionmsg']  = $langs->transnoentities('TmdTicketFromMail', $sender);
        $ticket->context['actionmsg2'] = $langs->transnoentities('TmdTicketFromMail', $sender);
        if ($contactId > 0) {
            $ticket->context['contact_id'] = $contactId;
        }

        if ($ticket->create($author) <= 0) {
            $this->error  = $ticket->error;
            $this->errors = array_merge($this->errors, (array) $ticket->errors);
            dol_syslog(__METHOD__ . ' ticket of mail ' . ((int) $row->rowid) . ' not created: ' . $ticket->error . ' ' . implode(', ', (array) $ticket->errors), LOG_ERR);
            return null;
        }

        if ($contactId > 0 && $ticket->add_contact($contactId, 'SUPPORTCLI', 'external') < 0) {
            dol_syslog(__METHOD__ . ' contact ' . $contactId . ' not added to ticket ' . $ticket->id . ': ' . $ticket->error, LOG_WARNING);
        }
        // The other people assigned: support users of the ticket too (the first one is added by Ticket::create())
        foreach (array_slice($assignees, 1) as $userId) {
            if ($ticket->add_contact($userId, 'SUPPORTTEC', 'internal') < 0) {
                dol_syslog(__METHOD__ . ' user ' . $userId . ' not added to ticket ' . $ticket->id . ': ' . $ticket->error, LOG_WARNING);
            }
        }

        return $ticket;
    }

    /**
     * Text of the mail for the ticket: its text part, else its HTML part as text, else the start kept by Inbox
     *
     * @param  InboxMessage|null $message Mail parsed
     * @param  string            $snippet Start of the mail kept in the index of Inbox
     * @return string
     */
    protected function bodyText($message, string $snippet): string
    {
        $text = '';
        if (is_object($message)) {
            $text = trim((string) $message->bodyText);
            if ($text === '' && trim((string) $message->bodyHtml) !== '') {
                $html = preg_replace('/<(br|\/p|\/div|\/li|\/tr|\/h[1-6])\b[^>]*>/i', "\n", (string) $message->bodyHtml);
                $text = trim(html_entity_decode(dol_string_nohtmltag($html, 0), ENT_QUOTES | ENT_HTML5, 'UTF-8'));
            }
        }
        if ($text === '') {
            $text = trim($snippet);
        }
        // Lines in a row without end: three at most
        $text = preg_replace("/\n{3,}/", "\n\n", str_replace(["\r\n", "\r"], "\n", $text));

        return dol_trunc($text, 30000, 'right', 'UTF-8', 1);
    }

    /**
     * Attachments of the mail in the documents of the ticket (never an executable file as such, never overwriting)
     *
     * @param  Ticket            $ticket  Ticket
     * @param  InboxMessage|null $message Mail parsed
     * @return int                        Files saved
     */
    protected function saveAttachments(Ticket $ticket, $message): int
    {
        global $conf;

        if (!is_object($message) || !method_exists($message, 'getFileAttachments')) {
            return 0;
        }
        $files = $message->getFileAttachments();
        if (empty($files)) {
            return 0;
        }

        $baseDir = $conf->ticket->multidir_output[$ticket->entity ?? $conf->entity] ?? $conf->ticket->dir_output;
        $dir     = $baseDir . '/' . dol_sanitizeFileName($ticket->ref);
        if (dol_mkdir($dir) < 0) {
            return 0;
        }

        $saved = 0;
        foreach ($files as $file) {
            $name = dol_sanitizeFileName((string) ($file['name'] ?? ''));
            if ($name === '' || $name === '.' || $name === '..') {
                $name = 'attachment-' . ($saved + 1);
            }
            if (isAFileWithExecutableContent($name)) {
                $name .= '.noexe';
            }
            $target = $dir . '/' . $name;
            for ($i = 2; file_exists($target); $i++) {
                $target = $dir . '/' . pathinfo($name, PATHINFO_FILENAME) . '-' . $i . (pathinfo($name, PATHINFO_EXTENSION) !== '' ? '.' . pathinfo($name, PATHINFO_EXTENSION) : '');
            }
            if (file_put_contents($target, (string) $file['content']) === false) {
                continue;
            }
            dolChmod($target);
            addFileIntoDatabaseIndex($dir, basename($target), '', 'uploaded', 0, $ticket);
            $saved++;
        }

        return $saved;
    }

    /**
     * The mail linked to the ticket in Inbox: it shows in the tab « Mails » of the ticket
     *
     * @param  object $row    Row of llx_inbox_mail
     * @param  Ticket $ticket Ticket
     * @return void
     */
    protected function linkMail(object $row, Ticket $ticket): void
    {
        if (!dol_include_once('/inbox/class/inboxmailref.class.php') || !dol_include_once('/inbox/class/inboxlink.class.php') || !class_exists('InboxLink')) {
            return;
        }
        $mailrefs  = new InboxMailRef($this->db);
        $mailrefId = $mailrefs->fetchOrCreate($row);
        if ($mailrefId > 0) {
            $links = new InboxLink($this->db);
            if ($links->link($mailrefId, 'ticket', (int) $ticket->id, null, 'dolitmd') < 0) {
                dol_syslog(__METHOD__ . ' mail not linked to ticket ' . $ticket->id . ': ' . $links->error, LOG_WARNING);
            }
        }
    }
}
