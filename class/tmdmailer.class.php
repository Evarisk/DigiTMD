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
 * \file    class/tmdmailer.class.php
 * \ingroup dolitmd
 * \brief   Automatic mails of the TMD requests: receipt and done to the sender, late requests to the person assigned
 */

// Load Dolibarr libraries
require_once DOL_DOCUMENT_ROOT . '/core/lib/date.lib.php';
require_once DOL_DOCUMENT_ROOT . '/societe/class/societe.class.php';
require_once DOL_DOCUMENT_ROOT . '/ticket/class/ticket.class.php';

// Load DoliTMD libraries
require_once __DIR__ . '/tmd.class.php';

/**
 * Class TmdMailer
 *
 * Each mail is written from its template (Setup > Emails > Templates, type « TMD requests »). The receipt and the
 * reminder of the late requests are automatic, off until switched on in the setup; the mail « request done » and the
 * messages to the customer while the request is being done are never automatic: they leave from the buttons of the card
 * (or the API), once read again. Sent by the server of the TMD
 * mailbox (module Inbox) when there is one: the sender sees the TMD address, his answer comes back in the same
 * conversation (In-Reply-To). Else by the mail setup of Dolibarr. The nomeref ID is internal: never offered to the
 * templates.
 */
class TmdMailer
{
    /**
     * Type of the templates of the module in llx_c_email_templates
     */
    public const TEMPLATE_TYPE = 'dolitmd_tmd';

    /**
     * The mails: constant switching on the automatic ones (none for « request done » and the messages, sent by hand),
     * label of their template
     */
    public const MAILS = [
        'ack'     => ['const' => 'DOLITMD_MAIL_ACK', 'template' => '(TmdMailAckTemplate)'],
        'message' => ['const' => '', 'template' => '(TmdMailMessageTemplate)'],
        'done'    => ['const' => '', 'template' => '(TmdMailDoneTemplate)'],
        'late'    => ['const' => 'DOLITMD_MAIL_LATE', 'template' => '(TmdMailLateTemplate)'],
    ];

    /**
     * The mails written on the card to the customer of a request: ticket message written once sent
     */
    public const CARD_MAILS = [
        'message' => 'TmdMailMessageSent',
        'done'    => 'TmdMailDoneSent',
    ];

    /**
     * @var DoliDB Database handler
     */
    public $db;

    /**
     * @var string Error
     */
    public $error = '';

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
     * Receipt to the sender of a request received by mail
     *
     * @param  Tmd  $tmd  TMD request
     * @param  User $user User running the reception
     * @return bool       Sent
     */
    public function sendAck(Tmd $tmd, User $user): bool
    {
        return $this->sendToSender('ack', $tmd, $user, 'auto-replied');
    }

    /**
     * Mail of the card to read again before sending it (« request done », or a message while the request is being done):
     * to the sender, in copy the people in copy of his mail, the template filled, the documents of the request (all of
     * them attached for « request done », none for a message)
     *
     * @param  Tmd    $tmd  TMD request
     * @param  string $mail done or message (CARD_MAILS)
     * @return array{to:string,cc:array<int,array{email:string,name:string}>,subject:string,html:string,files:array<int,array{name:string,size:int,date:int,fullname:string}>,attach:string[]}
     */
    public function cardDraft(Tmd $tmd, string $mail): array
    {
        global $langs;

        $langs->load('dolitmd@dolitmd');
        $ticket   = $this->ticketOf($tmd);
        $template = $this->template($mail);
        if ($template !== null) {
            [$subject, $html] = $this->compose($template, $this->substitutions($tmd, $ticket));
        } else {
            // Template missing (module not activated again since it came): the subject of the conversation, the
            // reference of the ticket (else of the request) kept for the answer
            [$subject, $html] = ['Re: ' . $tmd->label . ' [' . ($ticket ? $ticket->ref : $tmd->ref) . ']', ''];
        }
        require_once __DIR__ . '/tmdmailhandler.class.php';
        $files = $tmd->getPublicFiles();

        return [
            'to'      => (string) $tmd->email_from,
            'cc'      => (new TmdMailHandler($this->db))->requestCopies($tmd),
            'subject' => $subject,
            'html'    => $html,
            'files'   => $files,
            'attach'  => $mail == 'done' ? array_column($files, 'name') : [],
        ];
    }

