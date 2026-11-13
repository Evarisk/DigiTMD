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
 * \file    core/tpl/dolitmd_thirdparty_create_modal.tpl.php
 * \ingroup dolitmd
 * \brief   Modal creating the thirdparty of an unknown sender, opened by the button « + » of its address
 *          (js/modules/tmd.js fills it from the button and saves it by ajax/tmd.php)
 *
 * Printed by the hook printCommonFooter under the list, by the card of a request without thirdparty.
 * The main fields of the creation of a thirdparty, with the names and the helpers of societe/card.php (the zip code
 * fills the town and the country as there, so the ids zipcode, town and selectcountry_id are kept).
 * Expects: $db, $langs, $mysoc, $user
 */

// Protection to avoid direct call of template
if (empty($conf) || !is_object($conf)) {
    print 'Error, template page can be called as an URL';
    exit;
}

require_once DOL_DOCUMENT_ROOT . '/core/class/html.form.class.php';
require_once DOL_DOCUMENT_ROOT . '/core/class/html.formcompany.class.php';

$langs->loadLangs(['companies', 'dict']);

$socForm        = new Form($db);
$socFormCompany = new FormCompany($db);
$socCountryCode = (string) $mysoc->country_code;

// Professional ids: the SIRET (or what stands for it in the country), and any one the setup makes mandatory
$socIdProfs = [];
for ($i = 1; $i <= 6; $i++) {
    $socIdProfLabel     = $langs->transcountry('ProfId' . $i, $socCountryCode);
    $socIdProfMandatory = getDolGlobalString('SOCIETE_IDPROF' . $i . '_MANDATORY');
    if ($socIdProfLabel !== '-' && ($i == 2 || $socIdProfMandatory)) {
        $socIdProfs[$i] = ['label' => $socIdProfLabel, 'mandatory' => !empty($socIdProfMandatory)];
    }
}
$socEmailMandatory = getDolGlobalString('SOCIETE_EMAIL_MANDATORY');
$socVatMandatory   = getDolGlobalString('SOCIETE_VAT_INTRA_MANDATORY');
$socCustomerType   = $socFormCompany->selectProspectCustomerType(1, 'client', 'dolitmd-soc-client');

