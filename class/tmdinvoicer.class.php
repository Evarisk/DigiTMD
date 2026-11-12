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
 * \file    class/tmdinvoicer.class.php
 * \ingroup dolitmd
 * \brief   Invoices of the TMD requests: one draft invoice per thirdparty, one line per request
 */

// Load Dolibarr libraries
require_once DOL_DOCUMENT_ROOT . '/compta/facture/class/facture.class.php';
require_once DOL_DOCUMENT_ROOT . '/product/class/product.class.php';
require_once DOL_DOCUMENT_ROOT . '/societe/class/societe.class.php';

// Load DoliTMD libraries
require_once __DIR__ . '/tmd.class.php';

/**
 * Class TmdInvoicer
 */
class TmdInvoicer
{
    /**
     * @var DoliDB Database handler
     */
    public $db;

    /**
     * @var string[] Invoices made (HTML links)
     */
    public $invoices = [];

    /**
     * @var string[] Requests left aside, with why
     */
    public $skipped = [];

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
     * One draft invoice per thirdparty for the requests given, one line per request with the service of the setup. The
     * requests become invoiced (their ticket closes) and keep their invoice; a request already invoiced on an invoice,
     * deleted or without thirdparty is left aside.
     *
     * @param  User  $user User invoicing
     * @param  int[] $ids  TMD requests
     * @return int         Invoices made, < 0 when nothing could start (setup, rights)
     */
    public function createInvoices(User $user, array $ids): int
    {
        global $langs, $mysoc;

        $langs->loadLangs(['bills', 'dolitmd@dolitmd']);
        if (!isModEnabled('invoice') || !$user->hasRight('facture', 'creer')) {
            $this->errors[] = $langs->transnoentities('NotEnoughPermissions');
            return -1;
        }
        $product = new Product($this->db);
        if (getDolGlobalInt('DOLITMD_INVOICE_PRODUCT') <= 0 || $product->fetch(getDolGlobalInt('DOLITMD_INVOICE_PRODUCT')) <= 0) {
            $this->errors[] = $langs->transnoentities('TmdInvoiceNoProduct');
            return -1;
        }

        // Requests of each thirdparty
        $perThirdparty = [];
        foreach (array_unique(array_map('intval', $ids)) as $id) {
            $tmd = new Tmd($this->db);
            if ($id <= 0 || $tmd->fetch($id) <= 0 || (int) $tmd->status === Tmd::STATUS_DELETED) {
                continue;
            }
            if ($tmd->fk_facture > 0) {
                $this->skipped[] = $tmd->ref . ' : ' . $langs->transnoentities('TmdInvoiceAlready');
                continue;
            }
            if (empty($tmd->fk_soc)) {
                $this->skipped[] = $tmd->ref . ' : ' . $langs->transnoentities('TmdInvoiceNoThirdparty');
                continue;
            }
            $perThirdparty[(int) $tmd->fk_soc][] = $tmd;
        }

        $made = 0;
        foreach ($perThirdparty as $socId => $requests) {
            if ($this->invoiceThirdparty($user, $product, (int) $socId, $requests, $mysoc)) {
                $made++;
            }
        }

        return $made;
    }

    /**
     * The draft invoice of a thirdparty
     *
     * @param  User    $user     User
     * @param  Product $product  Service invoiced
     * @param  int     $socId    Thirdparty
     * @param  Tmd[]   $requests Its requests
     * @param  Societe $mysoc    Company
     * @return bool
     */
    protected function invoiceThirdparty(User $user, Product $product, int $socId, array $requests, $mysoc): bool
    {
        global $langs;

        $thirdparty = new Societe($this->db);
        if ($thirdparty->fetch($socId) <= 0) {
            return false;
        }
        $price = $product->getSellPrice($mysoc, $thirdparty);

        $this->db->begin();

        $invoice                    = new Facture($this->db);
        $invoice->socid             = $socId;
        $invoice->type              = Facture::TYPE_STANDARD;
        $invoice->date              = dol_now();
        $invoice->cond_reglement_id = $thirdparty->cond_reglement_id ?: 0;
        $invoice->mode_reglement_id = $thirdparty->mode_reglement_id ?: 0;
        if ($invoice->create($user) <= 0) {
            $this->errors[] = $thirdparty->name . ' : ' . $invoice->error . ' ' . implode(', ', (array) $invoice->errors);
            $this->db->rollback();
            return false;
        }

        foreach ($requests as $tmd) {
            $desc = $tmd->ref . ' — ' . (string) $tmd->label;
            if ($tmd->fk_ticket > 0) {
                $ticketRef = $this->db->getRow('SELECT ref FROM ' . $this->db->prefix() . 'ticket WHERE rowid = ' . ((int) $tmd->fk_ticket));
                if (is_object($ticketRef)) {
                    $desc .= ' (' . $langs->transnoentities('Ticket') . ' ' . $ticketRef->ref . ')';
                }
            }
            $result = $invoice->addline($desc, (float) $price['pu_ht'], 1, (float) $price['tva_tx'], 0, 0, (int) $product->id, 0, '', '', 0, 0, 0, 'HT', 0, (int) $product->type);
            if ($result <= 0) {
                $this->errors[] = $tmd->ref . ' : ' . $invoice->error;
                $this->db->rollback();
                return false;
            }
            if ($tmd->fk_ticket > 0) {
                $invoice->add_object_linked('ticket', (int) $tmd->fk_ticket);
            }
        }

        // The requests are invoiced and keep their invoice (their ticket closes, the sender is told when it is set up)
        foreach ($requests as $tmd) {
            $tmd->fk_facture = $invoice->id;
            if ($tmd->setState($user, Tmd::STATUS_INVOICED) <= 0) {
                $this->errors[] = $tmd->ref . ' : ' . $tmd->error;
                $this->db->rollback();
                return false;
            }
        }

        $this->db->commit();
        $invoice->fetch($invoice->id);
        $this->invoices[] = $invoice->getNomUrl(1) . ' — ' . $thirdparty->getNomUrl(1) . ' (' . count($requests) . ')';

        return true;
    }

    /**
     * An invoice deleted: its requests are done again, without invoice (trigger BILL_DELETE)
     *
     * @param  User $user      User deleting
     * @param  int  $invoiceId Invoice deleted
     * @return int             Requests changed
     */
    public function forgetInvoice(User $user, int $invoiceId): int
    {
        $rows = $this->db->getRows('SELECT rowid FROM ' . $this->db->prefix() . 'dolitmd_tmd WHERE fk_facture = ' . $invoiceId . ' LIMIT 1000');
        $done = 0;
        foreach (is_array($rows) ? $rows : [] as $row) {
            $tmd = new Tmd($this->db);
            if ($tmd->fetch((int) $row->rowid) <= 0) {
                continue;
            }
            $tmd->fk_facture = null;
            if ($tmd->setState($user, Tmd::STATUS_DONE) > 0) {
                $done++;
            }
        }

        return $done;
    }
}