    /**
     * Send a mail of the card (« request done », or a message while the request is being done) in the conversation of
     * the mail of the request, the documents chosen attached, and write it in the messages of the ticket. The state of
     * the request is not changed here
     *
     * @param  string                                    $mail    done or message (CARD_MAILS)
     * @param  Tmd                                       $tmd     TMD request
     * @param  User                                      $user    User sending
     * @param  array<int,array{email:string,name:string}> $to      Recipients
     * @param  array<int,array{email:string,name:string}> $cc      Copies
     * @param  string                                    $subject Subject
     * @param  string                                    $html    HTML
     * @param  string[]                                  $files   Names of documents of the request (tab Documents)
     * @return bool                                               Sent
     */
    public function sendCardMail(string $mail, Tmd $tmd, User $user, array $to, array $cc, string $subject, string $html, array $files): bool
    {
        global $langs;

        $langs->load('dolitmd@dolitmd');
        $this->error = '';
        if (!isset(self::CARD_MAILS[$mail])) {
            $this->error = 'Unknown mail ' . $mail;
            return false;
        }
        if (empty($to)) {
            $this->error = $langs->transnoentities('ErrorFieldRequired', $langs->transnoentities('MailTo'));
            return false;
        }

        // Only documents of the request, found by their name in its folder: never a path given by the page
        $attachments = [];
        $size        = 0;
        foreach ($tmd->getPublicFiles() as $file) {
            if (in_array($file['name'], $files, true)) {
                $attachments[] = $file;
                $size += $file['size'];
            }
        }
        $max = getDolGlobalInt('INBOX_SEND_MAX_MB', 20) * 1048576;
        if ($size > $max) {
            $this->error = $langs->transnoentities('TmdMailTooBig', dol_print_size($size), dol_print_size($max));
            return false;
        }

        // Addresses typed alone: the names given in the mail of the request, as in a normal answer
        require_once __DIR__ . '/tmdmailhandler.class.php';
        $names = array_column((new TmdMailHandler($this->db))->requestPeople($tmd), 'name', 'email');
        $named = static function (array $list) use ($names): array {
            foreach ($list as $i => $person) {
                if ($person['name'] === '' && !empty($names[$person['email']])) {
                    $list[$i]['name'] = $names[$person['email']];
                }
            }
            return $list;
        };
        $to = $named($to);
        $cc = $named($cc);

        $ticket = $this->ticketOf($tmd);
        if (!$this->sendFull($to, $cc, $subject, $html, (string) $tmd->message_id, $attachments, $ticket)) {
            return false;
        }

        // In the messages of the ticket: what was sent, to whom, with which documents
        if ($ticket) {
            $names           = array_column($attachments, 'name');
            $ticket->message = $this->text($html) . ($names ? "\n\n" . $langs->transnoentities('TmdMailAttachments', implode(', ', $names)) : '');
            $ticket->private = 0;
            $recipients      = dol_trunc(implode(', ', array_column(array_merge($to, $cc), 'email')), 180, 'right', 'UTF-8', 1);
            $ticket->createTicketMessage($user, 1, [], [], [], true, 0, $langs->transnoentities(self::CARD_MAILS[$mail], $recipients));
        }

        return true;
    }

