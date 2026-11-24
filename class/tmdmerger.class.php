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
 * \file    class/tmdmerger.class.php
 * \ingroup dolitmd
 * \brief   Two requests made one (merge), a message of the thread of a request made a request of its own (split)
 */

// Load Dolibarr libraries
require_once DOL_DOCUMENT_ROOT . '/core/lib/files.lib.php';
require_once DOL_DOCUMENT_ROOT . '/comm/action/class/actioncomm.class.php';
require_once DOL_DOCUMENT_ROOT . '/ticket/class/ticket.class.php';

// Load DoliTMD libraries
require_once __DIR__ . '/tmd.class.php';
require_once __DIR__ . '/tmdmailhandler.class.php';

/**
 * Class TmdMerger
 *
 * A request merged into another one is deleted (soft): its mails become mails of the other one in the answers
 * (llx_dolitmd_tmd_reply), its first mail included under mergeKey(). That row is what tells where it went: the mails
 * of its thread, its card and its tracking number lead to the request it was merged into (mergedInto()).
 */
class TmdMerger
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
     * Constructor
     *
     * @param DoliDB $db Database handler
     */
    public function __construct(DoliDB $db)
    {
        $this->db = $db;
    }

    /**
     * Key of a request among the answers of the request it is merged into: the Message-ID of its mail, else a key of
     * its own (a request typed by hand has no mail)
     *
     * @param  Tmd    $tmd Request
     * @return string
     */
    public static function mergeKey(Tmd $tmd): string
    {
        $messageId = trim((string) $tmd->message_id);

        return $messageId !== '' ? $messageId : 'dolitmd-merged-' . ((int) $tmd->id);
    }

    /**
     * Request a request was merged into, the last one when the merges follow each other (the answers of a request
     * merged go to the request it is merged into, the ones it got itself included)
     *
     * @param  Tmd $tmd Request
     * @return int      Id of the request, 0 when the request is not deleted by a merge
     */
    public static function mergedInto(Tmd $tmd): int
    {
        if ($tmd->id <= 0 || (int) $tmd->status !== Tmd::STATUS_DELETED) {
            return 0;
        }
        $p   = $tmd->db->prefix();
        $obj = $tmd->db->getRow('SELECT r.fk_tmd FROM ' . $p . 'dolitmd_tmd_reply AS r INNER JOIN ' . $p . 'dolitmd_tmd AS t ON t.rowid = r.fk_tmd'
            . " WHERE r.message_id = '" . $tmd->db->escape(self::mergeKey($tmd)) . "' AND r.entity IN (" . getEntity('tmd') . ')'
            . ' AND r.fk_tmd > 0 AND r.fk_tmd <> ' . ((int) $tmd->id) . ' AND t.status >= 0');

        return is_object($obj) ? (int) $obj->fk_tmd : 0;
    }

    /**
     * Requests checked in the list for a merge, the oldest one first (the one kept by default: the first mail)
     *
     * @param  int[]             $ids Requests checked
     * @return array<int,string>      Label (reference, subject, sender, date) by id, the deleted ones and the ones of
     *                                another entity left out
     */
    public function requestLabels(array $ids): array
    {
        $ids = array_values(array_unique(array_filter(array_map('intval', $ids))));
        if (empty($ids)) {
            return [];
        }
        $sql  = 'SELECT rowid, ref, label, email_from, date_mail, date_creation FROM ' . $this->db->prefix() . 'dolitmd_tmd';
        $sql .= ' WHERE entity IN (' . getEntity('tmd') . ') AND status >= 0 AND rowid IN (' . implode(',', $ids) . ')';
        $sql .= ' ORDER BY COALESCE(date_mail, date_creation) ASC, rowid ASC LIMIT ' . count($ids);
        $rows = $this->db->getRows($sql);

        $out = [];
        foreach (is_array($rows) ? $rows : [] as $row) {
            $date  = $this->db->jdate($row->date_mail ?: $row->date_creation);
            $parts = [(string) $row->ref, dol_trunc((string) $row->label, 60), (string) $row->email_from, dol_print_date($date, 'day', 'tzuserrel')];
            $out[(int) $row->rowid] = implode(' - ', array_filter($parts, 'strlen'));
        }

        return $out;
    }

    /**
     * Merge a request into another one, kept: the mail and the messages of its ticket go to the ticket of the request
     * kept, with their files and the contacts of the ticket; its documents (tab Documents) too; the fields the request
     * kept lacks are taken from it (nomeref ID, category, thirdparty, contact, sender). The request kept keeps its
     * reference, its state, its deadline and its tracking number. The other one is deleted (soft), its ticket
     * cancelled without mail; its thread, its card and its tracking number lead to the request kept
     *
     * @param  Tmd  $target Request kept
     * @param  Tmd  $source Request merged into it, deleted
     * @param  User $user   User merging
     * @return int          > 0 if OK, < 0 if KO
     */
    public function merge(Tmd $target, Tmd $source, User $user): int
    {
        global $conf, $langs;

        $langs->load('dolitmd@dolitmd');
        if ($target->id <= 0 || $source->id <= 0 || (int) $target->id === (int) $source->id) {
            $this->error = $langs->trans('TmdMergeSameRequest');
            return -1;
        }
        $p      = $this->db->prefix();
        $entity = getEntity('tmd');
        // Both alive, in the entities of the user (the request merged comes from a form)
        $alive = $this->db->getRow('SELECT COUNT(rowid) AS nb FROM ' . $p . 'dolitmd_tmd WHERE rowid IN (' . ((int) $target->id) . ', ' . ((int) $source->id) . ') AND entity IN (' . $entity . ') AND status >= 0');
        if (!is_object($alive) || (int) $alive->nb !== 2) {
            $this->error = $langs->trans('ErrorRecordNotFound');
            return -1;
        }

        $targetTicket = $this->ticket((int) $target->fk_ticket);
        $sourceTicket = $this->ticket((int) $source->fk_ticket);
        $key          = self::mergeKey($source);

        // Mails of the thread of the source, linked to the ticket kept in Inbox afterwards
        $mailIds = [];
        if (trim((string) $source->message_id) !== '') {
            $mailIds[] = (string) $source->message_id;
        }
        $rows = $this->db->getRows('SELECT message_id FROM ' . $p . 'dolitmd_tmd_reply WHERE fk_tmd = ' . ((int) $source->id) . ' AND entity IN (' . $entity . ') LIMIT 1000');
        foreach (is_array($rows) ? $rows : [] as $obj) {
            $mailIds[] = (string) $obj->message_id;
        }
        // The files of each thread with their message, read while each thread is alone: once together, the date of a
        // file could put it under a message of the other request
        $targetThread = $targetTicket !== null && $sourceTicket !== null ? $target->getRequestThread() : [];
        $sourceThread = $targetTicket !== null && $sourceTicket !== null ? $source->getRequestThread() : [];

        $this->db->begin();

        // The answers of the source are answers of the request kept, its first mail too (the record of the merge)
        $ok = (bool) $this->db->query('UPDATE ' . $p . 'dolitmd_tmd_reply SET fk_tmd = ' . ((int) $target->id) . ' WHERE fk_tmd = ' . ((int) $source->id) . ' AND entity IN (' . $entity . ')');
        $ok = $ok && $this->db->query('DELETE FROM ' . $p . "dolitmd_tmd_reply WHERE message_id = '" . $this->db->escape($key) . "' AND entity IN (" . $entity . ')');
        $ok = $ok && $this->db->query('INSERT INTO ' . $p . 'dolitmd_tmd_reply (entity, fk_tmd, message_id, email_from, date_mail, date_creation) VALUES ('
            . ((int) $conf->entity) . ', ' . ((int) $target->id) . ", '" . $this->db->escape($key) . "', "
            . (trim((string) $source->email_from) !== '' ? "'" . $this->db->escape((string) $source->email_from) . "'" : 'NULL') . ', '
            . (!empty($source->date_mail) ? "'" . $this->db->idate($this->timestamp($source->date_mail)) . "'" : 'NULL') . ", '" . $this->db->idate(dol_now()) . "')");

        // Counters of the answers: the ones of the source, and its mail
        $last = max($this->timestamp($target->date_last_reply), $this->timestamp($source->date_last_reply), trim((string) $source->message_id) !== '' ? $this->timestamp($source->date_mail) : 0);
        $target->nb_replies      = (int) $target->nb_replies + (int) $source->nb_replies + (trim((string) $source->message_id) !== '' ? 1 : 0);
        $target->date_last_reply = $last > 0 ? $last : null;

        // What the request kept lacks
        foreach (['software_ref', 'category', 'fk_soc', 'fk_socpeople', 'email_from'] as $field) {
            if (empty($target->$field) && !empty($source->$field)) {
                $target->$field = $source->$field;
            }
        }

        // The ticket: the messages of the source in the ticket kept; the request kept without ticket (typed by hand)
        // takes the one of the source as it is
        $mailEventId = 0;
        if ($ok && $sourceTicket !== null) {
            if ($targetTicket === null) {
                $target->fk_ticket = $sourceTicket->id;
            } else {
                $mailEventId = $this->moveTicketMessages($source, $sourceTicket, $targetTicket, $user);
                $ok          = $mailEventId > 0;
            }
        }

        $ok = $ok && $target->update($user) > 0;
        $ok = $ok && $source->delete($user) > 0;
        if (!$ok) {
            $this->error  = $this->error ?: ($target->error ?: ($source->error ?: $this->db->lasterror()));
            $this->errors = array_merge($this->errors, (array) $target->errors, (array) $source->errors);
            dol_syslog(__METHOD__ . ' ' . $source->ref . ' not merged into ' . $target->ref . ': ' . $this->error, LOG_ERR);
            $this->db->rollback();
            return -1;
        }

        $this->db->commit();

        // Out of the transaction: the files moved, the contacts, the mails in Inbox, the ticket of the source cancelled.
        // A failure here keeps the merge
        if ($mailEventId > 0) {
            $moved = $this->moveFiles($this->ticketDir($sourceTicket), $this->ticketDir($targetTicket), 'ticket', (int) $targetTicket->id);
            $this->bindFiles($targetTicket, $targetThread, 0);
            $this->bindFiles($targetTicket, $sourceThread, $mailEventId, $moved);
            $this->copyTicketContacts($source, $sourceTicket, $targetTicket);

            $handler = new TmdMailHandler($this->db);
            foreach (array_unique($mailIds) as $messageId) {
                $row = $handler->mailRow($messageId);
                if ($row !== null) {
                    $handler->linkMail($row, $targetTicket);
                }
            }

            // The ticket of the source, empty now: a note telling where its messages went, then cancelled (never the
            // mail « ticket closed » of the trigger: the request goes on in the ticket kept)
            $sourceTicket->message = $langs->transnoentities('TmdMergedTicketNote', $source->ref, $target->ref, $targetTicket->ref);
            $sourceTicket->private = 1;
            $sourceTicket->createTicketMessage($user, 1, [], [], [], false, 0, $langs->transnoentities('TmdMergedNoteTitle'));
            if (!in_array((int) $sourceTicket->status, [Ticket::STATUS_CLOSED, Ticket::STATUS_CANCELED], true)) {
                $sourceTicket->setStatut(Ticket::STATUS_CANCELED);
            }
        }
        if (!empty($this->moveFiles($source->getUploadDir(), $target->getUploadDir(), $target->table_element, (int) $target->id))) {
            $target->fillSoftwareRefFromDocuments($user);
        }

        return 1;
    }

    /**
     * A message of the thread of a request made a request of its own, with its own ticket: an answer received (never
     * the mail of the request, a note nor a mail sent). Its text, its files and its mail (subject, sender, people in
     * copy, Message-ID: the answers to it go to the new request) leave the request; a private note of its ticket tells
     * where it went. No mail is sent
     *
     * @param  Tmd    $parent  Request whose thread has the message
     * @param  int    $eventId Event of the message (llx_actioncomm)
     * @param  User   $user    User taking the message out
     * @param  string $subject Subject of the new request, empty: the subject of the mail of the message
     * @return int             Id of the new request, < 0 if KO
     */
    public function split(Tmd $parent, int $eventId, User $user, string $subject = ''): int
    {
        global $langs;

        $langs->load('dolitmd@dolitmd');
        $item = $this->threadMessage($parent, $eventId);
        if ($item === null) {
            $this->error = $langs->trans('TmdSplitMessageNotFound');
            return -1;
        }
        $parentTicket = $this->ticket((int) $parent->fk_ticket);
        $event        = $this->db->getRow('SELECT id, label, note, datep, datec, email_msgid, email_from, email_subject FROM ' . $this->db->prefix() . 'actioncomm WHERE id = ' . $eventId);
        if ($parentTicket === null || !is_object($event)) {
            $this->error = $langs->trans('TmdSplitMessageNotFound');
            return -1;
        }

        $p         = $this->db->prefix();
        $entity    = getEntity('tmd');
        $handler   = new TmdMailHandler($this->db);
        $reply     = $this->messageReply($parent, $event);
        $messageId = $reply !== null ? trim((string) $reply->message_id) : '';
        // A Message-ID that a request still has stays with it (unique Message-ID): the new request goes without
        if ($messageId !== '' && is_object($this->db->getRow('SELECT rowid FROM ' . $p . "dolitmd_tmd WHERE message_id = '" . $this->db->escape($messageId) . "' AND status >= 0 AND entity IN (" . $entity . ')'))) {
            $messageId = '';
        }
        $mail = $handler->mailRow($messageId);

        $sender   = strtolower(trim((string) ($reply->email_from ?? '') ?: (string) ($event->email_from ?? '') ?: (string) ($mail->requester_email ?? '') ?: (string) ($mail->from_email ?? '') ?: (string) $parent->email_from));
        $subject  = trim($subject) !== '' ? trim($subject) : (trim((string) ($mail->subject ?? '')) ?: (trim((string) $event->email_subject) ?: (string) $parent->label));
        $dateMail = !empty($reply->date_mail) ? (int) $this->db->jdate($reply->date_mail) : (!empty($mail->date_received) ? (int) $this->db->jdate($mail->date_received) : (int) $this->db->jdate($event->datep));

        // The mail of the request: the one of the index of Inbox (people in copy, link of the mail), its text as the
        // thread shows it
        $row             = $mail !== null ? clone $mail : (object) ['rowid' => 0, 'subject' => $subject, 'to_text' => '', 'cc_text' => '', 'from_name' => ''];
        $row->message_id = $messageId;
        $row->snippet    = (string) $event->note;

        $this->db->begin();

        $ok = true;
        if ($messageId !== '') {
            // The mail is the one of the new request now: an answer of no request, and a request merged then deleted
            // that had it lets it go
            $ok = (bool) $this->db->query('DELETE FROM ' . $p . "dolitmd_tmd_reply WHERE message_id = '" . $this->db->escape($messageId) . "' AND entity IN (" . $entity . ')');
            $ok = $ok && $this->db->query('UPDATE ' . $p . "dolitmd_tmd SET message_id = NULL WHERE message_id = '" . $this->db->escape($messageId) . "' AND status = " . Tmd::STATUS_DELETED . ' AND entity IN (' . $entity . ')');
        }
        // Counters of the answers of the request: the mails still in its thread
        if ($ok && $reply !== null && (int) $reply->fk_tmd === (int) $parent->id) {
            $last = $this->db->getRow('SELECT MAX(date_mail) AS last FROM ' . $p . 'dolitmd_tmd_reply WHERE fk_tmd = ' . ((int) $parent->id));
            $ok   = $this->db->query('UPDATE ' . $p . 'dolitmd_tmd SET nb_replies = CASE WHEN nb_replies > 0 THEN nb_replies - 1 ELSE 0 END, date_last_reply = '
                . (is_object($last) && !empty($last->last) ? "'" . $this->db->escape((string) $last->last) . "'" : 'NULL') . ' WHERE rowid = ' . ((int) $parent->id));
        }

        $request = $ok ? $handler->createRequest($row, null, $subject, $sender, $dateMail, $user, $mail !== null ? TmdMailHandler::configuredMailbox($this->db) : null, false) : null;
        if ($request === null) {
            $this->error  = $handler->error ?: $this->db->lasterror();
            $this->errors = $handler->errors;
            dol_syslog(__METHOD__ . ' message ' . $eventId . ' of ' . $parent->ref . ' not taken out: ' . $this->error, LOG_ERR);
            $this->db->rollback();
            return -1;
        }

        // The message leaves the thread of the request; a note tells where it went
        $message = new ActionComm($this->db);
        if ($message->fetch($eventId) <= 0 || $message->delete($user, 1) <= 0) {
            $this->error = $message->error ?: $this->db->lasterror();
            dol_syslog(__METHOD__ . ' message ' . $eventId . ' of ' . $parent->ref . ' not deleted: ' . $this->error, LOG_ERR);
            $this->db->rollback();
            return -1;
        }
        $parentTicket->message = $langs->transnoentities('TmdSplitTicketNote', $sender !== '' ? $sender : '?', dol_print_date($dateMail, 'dayhour', 'tzuserrel'), $request->ref);
        $parentTicket->private = 1;
        $parentTicket->createTicketMessage($user, 1, [], [], [], false, 0, $langs->transnoentities('TmdSplitNoteTitle', $request->ref));

        $this->db->commit();

        // Out of the transaction: the files of the message, its mail no longer linked to the ticket of the request
        $newTicket = $this->ticket((int) $request->fk_ticket);
        if ($newTicket !== null && !empty($item['files'])) {
            $this->moveFiles($this->ticketDir($parentTicket), $this->ticketDir($newTicket), 'ticket', (int) $newTicket->id, array_column($item['files'], 'name'), false);
        }
        if ($mail !== null) {
            $handler->unlinkMail($mail, (int) $parentTicket->id);
        }

        return (int) $request->id;
    }

    /**
     * Message of the thread of a request that may become a request: an answer received (TICKET_MSG), as the card
     * shows it, with its files
     *
     * @param  Tmd        $parent  Request
     * @param  int        $eventId Event of the message
     * @return array|null          Item of Tmd::getRequestThread(), null when it is not one
     */
    public function threadMessage(Tmd $parent, int $eventId): ?array
    {
        if ($eventId <= 0) {
            return null;
        }
        foreach ($parent->getRequestThread() as $item) {
            if ((int) $item['id'] === $eventId && $item['code'] === 'ticket_msg') {
                return $item;
            }
        }

        return null;
    }

    /**
     * Subject the new request of a message takes: the one of its mail, else the subject of the request
     *
     * @param  Tmd    $parent  Request
     * @param  int    $eventId Event of the message
     * @return string
     */
    public function splitSubject(Tmd $parent, int $eventId): string
    {
        $event = $this->db->getRow('SELECT id, label, datec, email_msgid, email_from, email_subject FROM ' . $this->db->prefix() . 'actioncomm WHERE id = ' . $eventId);
        if (!is_object($event)) {
            return (string) $parent->label;
        }
        $reply = $this->messageReply($parent, $event);
        $mail  = $reply !== null ? (new TmdMailHandler($this->db))->mailRow((string) $reply->message_id) : null;

        return trim((string) ($mail->subject ?? '')) ?: (trim((string) $event->email_subject) ?: (string) $parent->label);
    }

    /**
     * Mail of a message of a thread, among the answers of the request: the one noted on the message (email_msgid),
     * else, for a message added before the mail was noted on it, the answer saved in the same minutes from the address
     * its title names, the closest one
     *
     * @param  Tmd         $parent Request
     * @param  object      $event  Row of llx_actioncomm (id, label, datec, email_msgid, email_from)
     * @return object|null         Row of llx_dolitmd_tmd_reply (rowid, fk_tmd, message_id, email_from, date_mail)
     */
    protected function messageReply(Tmd $parent, object $event): ?object
    {
        $p      = $this->db->prefix();
        $entity = getEntity('tmd');
        $known  = trim((string) ($event->email_msgid ?? ''));
        if ($known !== '') {
            $reply = $this->db->getRow('SELECT rowid, fk_tmd, message_id, email_from, date_mail, date_creation FROM ' . $p . "dolitmd_tmd_reply WHERE message_id = '" . $this->db->escape($known) . "' AND entity IN (" . $entity . ')');

            return is_object($reply) ? $reply : (object) ['rowid' => 0, 'fk_tmd' => 0, 'message_id' => $known, 'email_from' => (string) ($event->email_from ?? ''), 'date_mail' => null];
        }

        $created = (int) $this->db->jdate($event->datec);
        $rows    = $this->db->getRows('SELECT rowid, fk_tmd, message_id, email_from, date_mail, date_creation FROM ' . $p . 'dolitmd_tmd_reply WHERE fk_tmd = ' . ((int) $parent->id)
            . " AND date_creation BETWEEN '" . $this->db->idate($created - 300) . "' AND '" . $this->db->idate($created + 60) . "' ORDER BY rowid LIMIT 50");
        $best    = null;
        $bestGap = PHP_INT_MAX;
        foreach (is_array($rows) ? $rows : [] as $row) {
            $from = trim((string) $row->email_from);
            if ($from !== '' && stripos((string) $event->label, $from) === false) {
                continue;
            }
            $gap = abs((int) $this->db->jdate($row->date_creation) - $created);
            if ($gap < $bestGap) {
                $best    = $row;
                $bestGap = $gap;
            }
        }

        return $best;
    }

    /**
     * The mail and the messages of the ticket of a request in the ticket of another one: the mail as an answer at its
     * date, the messages as they are (their files are bound to them afterwards: bindFiles())
     *
     * @param  Tmd    $source       Request merged
     * @param  Ticket $sourceTicket Its ticket
     * @param  Ticket $targetTicket Ticket of the request kept
     * @param  User   $user         User merging
     * @return int                  Message of the mail of the request merged, < 0 if KO
     */
    protected function moveTicketMessages(Tmd $source, Ticket $sourceTicket, Ticket $targetTicket, User $user): int
    {
        global $langs;

        $sender = trim((string) ($source->email_from ?: $sourceTicket->origin_email));
        $date   = $this->timestamp($source->date_mail) ?: (int) $sourceTicket->datec;

        $event               = new ActionComm($this->db);
        $event->type_code    = 'AC_OTH_AUTO';
        $event->code         = 'TICKET_MSG';
        $event->label        = $langs->transnoentities('TmdMergedMailFrom', $source->ref, $sender !== '' ? $sender : '?');
        $event->note_private = (string) $sourceTicket->message;
        $event->datep        = $date;
        $event->percentage   = -1;
        $event->elementtype  = 'ticket';
        $event->fk_element   = (int) $targetTicket->id;
        $event->elementid    = (int) $targetTicket->id;
        $event->socid        = (int) $targetTicket->fk_soc;
        $event->userownerid  = (int) $user->id;
        $event->userassigned = [$user->id => ['id' => $user->id, 'transparency' => 0]];
        $event->email_msgid  = trim((string) $source->message_id) !== '' ? (string) $source->message_id : null;
        $event->email_from   = $sender !== '' ? $sender : null;
        $event->email_subject = (string) $source->label;
        $eventId             = $event->create($user, 1);
        if ($eventId <= 0) {
            $this->error  = $event->error;
            $this->errors = (array) $event->errors;
            return -1;
        }

        $p     = $this->db->prefix();
        $where = " WHERE elementtype = 'ticket' AND fk_element = " . ((int) $sourceTicket->id) . " AND code IN ('TICKET_MSG', 'TICKET_MSG_SENTBYMAIL', 'TICKET_MSG_PRIVATE', 'TICKET_MSG_PRIVATE_SENTBYMAIL')";
        $ok    = $this->db->query('UPDATE ' . $p . "actioncomm SET datec = '" . $this->db->idate((int) $sourceTicket->datec) . "' WHERE id = " . ((int) $eventId));

        // The title of a message starts with the subject of its ticket (cut after its first [...]: Ticket::createTicketMessage()):
        // the one of the ticket kept now, so that the thread shows the title alone
        $from = self::subjectPrefix((string) $sourceTicket->subject);
        $to   = self::subjectPrefix((string) $targetTicket->subject);
        $rows = $from !== '' && $from !== $to ? $this->db->getRows('SELECT id, label FROM ' . $p . 'actioncomm' . $where . ' AND id <> ' . ((int) $eventId) . ' LIMIT 1000') : [];
        foreach (is_array($rows) ? $rows : [] as $row) {
            if ($ok && strpos((string) $row->label, $from) === 0) {
                $label = trim($to . ' ' . trim(substr((string) $row->label, strlen($from))));
                $ok    = (bool) $this->db->query('UPDATE ' . $p . "actioncomm SET label = '" . $this->db->escape(dol_trunc($label, 255, 'right', 'UTF-8', 1)) . "' WHERE id = " . ((int) $row->id));
            }
        }

        $ok = $ok && $this->db->query('UPDATE ' . $p . 'actioncomm SET fk_element = ' . ((int) $targetTicket->id) . $where);

        return $ok ? (int) $eventId : -1;
    }

    /**
     * Start of the title of the messages of a ticket: its subject, cut after its first [...] (Ticket::createTicketMessage())
     *
     * @param  string $subject Subject of the ticket
     * @return string
     */
    public static function subjectPrefix(string $subject): string
    {
        return trim((string) preg_replace('/(\[[^\]]*\]).*$/', '\1', $subject));
    }

    /**
     * The files of a thread bound to their message in the index (agenda_id), as the thread showed them: the ones
     * already bound keep their message
     *
     * @param  Ticket                $ticket       Ticket holding the files now
     * @param  array                 $thread       Thread read before (Tmd::getRequestThread())
     * @param  int                   $firstEventId Message the files of the mail of the request go with, 0: they stay without
     * @param  array<string,string>  $renamed      New name of a file renamed by its move
     * @return void
     */
    protected function bindFiles(Ticket $ticket, array $thread, int $firstEventId, array $renamed = []): void
    {
        $relDir = trim((string) preg_replace('/^' . preg_quote(DOL_DATA_ROOT, '/') . '/', '', $this->ticketDir($ticket)), '/\\');
        foreach ($thread as $item) {
            $eventId = (int) $item['id'] > 0 ? (int) $item['id'] : $firstEventId;
            if ($eventId <= 0 || empty($item['files'])) {
                continue;
            }
            $names = array_map(function ($file) use ($renamed) {
                return "'" . $this->db->escape($renamed[$file['name']] ?? $file['name']) . "'";
            }, $item['files']);
            $this->db->query('UPDATE ' . $this->db->prefix() . 'ecm_files SET agenda_id = ' . $eventId . " WHERE filepath = '" . $this->db->escape($relDir) . "'"
                . ' AND filename IN (' . implode(',', $names) . ') AND (agenda_id IS NULL OR agenda_id = 0) AND entity IN (' . getEntity('ecmfiles') . ')');
        }
    }

    /**
     * External contacts of the ticket of the request merged, and its contact, on the ticket kept: the mails written
     * from the request kept go to them too
     *
     * @param  Tmd    $source       Request merged
     * @param  Ticket $sourceTicket Its ticket
     * @param  Ticket $targetTicket Ticket kept
     * @return void
     */
    protected function copyTicketContacts(Tmd $source, Ticket $sourceTicket, Ticket $targetTicket): void
    {
        $known = [];
        foreach ((array) $targetTicket->liste_contact(-1, 'external') as $contact) {
            $known[(int) $contact['id']] = true;
        }
        $contacts = [];
        foreach ((array) $sourceTicket->liste_contact(-1, 'external') as $contact) {
            $contacts[(int) $contact['id']] = (string) ($contact['code'] ?? 'SUPPORTCLI');
        }
        if ((int) $source->fk_socpeople > 0 && !isset($contacts[(int) $source->fk_socpeople])) {
            $contacts[(int) $source->fk_socpeople] = 'SUPPORTCLI';
        }
        foreach ($contacts as $contactId => $code) {
            if ($contactId > 0 && !isset($known[$contactId]) && $targetTicket->add_contact($contactId, $code ?: 'SUPPORTCLI', 'external') > 0) {
                $known[$contactId] = true;
            }
        }
    }

    /**
     * Files of a directory moved to another one, their index with them (the object they belong to, the message they
     * go with): never overwriting (« name-2.pdf »), their date kept (a file without message goes with the message
     * created before it)
     *
     * @param  string        $fromDir    Directory of the files
     * @param  string        $toDir      Directory they go to
     * @param  string        $objectType Type of the object of the index (ticket, dolitmd_tmd)
     * @param  int           $objectId   Object they belong to now
     * @param  string[]|null $only       Names of the files to move, null: all of them
     * @param  bool          $keepEvent  False: they leave their message (a message become the mail of a request)
     * @return array<string,string>      Files moved: new name by name
     */
    protected function moveFiles(string $fromDir, string $toDir, string $objectType, int $objectId, ?array $only = null, bool $keepEvent = true): array
    {
        if ($fromDir === '' || $toDir === '' || $fromDir === $toDir || !is_dir($fromDir) || dol_mkdir($toDir) < 0) {
            return [];
        }
        $relDir = trim((string) preg_replace('/^' . preg_quote(DOL_DATA_ROOT, '/') . '/', '', $toDir), '/\\');
        $moved  = [];
        foreach (dol_dir_list($fromDir, 'files', 0, '', '(\.meta|_preview.*\.png)$', 'date', SORT_ASC, 1) as $file) {
            if ($file['name'][0] === '.' || ($only !== null && !in_array($file['name'], $only, true))) {
                continue;
            }
            $name   = (string) $file['name'];
            $target = $toDir . '/' . $name;
            for ($i = 2; file_exists($target); $i++) {
                $target = $toDir . '/' . pathinfo($name, PATHINFO_FILENAME) . '-' . $i . (pathinfo($name, PATHINFO_EXTENSION) !== '' ? '.' . pathinfo($name, PATHINFO_EXTENSION) : '');
            }
            if (!dol_move($file['fullname'], $target, '0', 0, 0, 1, ['src_object_type' => $objectType, 'src_object_id' => $objectId])) {
                dol_syslog(__METHOD__ . ' ' . $file['fullname'] . ' not moved to ' . $target, LOG_WARNING);
                continue;
            }
            @touch($target, (int) $file['date']);
            $this->db->query('UPDATE ' . $this->db->prefix() . "ecm_files SET src_object_type = '" . $this->db->escape($objectType) . "', src_object_id = " . $objectId . ($keepEvent ? '' : ', agenda_id = NULL')
                . " WHERE filepath = '" . $this->db->escape($relDir) . "' AND filename = '" . $this->db->escape(basename($target)) . "' AND entity IN (" . getEntity('ecmfiles') . ')');
            $moved[$name] = basename($target);
        }

        return $moved;
    }

    /**
     * Ticket of a request
     *
     * @param  int         $ticketId Ticket
     * @return Ticket|null           Null without ticket, or the module Ticket off
     */
    protected function ticket(int $ticketId): ?Ticket
    {
        if ($ticketId <= 0 || !isModEnabled('ticket')) {
            return null;
        }
        $ticket = new Ticket($this->db);

        return $ticket->fetch($ticketId) > 0 ? $ticket : null;
    }

    /**
     * Directory of the files of a ticket
     *
     * @param  Ticket $ticket Ticket
     * @return string
     */
    protected function ticketDir(Ticket $ticket): string
    {
        global $conf;

        $baseDir = $conf->ticket->multidir_output[$ticket->entity ?? $conf->entity] ?? $conf->ticket->dir_output;

        return $baseDir . '/' . dol_sanitizeFileName($ticket->ref);
    }

    /**
     * Timestamp of a date of a request (a timestamp once fetched, a date of the database otherwise)
     *
     * @param  mixed $date Date
     * @return int         0 when empty
     */
    protected function timestamp($date): int
    {
        if (empty($date)) {
            return 0;
        }

        return is_numeric($date) ? (int) $date : (int) $this->db->jdate($date);
    }
}