// Tags of the customers and prospects, as on the card of a thirdparty (the button that manages them there opens a popup
// the modal would cover: left out)
$socTags = '';
if (isModEnabled('category') && $user->hasRight('categorie', 'lire')) {
    require_once DOL_DOCUMENT_ROOT . '/categories/class/categorie.class.php';
    $langs->load('categories');
    $socTags = $socForm->multiselectarray('custcats', $socForm->select_all_categories(Categorie::TYPE_CUSTOMER, '', '', 64, 0, 3), [], 0, 0, '', 0, 0);
}
?>
<?php // Saturne closes a modal on a click on its backdrop, a selection released there included: closed by its buttons only ?>
<div class="wpeo-modal dolitmd-soc-modal modal-close-only-with-button" id="dolitmd-soc-modal"
     data-url="<?php echo dol_escape_htmltag(dol_buildpath('/dolitmd/ajax/tmd.php', 1)); ?>"
     data-error="<?php echo dol_escape_htmltag($langs->transnoentities('TmdThirdpartyError')); ?>">
    <div class="modal-container">
        <div class="modal-header">
            <h2 class="modal-title"><i class="fas fa-building pictofixedwidth"></i><?php echo dol_escape_htmltag($langs->trans('TmdCreateThirdparty')); ?></h2>
            <div class="modal-close"><i class="fas fa-times"></i></div>
        </div>
        <div class="modal-content">
            <form class="dolitmd-soc-form" id="dolitmd-soc-form" autocomplete="off">
                <div class="dolitmd-soc-field dolitmd-soc-field--full">
                    <label class="fieldrequired" for="dolitmd-soc-name"><?php echo dol_escape_htmltag($langs->trans('ThirdPartyName')); ?></label>
                    <input type="text" id="dolitmd-soc-name" class="dolitmd-soc-name" name="name" maxlength="128" required>
                </div>
                <?php // Type and tags side by side, the one alone across the modal ?>
                <?php if ($socCustomerType !== '') { ?>
                    <div class="dolitmd-soc-field<?php echo $socTags === '' ? ' dolitmd-soc-field--full' : ''; ?>">
                        <label for="dolitmd-soc-client"><?php echo dol_escape_htmltag($langs->trans('ProspectCustomer')); ?></label>
                        <?php echo $socCustomerType; ?>
                    </div>
                <?php } ?>
                <?php if ($socTags !== '') { ?>
                    <div class="dolitmd-soc-field<?php echo $socCustomerType === '' ? ' dolitmd-soc-field--full' : ''; ?>">
                        <label for="custcats"><?php echo dol_escape_htmltag($langs->trans('CustomersProspectsCategoriesShort')); ?></label>
                        <?php echo $socTags; ?>
                    </div>
                <?php } ?>
                <div class="dolitmd-soc-field dolitmd-soc-field--full">
                    <label for="dolitmd-soc-address"><?php echo dol_escape_htmltag($langs->trans('Address')); ?></label>
                    <textarea id="dolitmd-soc-address" name="address" rows="2"></textarea>
                </div>
                <div class="dolitmd-soc-field">
                    <label for="zipcode"><?php echo dol_escape_htmltag($langs->trans('Zip')); ?></label>
                    <?php echo $socFormCompany->select_ziptown('', 'zipcode', ['town', 'selectcountry_id', 'state_id']); ?>
                </div>
                <div class="dolitmd-soc-field">
                    <label for="town"><?php echo dol_escape_htmltag($langs->trans('Town')); ?></label>
                    <?php echo $socFormCompany->select_ziptown('', 'town', ['zipcode', 'selectcountry_id', 'state_id']); ?>
                </div>
                <?php // The country closes the postal address, under the town ?>
                <div class="dolitmd-soc-field dolitmd-soc-field--full">
                    <label for="selectcountry_id"><?php echo dol_escape_htmltag($langs->trans('Country')); ?></label>
                    <?php echo $socForm->select_country((string) $mysoc->country_id, 'country_id', '', 0, ''); ?>
                </div>
                <div class="dolitmd-soc-field">
                    <label<?php echo $socEmailMandatory ? ' class="fieldrequired"' : ''; ?> for="dolitmd-soc-email"><?php echo dol_escape_htmltag($langs->trans('EMail')); ?></label>
                    <input type="email" id="dolitmd-soc-email" class="dolitmd-soc-email" name="email" maxlength="128"<?php echo $socEmailMandatory ? ' required' : ''; ?>>
                </div>
                <div class="dolitmd-soc-field">
                    <label for="dolitmd-soc-phone"><?php echo dol_escape_htmltag($langs->trans('Phone')); ?></label>
                    <input type="tel" id="dolitmd-soc-phone" name="phone" maxlength="20">
                </div>
                <?php foreach ($socIdProfs as $i => $socIdProf) { ?>
                    <div class="dolitmd-soc-field">
                        <label<?php echo $socIdProf['mandatory'] ? ' class="fieldrequired"' : ''; ?> for="idprof<?php echo $i; ?>"><?php echo dol_escape_htmltag($socIdProf['label']); ?></label>
                        <?php echo $socFormCompany->get_input_id_prof($i, 'idprof' . $i, '', $socCountryCode, ''); ?>
                    </div>
                <?php } ?>
                <div class="dolitmd-soc-field">
                    <label<?php echo $socVatMandatory ? ' class="fieldrequired"' : ''; ?> for="dolitmd-soc-vat"><?php echo dol_escape_htmltag($langs->trans('VATIntra')); ?></label>
                    <input type="text" id="dolitmd-soc-vat" name="tva_intra" maxlength="20">
                </div>
                <p class="dolitmd-soc-help opacitymedium"><?php echo dol_escape_htmltag($langs->trans('TmdCreateThirdpartyInfo')); ?></p>
                <div class="dolitmd-soc-error hidden" role="alert">
                    <span class="dolitmd-soc-error-text"></span>
                    <button type="button" class="wpeo-button button-grey button-size-small no-load dolitmd-soc-link hidden"><i class="fas fa-link"></i>&nbsp;<span class="dolitmd-soc-link-label"></span></button>
                </div>
            </form>
        </div>
        <div class="modal-footer">
            <button type="button" class="wpeo-button button-grey modal-close"><?php echo dol_escape_htmltag($langs->trans('Cancel')); ?></button>
            <?php // no-load: the loader Saturne puts on a submit button goes away with the page only, the request is sent by AJAX ?>
            <button type="submit" form="dolitmd-soc-form" class="wpeo-button button-blue no-load dolitmd-soc-save"><i class="fas fa-check"></i>&nbsp;<?php echo dol_escape_htmltag($langs->trans('TmdCreateThirdparty')); ?></button>
        </div>
    </div>
</div>