    /**
     * Addresses typed (« Name <a@b.c>, d@e.f; ... »), each one checked
     *
     * @param  string   $value Text
     * @param  string[] $bad   Addresses refused
     * @return array<int,array{email:string,name:string}>
     */
    public static function parseAddresses(string $value, array &$bad = []): array
    {
        // Read here, never with a function of Inbox: its classes change from a version to another
        $out = [];
        foreach (preg_split('/[,;\n]+(?=(?:[^"]*"[^"]*")*[^"]*$)/', $value) as $part) {
            $email = strtolower(trim(preg_match('/<([^<>]+)>/', $part, $m) ? $m[1] : $part));
            if ($email === '') {
                continue;
            }
            if (!isValidEmail($email) || preg_match('/[\r\n]/', $email)) {
                $bad[] = $email;
                continue;
            }
            $out[$email] = ['email' => $email, 'name' => strpos($part, '<') !== false ? trim(preg_replace('/<[^<>]*>|["\x00-\x1F]/', '', $part)) : ''];
        }

        return array_values($out);
    }

    /**
     * Addresses for a field of the page (« Name <a@b.c>, d@e.f »)
     *
     * @param  array<int,array{email:string,name:string}> $list Addresses
     * @return string
     */
    public static function addressesText(array $list): string
    {
        return implode(', ', array_map(static function ($a) {
            return $a['name'] !== '' ? $a['name'] . ' <' . $a['email'] . '>' : $a['email'];
        }, $list));
    }

    /**
     * Address the mails of the requests come from: the TMD mailbox, else the mail setup of Dolibarr
     *
     * @return string
     */
    public function senderAddress(): string
    {
        require_once __DIR__ . '/tmdmailhandler.class.php';
        $mailbox = TmdMailHandler::configuredMailbox($this->db);

        return $mailbox !== null && (int) $mailbox->status === 1 ? (string) $mailbox->email : getDolGlobalString('MAIN_MAIL_EMAIL_FROM');
    }

    /**
     * Ticket of a request
     *
     * @param  Tmd         $tmd TMD request
     * @return Ticket|null
     */
    protected function ticketOf(Tmd $tmd): ?Ticket
    {
        if (empty($tmd->fk_ticket)) {
            return null;
        }
        $ticket = new Ticket($this->db);

        return $ticket->fetch((int) $tmd->fk_ticket) > 0 ? $ticket : null;
    }

    /**
     * Scheduled job, every day: each person assigned gets the list of his late requests (the person « Assigned to » of
     * the ticket and its other support users; the people of the setup when the ticket has none)
     *
     * @return int 0 if OK, < 0 if KO
     */
    public function cronLateReminder(): int
    {
        global $langs;

        $this->output = '';
        if (!getDolGlobalInt(self::MAILS['late']['const'])) {
            $this->output = 'Reminder of the late requests switched off';
            return 0;
        }

        $langs->load('dolitmd@dolitmd');
        $p    = $this->db->prefix();
        $sql  = 'SELECT t.rowid, t.fk_ticket, k.fk_user_assign FROM ' . $p . 'dolitmd_tmd AS t LEFT JOIN ' . $p . 'ticket AS k ON k.rowid = t.fk_ticket';
        // A subcontracted request is never late (Tmd::isLate())
        $sql .= ' WHERE t.entity IN (' . getEntity('tmd') . ') AND t.status >= 0 AND t.status <> ' . Tmd::STATUS_SUBCONTRACTED . ' AND t.done = 0 AND t.date_due IS NOT NULL';
        $sql .= " AND t.date_due < '" . $this->db->idate(dol_get_first_hour(dol_now())) . "' ORDER BY t.date_due ASC LIMIT 500";
        $rows = $this->db->getRows($sql);

        require_once __DIR__ . '/tmdmailhandler.class.php';
        $fallback = TmdMailHandler::assignUserIds($this->db);
        $perUser  = [];
        foreach (is_array($rows) ? $rows : [] as $row) {
            $userIds = $this->ticketUserIds((int) $row->fk_ticket);
            if ((int) $row->fk_user_assign > 0) {
                $userIds[] = (int) $row->fk_user_assign;
            }
            foreach (array_unique($userIds ?: $fallback) as $userId) {
                $perUser[$userId][] = (int) $row->rowid;
            }
        }

        $sent = 0;
        foreach ($perUser as $userId => $ids) {
            $assigned = new User($this->db);
            if ($assigned->fetch($userId) <= 0 || empty($assigned->statut) || !isValidEmail((string) $assigned->email)) {
                continue;
            }
            if ($this->sendLateList($assigned, $ids)) {
                $sent++;
            }
        }
        $this->output = $sent . ' reminder(s) sent' . ($this->error ? ', error: ' . $this->error : '');

        return $this->error ? -1 : 0;
    }

    /**
     * Support users of a ticket (internal contacts « SUPPORTTEC »)
     *
     * @param  int   $ticketId Ticket
     * @return int[]           Ids of the users
     */
    protected function ticketUserIds(int $ticketId): array
    {
        if ($ticketId <= 0) {
            return [];
        }
        $p    = $this->db->prefix();
        $sql  = 'SELECT ec.fk_socpeople FROM ' . $p . 'element_contact AS ec INNER JOIN ' . $p . 'c_type_contact AS tc ON tc.rowid = ec.fk_c_type_contact';
        $sql .= " WHERE ec.element_id = " . $ticketId . " AND tc.element = 'ticket' AND tc.source = 'internal' AND tc.code = 'SUPPORTTEC' LIMIT 50";
        $rows = $this->db->getRows($sql);

        return array_map(static function ($r) {
            return (int) $r->fk_socpeople;
        }, is_array($rows) ? $rows : []);
    }

    /**
     * Template of a mail, in the entity first
     *
     * @param  string     $mail ack, message, done or late
     * @return array|null       [topic, content], null when missing or disabled
     */
    public function template(string $mail): ?array
    {
        $sql  = 'SELECT topic, content FROM ' . $this->db->prefix() . 'c_email_templates';
        $sql .= " WHERE type_template = '" . $this->db->escape(self::TEMPLATE_TYPE) . "' AND label = '" . $this->db->escape(self::MAILS[$mail]['template']) . "'";
        $sql .= " AND active = 1 AND entity IN (0, " . getEntity('c_email_templates') . ') ORDER BY entity DESC, position ASC';
        $row = $this->db->getRow($sql);

        return is_object($row) ? [(string) $row->topic, (string) $row->content] : null;
    }

    /**
     * Keys of the templates of a request (never the nomeref ID)
     *
     * @param  Tmd       $tmd    TMD request
     * @param  Ticket|null $ticket Its ticket
     * @return array<string,string>
     */
    public function substitutions(Tmd $tmd, ?Ticket $ticket): array
    {
        global $langs;

        $thirdparty = '';
        if ($tmd->fk_soc > 0) {
            $soc = new Societe($this->db);
            if ($soc->fetch((int) $tmd->fk_soc) > 0) {
                $thirdparty = (string) $soc->name;
            }
        }
        $db   = $this->db;
        $date = static function ($value, string $format) use ($db) {
            return empty($value) ? '' : dol_print_date(is_numeric($value) ? (int) $value : $db->jdate($value), $format, 'tzserver');
        };

        return array_merge(getCommonSubstitutionArray($langs, 0, null, $tmd), [
            '__TMD_REF__'           => (string) $tmd->ref,
            '__TMD_SUBJECT__'       => (string) $tmd->label,
            '__TMD_DATE_RECEIVED__' => $date($tmd->date_mail, 'dayhour'),
            '__TMD_DATE_DUE__'      => $date($tmd->date_due, 'day'),
            // A request without ticket (made by hand): its own reference, which brings the answer back to it too
            // (TmdMailHandler::findParentRequest())
            '__TICKET_REF__'        => $ticket ? (string) $ticket->ref : (string) $tmd->ref,
            '__THIRDPARTY_NAME__'   => $thirdparty,
            '__SENDER_EMAIL__'      => (string) $tmd->email_from,
            // Public page of the request: where it stands, its documents
            '__TMD_TRACK_ID__'      => $tmd->ensureTrackId(),
            '__TMD_PUBLIC_URL__'    => $tmd->getPublicUrl(),
        ]);
    }

    /**
     * Receipt or done to the sender, in the conversation of his mail, written in the messages of the ticket
     *
     * @param  string $mail          ack or done
     * @param  Tmd    $tmd           TMD request
     * @param  User   $user          User
     * @param  string $autoSubmitted Header Auto-Submitted (RFC 3834: no answer of an absence robot to it)
     * @return bool                  Sent
     */
    protected function sendToSender(string $mail, Tmd $tmd, User $user, string $autoSubmitted): bool
    {
        global $langs;

        if (!getDolGlobalInt(self::MAILS[$mail]['const']) || !isValidEmail((string) $tmd->email_from)) {
            return false;
        }
        $template = $this->template($mail);
        if ($template === null) {
            $this->error = 'Template ' . self::MAILS[$mail]['template'] . ' not found';
            dol_syslog(__METHOD__ . ' ' . $this->error, LOG_WARNING);
            return false;
        }

        $langs->load('dolitmd@dolitmd');
        $ticket = null;
        if ($tmd->fk_ticket > 0) {
            $ticket = new Ticket($this->db);
            if ($ticket->fetch((int) $tmd->fk_ticket) <= 0) {
                $ticket = null;
            }
        }
        [$subject, $html] = $this->compose($template, $this->substitutions($tmd, $ticket));

        $headers = ['Auto-Submitted' => $autoSubmitted, 'X-Auto-Response-Suppress' => 'All'];
        if (!empty($tmd->message_id)) {
            $headers['In-Reply-To'] = '<' . $tmd->message_id . '>';
            $headers['References']  = '<' . $tmd->message_id . '>';
        }
        $ok = $this->send((string) $tmd->email_from, $subject, $html, $headers, $ticket);

        // In the messages of the ticket: what the sender got, and when
        if ($ok && $ticket) {
            $ticket->message = $this->text($html);
            $ticket->private = 0;
            $ticket->createTicketMessage($user, 1, [], [], [], true, 0, $langs->transnoentities($mail == 'ack' ? 'TmdMailAckSent' : 'TmdMailDoneSent', $tmd->email_from));
        }

        return $ok;
    }

    /**
     * Late requests of a person assigned
     *
     * @param  User  $assigned Person assigned
     * @param  int[] $ids      Late requests
     * @return bool            Sent
     */
    protected function sendLateList(User $assigned, array $ids): bool
    {
        global $langs;

        $template = $this->template('late');
        if ($template === null) {
            $this->error = 'Template ' . self::MAILS['late']['template'] . ' not found';
            return false;
        }

        $lines = '';
        foreach ($ids as $id) {
            $tmd = new Tmd($this->db);
            if ($tmd->fetch($id) <= 0) {
                continue;
            }
            $url    = dol_buildpath('/dolitmd/view/tmd/tmd_card.php', 2) . '?id=' . $tmd->id;
            $due    = dol_print_date($this->db->jdate($tmd->date_due), 'day', 'tzserver');
            $lines .= '<li><a href="' . dol_escape_htmltag($url) . '">' . dol_escape_htmltag($tmd->ref) . '</a> — ' . dol_escape_htmltag((string) $tmd->label)
                . ' (' . dol_escape_htmltag($langs->transnoentities('TmdDateDue')) . ' : ' . dol_escape_htmltag($due) . ')</li>';
        }
        if ($lines === '') {
            return false;
        }

        $substitutions = array_merge(getCommonSubstitutionArray($langs), [
            '__TMD_LATE_COUNT__' => (string) count($ids),
            '__TMD_LATE_LIST__'  => '<ul>' . $lines . '</ul>',
            '__TMD_LIST_URL__'   => dol_buildpath('/saturne/view/saturne_list.php', 2) . '?object_type=tmd&mainmenu=dolitmd',
            '__USER_FIRSTNAME__' => (string) $assigned->firstname,
        ]);
        [$subject, $html] = $this->compose($template, $substitutions, ['__TMD_LATE_LIST__']);

        return $this->send((string) $assigned->email, $subject, $html, ['Auto-Submitted' => 'auto-generated', 'X-Auto-Response-Suppress' => 'All']);
    }

    /**
     * Subject and HTML of a template, keys replaced (escaped in the HTML, except the keys already in HTML)
     *
     * @param  array    $template      [topic, content]
     * @param  array    $substitutions Keys
     * @param  string[] $htmlKeys      Keys whose value is HTML already
     * @return string[]                [subject, html]
     */
    protected function compose(array $template, array $substitutions, array $htmlKeys = []): array
    {
        global $langs;

        $escaped = [];
        foreach ($substitutions as $key => $value) {
            $escaped[$key] = in_array($key, $htmlKeys, true) ? (string) $value : dol_escape_htmltag((string) $value);
        }
        $subject = trim(preg_replace('/[\r\n]+/', ' ', make_substitutions($template[0], $substitutions, $langs)));
        $html    = make_substitutions($template[1], $escaped, $langs);
        if (!dol_textishtml($html)) {
            $html = dol_nl2br($html);
        }

        return [$subject, $html];
    }

    /**
     * Text version of the HTML of a mail
     *
     * @param  string $html HTML
     * @return string
     */
    protected function text(string $html): string
    {
        $text = preg_replace('/<(br|\/p|\/div|\/li|\/tr|\/h[1-6])\b[^>]*>/i', "\n", $html);

        return trim(html_entity_decode(dol_string_nohtmltag($text, 0), ENT_QUOTES | ENT_HTML5, 'UTF-8'));
    }

    /**
     * Send: by the server of the TMD mailbox (Inbox), else by the mail setup of Dolibarr
     *
     * @param  string               $to      Recipient
     * @param  string               $subject Subject
     * @param  string               $html    HTML
     * @param  array<string,string> $headers More headers
     * @param  Ticket|null          $ticket  Ticket (track id of Dolibarr)
     * @return bool
     */
    protected function send(string $to, string $subject, string $html, array $headers, ?Ticket $ticket = null): bool
    {
        global $conf;

        $this->error = '';
        $text        = $this->text($html);
        $page        = '<!DOCTYPE html><html><head><meta charset="UTF-8"></head><body>' . $html . '</body></html>';

        require_once __DIR__ . '/tmdmailhandler.class.php';
        $mailbox = TmdMailHandler::configuredMailbox($this->db);
        if ($mailbox !== null && (int) $mailbox->status === 1 && dol_include_once('/inbox/class/inboxsystemmail.class.php') && dol_include_once('/inbox/class/inboxaccount.class.php') && class_exists('InboxSystemMail')) {
            $account = new InboxAccount($this->db);
            if ($account->fetch((int) $mailbox->rowid) > 0) {
                $boundary = 'dolitmd' . md5(uniqid('', true));
                [$contentType, $body] = InboxSystemMail::multipart('multipart/alternative', [
                    InboxSystemMail::part('text/plain; charset=UTF-8', $text),
                    InboxSystemMail::part('text/html; charset=UTF-8', $page),
                ], $boundary);
                $from   = ['email' => (string) $account->email, 'name' => (string) ($account->label && $account->label !== $account->email ? $account->label : getDolGlobalString('MAIN_INFO_SOCIETE_NOM'))];
                $sender = new InboxSystemMail();
                if ($sender->send($this->db, $account, $from, $to, $subject, $contentType, $body, $headers)) {
                    return true;
                }
                $this->error = $sender->errorCode . ' ' . $sender->error;
                dol_syslog(__METHOD__ . ' mail to ' . $to . ' not sent by the TMD mailbox: ' . $this->error, LOG_WARNING);
                return false;
            }
        }

        // No TMD mailbox: the mail setup of Dolibarr
        require_once DOL_DOCUMENT_ROOT . '/core/class/CMailFile.class.php';
        $more = '';
        foreach (['Auto-Submitted', 'X-Auto-Response-Suppress'] as $name) {
            if (!empty($headers[$name])) {
                $more .= $name . ': ' . $headers[$name] . "\r\n";
            }
        }
        $from = getDolGlobalString('MAIN_MAIL_EMAIL_FROM');
        $mail = new CMailFile($subject, $to, $from, $page, [], [], [], '', '', 0, 1, '', '', $ticket ? 'tic' . $ticket->id : '', $more, 'standard', '', '', trim($headers['In-Reply-To'] ?? '', '<>'), trim($headers['References'] ?? '', '<>'));
        if ($mail->sendfile()) {
            return true;
        }
        $this->error = (string) $mail->error;
        dol_syslog(__METHOD__ . ' mail to ' . $to . ' not sent: ' . $this->error, LOG_WARNING);

        return false;
    }

    /**
     * The classes of Inbox that send a mail written here are there, in the version installed (they change from a version
     * to another): else the mail setup of Dolibarr sends
     *
     * @return bool
     */
    protected function inboxCanSend(): bool
    {
        foreach (['/inbox/class/inboxaccount.class.php', '/inbox/class/inboxmailbuilder.class.php', '/inbox/class/connector/inboxconnectorfactory.class.php'] as $file) {
            if (!dol_include_once($file)) {
                return false;
            }
        }
        dol_include_once('/inbox/class/inboxcomposer.class.php');
        if (!class_exists('InboxAccount') || !class_exists('InboxMailBuilder') || !method_exists('InboxMailBuilder', 'build') || !class_exists('InboxConnectorFactory') || !method_exists('InboxConnectorFactory', 'forAccount')) {
            dol_syslog(__METHOD__ . ' classes of Inbox to send missing in its installed version: the mail setup of Dolibarr sends', LOG_WARNING);
            return false;
        }
        foreach (['from', 'to', 'cc', 'subject', 'html', 'inReplyTo', 'references', 'attachments'] as $property) {
            if (!property_exists('InboxMailBuilder', $property)) {
                dol_syslog(__METHOD__ . ' InboxMailBuilder::$' . $property . ' missing in the installed version of Inbox: the mail setup of Dolibarr sends', LOG_WARNING);
                return false;
            }
        }

        return true;
    }

    /**
     * Send to several people with attachments: by the TMD mailbox as a mail written in Inbox (a copy in its Sent
     * folder, the conversation of the request), else by the mail setup of Dolibarr
     *
     * @param  array<int,array{email:string,name:string}>                     $to          Recipients
     * @param  array<int,array{email:string,name:string}>                     $cc          Copies
     * @param  string                                                         $subject     Subject
     * @param  string                                                         $html        HTML
     * @param  string                                                         $inReplyTo   Message-ID of the mail of the request, without the angle brackets
     * @param  array<int,array{name:string,size:int,date:int,fullname:string}> $attachments Files
     * @param  Ticket|null                                                    $ticket      Ticket (track id of Dolibarr)
     * @return bool
     */
    protected function sendFull(array $to, array $cc, string $subject, string $html, string $inReplyTo, array $attachments, ?Ticket $ticket): bool
    {
        global $langs;

        $this->error = '';
        if (getDolGlobalString('MAIN_DISABLE_ALL_MAILS')) {
            $this->error = $langs->transnoentities('TmdMailsDisabled');
            return false;
        }

        require_once __DIR__ . '/tmdmailhandler.class.php';
        $mailbox = TmdMailHandler::configuredMailbox($this->db);
        if ($mailbox !== null && (int) $mailbox->status === 1 && $this->inboxCanSend()) {
            $account = new InboxAccount($this->db);
            if ($account->fetch((int) $mailbox->rowid) > 0) {
                $builder          = new InboxMailBuilder();
                $builder->from    = ['email' => (string) $account->email, 'name' => (string) ($account->label && $account->label !== $account->email ? $account->label : getDolGlobalString('MAIN_INFO_SOCIETE_NOM'))];
                $builder->to      = $to;
                $builder->cc      = $cc;
                $builder->subject = $subject;
                $builder->html    = $html;
                if ($inReplyTo !== '') {
                    $builder->inReplyTo  = $inReplyTo;
                    $builder->references = [$inReplyTo];
                }
                foreach ($attachments as $file) {
                    $builder->attachments[] = ['path' => $file['fullname'], 'name' => $file['name'], 'mime' => dol_mimetype($file['name'])];
                }
                $built = $builder->build();
                if (!is_array($built) || !isset($built['wire'], $built['raw']) || empty($built['recipients'])) {
                    $this->error = (string) $builder->error;
                    return false;
                }

                $error     = '';
                $connector = InboxConnectorFactory::forAccount($this->db, $account, $error);
                if (!is_object($connector) || !method_exists($connector, 'send')) {
                    $this->error = $error !== '' ? $error : 'No connection to the TMD mailbox';
                    return false;
                }
                if (method_exists($connector, 'can') && defined('InboxConnectorInterface::CAP_SEND') && !$connector->can(InboxConnectorInterface::CAP_SEND)) {
                    $connector->disconnect();
                    $this->error = 'No sending server for the TMD mailbox';
                    return false;
                }
                if (!$connector->send($built['wire'], ['from' => $builder->from['email'], 'recipients' => $built['recipients']])) {
                    $this->error = method_exists($connector, 'getError') ? (string) $connector->getError() : 'Mail refused by the server of the TMD mailbox';
                    $connector->disconnect();
                    dol_syslog(__METHOD__ . ' mail not sent by the TMD mailbox: ' . $this->error, LOG_WARNING);
                    return false;
                }
                // The copy in Sent, as for a mail written in Inbox (unless the sending server files it itself)
                $sentByServer = method_exists($account, 'sentCopyByServer') && $account->sentCopyByServer();
                if (!$sentByServer && class_exists('InboxComposer') && method_exists('InboxComposer', 'roleFolder') && method_exists($connector, 'importRaw')) {
                    $sent = (string) (new InboxComposer($this->db))->roleFolder($account, 'sent');
                    if ($sent !== '' && $connector->importRaw($sent, $built['raw'], ['\\Seen']) === null) {
                        dol_syslog(__METHOD__ . ' copy in Sent failed', LOG_WARNING);
                    }
                }
                $connector->disconnect();

                return true;
            }
        }

        // No TMD mailbox: the mail setup of Dolibarr
        require_once DOL_DOCUMENT_ROOT . '/core/class/CMailFile.class.php';
        $page = '<!DOCTYPE html><html><head><meta charset="UTF-8"></head><body>' . $html . '</body></html>';
        $mail = new CMailFile($subject, self::addressesText($to), getDolGlobalString('MAIN_MAIL_EMAIL_FROM'), $page, array_column($attachments, 'fullname'), array_map('dol_mimetype', array_column($attachments, 'name')),
            array_column($attachments, 'name'), self::addressesText($cc), '', 0, 1, '', '', $ticket ? 'tic' . $ticket->id : '', '', 'standard', '', '', $inReplyTo, $inReplyTo);
        if ($mail->sendfile()) {
            return true;
        }
        $this->error = (string) $mail->error;
        dol_syslog(__METHOD__ . ' mail not sent: ' . $this->error, LOG_WARNING);

        return false;
    }
}
