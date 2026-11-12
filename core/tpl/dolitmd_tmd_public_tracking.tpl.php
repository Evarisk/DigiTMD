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
 * \file    core/tpl/dolitmd_tmd_public_tracking.tpl.php
 * \ingroup dolitmd
 * \brief   Screens of the public page of a TMD request: the form of the tracking number, then where the request stands
 *          and its documents. Never the nomeref ID nor anything internal
 *
 * Rendered by public/tmd/tracking.php (inherits its scope).
 * Expects: $object, $found, $error, $trackId, $ticketRef, $files, $contact, $conf, $db, $langs, $mysoc
 */

// Logo of the company: the page comes from a link in a mail, it says who it is from (viewimage.php serves the logos
// without login); the name of the company when the logo is missing on the disk
$logoFile = '';
$logoDir  = $conf->mycompany->multidir_output[$conf->entity] ?? ($conf->mycompany->dir_output ?? '');
foreach ([!empty($mysoc->logo_small) ? 'logos/thumbs/' . $mysoc->logo_small : '', !empty($mysoc->logo) ? 'logos/' . $mysoc->logo : ''] as $candidate) {
    if ($candidate !== '' && $logoDir !== '' && is_file($logoDir . '/' . $candidate)) {
        $logoFile = $candidate;
        break;
    }
}
$companyName = (string) ($mysoc->name ?? '');

$pageUrl  = $_SERVER['PHP_SELF'];
$entityId = (int) $conf->entity;
$date     = static function ($value, string $format) use ($db): string {
    if (empty($value)) {
        return '';
    }
    return dol_print_date(is_numeric($value) ? (int) $value : $db->jdate($value), $format, 'tzserver');
};
// Size of a file as a customer reads it: bytes, then KB, then MB
$size = static function (int $bytes) use ($langs): string {
    if ($bytes < 1024) {
        return $bytes . ' ' . $langs->trans('b');
    }
    if ($bytes < 1048576) {
        return round($bytes / 1024) . ' ' . $langs->trans('Kb');
    }
    return price(round($bytes / 1048576, 1), 0, $langs, 0, 0, 1) . ' ' . $langs->trans('Mb');
};
?>
<div class="dolitmd-public">
    <div class="dolitmd-public__brand">
        <?php if ($logoFile !== '') : ?>
            <img class="dolitmd-public__logo" src="<?php echo dol_escape_htmltag(DOL_URL_ROOT . '/viewimage.php?modulepart=mycompany&entity=' . $entityId . '&file=' . urlencode($logoFile)); ?>" alt="<?php echo dol_escape_htmltag($companyName); ?>">
        <?php else : ?>
            <span class="dolitmd-public__company"><?php echo dol_escape_htmltag($companyName); ?></span>
        <?php endif; ?>
    </div>

    <div class="public-card__container dolitmd-public__card">
    <?php if (!$found) : ?>
        <?php // Screen « form »: the tracking number, typed or pasted ?>
        <div class="dolitmd-public__intro">
            <span class="dolitmd-public__icon"><i class="fas fa-search-location"></i></span>
            <h1 class="dolitmd-public__title"><?php echo $langs->trans('TmdPublicTitle'); ?></h1>
            <p class="dolitmd-public__lead"><?php echo $langs->trans('TmdPublicFormHelp'); ?></p>
        </div>

        <?php if ($error) : ?>
            <div class="dolitmd-public__notice dolitmd-public__notice--error" role="alert">
                <i class="fas fa-exclamation-circle"></i>
                <span><?php echo $langs->trans('TmdPublicNotFound'); ?></span>
            </div>
        <?php endif; ?>

        <form class="dolitmd-public__search" method="GET" action="<?php echo dol_escape_htmltag($pageUrl); ?>">
            <input type="hidden" name="entity" value="<?php echo $entityId; ?>">
            <label class="dolitmd-public__label" for="track_id"><?php echo $langs->trans('TmdTrackId'); ?></label>
            <div class="dolitmd-public__search-row">
                <input class="dolitmd-public__input" type="text" id="track_id" name="track_id" value="<?php echo dol_escape_htmltag($trackId); ?>" placeholder="XXXX-XXXX-XXXX-XXXX" maxlength="40" autocomplete="off" autocapitalize="characters" spellcheck="false" required autofocus>
                <button type="submit" class="dolitmd-public__button"><?php echo $langs->trans('TmdPublicFollow'); ?><i class="fas fa-arrow-right"></i></button>
            </div>
        </form>
    <?php else : ?>
        <?php
        // Screen « request »: its state, its steps, its documents
        $state   = (int) $object->status;
        $isDone  = !empty($object->done);
        $stateKeys = [Tmd::STATUS_PENDING => 'pending', Tmd::STATUS_DONE => 'done', Tmd::STATUS_INVOICED => 'invoiced'];
        $stateKey  = $stateKeys[$state] ?? 'pending';

        // The deadline is told while it is ahead only
        $due      = empty($object->date_due) ? 0 : (is_numeric($object->date_due) ? (int) $object->date_due : (int) $db->jdate($object->date_due));
        $showDue  = !$isDone && $due > 0 && $due >= dol_get_first_hour(dol_now());
        $received = $date($object->date_mail ?: $object->date_creation, 'dayhour');
        $doneDate = $date($object->date_done, 'day');
        $reference = $ticketRef ?: (string) $object->ref;
        ?>
        <div class="dolitmd-public__head">
            <div class="dolitmd-public__heading">
                <span class="dolitmd-public__eyebrow"><?php echo $langs->trans('TmdPublicRequest', $reference); ?></span>
                <h1 class="dolitmd-public__title"><?php echo dol_escape_htmltag((string) ($object->label ?: $langs->transnoentities('TmdPublicNoSubject'))); ?></h1>
                <?php if ($received !== '') : ?>
                    <span class="dolitmd-public__meta"><?php echo $langs->trans('TmdPublicReceivedOn', $received); ?></span>
                <?php endif; ?>
            </div>
            <span class="dolitmd-public__state dolitmd-public__state--<?php echo $stateKey; ?>">
                <i class="fas <?php echo $isDone ? 'fa-check-circle' : 'fa-hourglass-half'; ?>"></i><?php echo $langs->trans('TmdPublicState' . ucfirst($stateKey)); ?>
            </span>
        </div>

        <ol class="dolitmd-public__steps">
            <li class="dolitmd-public__step is-done">
                <span class="dolitmd-public__dot"><i class="fas fa-check"></i></span>
                <span class="dolitmd-public__step-label"><?php echo $langs->trans('TmdPublicStepReceived'); ?></span>
                <span class="dolitmd-public__step-date"><?php echo $date($object->date_mail ?: $object->date_creation, 'day'); ?></span>
            </li>
            <li class="dolitmd-public__step <?php echo $isDone ? 'is-done' : 'is-current'; ?>">
                <span class="dolitmd-public__dot"><i class="fas <?php echo $isDone ? 'fa-check' : 'fa-cog'; ?>"></i></span>
                <span class="dolitmd-public__step-label"><?php echo $langs->trans('TmdPublicStepProcessing'); ?></span>
                <span class="dolitmd-public__step-date"><?php echo $showDue ? $langs->trans('TmdPublicDueBefore', $date($due, 'day')) : ''; ?></span>
            </li>
            <li class="dolitmd-public__step <?php echo $isDone ? 'is-done' : 'is-todo'; ?>">
                <span class="dolitmd-public__dot"><i class="fas <?php echo $isDone ? 'fa-check' : 'fa-flag-checkered'; ?>"></i></span>
                <span class="dolitmd-public__step-label"><?php echo $langs->trans('TmdPublicStepDone'); ?></span>
                <span class="dolitmd-public__step-date"><?php echo $doneDate; ?></span>
            </li>
        </ol>

        <dl class="dolitmd-public__facts">
            <div class="dolitmd-public__fact">
                <dt><?php echo $langs->trans('TmdTrackId'); ?></dt>
                <dd class="dolitmd-public__mono"><?php echo dol_escape_htmltag((string) $object->track_id); ?></dd>
            </div>
            <?php if ($ticketRef !== '') : ?>
                <div class="dolitmd-public__fact">
                    <dt><?php echo $langs->trans('TmdPublicReference'); ?></dt>
                    <dd><?php echo dol_escape_htmltag($ticketRef); ?></dd>
                </div>
            <?php endif; ?>
            <?php if ($isDone && $doneDate !== '') : ?>
                <div class="dolitmd-public__fact">
                    <dt><?php echo $langs->trans('TmdPublicDoneOn'); ?></dt>
                    <dd><?php echo $doneDate; ?></dd>
                </div>
            <?php elseif ($showDue) : ?>
                <div class="dolitmd-public__fact">
                    <dt><?php echo $langs->trans('TmdPublicDue'); ?></dt>
                    <dd><?php echo $date($due, 'day'); ?></dd>
                </div>
            <?php endif; ?>
        </dl>

        <section class="dolitmd-public__docs">
            <h2 class="dolitmd-public__subtitle">
                <?php echo $langs->trans('TmdPublicDocuments'); ?>
                <?php if (!empty($files)) : ?>
                    <span class="dolitmd-public__count"><?php echo count($files); ?></span>
                <?php endif; ?>
            </h2>
            <?php if (empty($files)) : ?>
                <p class="dolitmd-public__empty"><i class="far fa-folder-open"></i><?php echo $langs->trans('TmdPublicNoDocument'); ?></p>
            <?php else : ?>
                <ul class="dolitmd-public__files">
                    <?php foreach ($files as $file) :
                        $fileUrl = dol_buildpath('/dolitmd/public/tmd/document.php', 1) . '?track_id=' . urlencode((string) $object->track_id) . '&entity=' . $entityId . '&file=' . urlencode($file['name']);
                        ?>
                        <li class="dolitmd-public__file">
                            <span class="dolitmd-public__file-icon"><?php echo img_mime($file['name']); ?></span>
                            <span class="dolitmd-public__file-info">
                                <span class="dolitmd-public__file-name"><?php echo dol_escape_htmltag($file['name']); ?></span>
                                <span class="dolitmd-public__file-meta"><?php echo $size($file['size']) . ' · ' . $date($file['date'], 'day'); ?></span>
                            </span>
                            <a class="dolitmd-public__button dolitmd-public__button--light" href="<?php echo dol_escape_htmltag($fileUrl); ?>" target="_blank" rel="noopener">
                                <i class="fas fa-download"></i><span class="dolitmd-public__button-text"><?php echo $langs->trans('Download'); ?></span>
                            </a>
                        </li>
                    <?php endforeach; ?>
                </ul>
            <?php endif; ?>
        </section>

        <?php if ($contact !== '') :
            $mailto = 'mailto:' . $contact . '?subject=' . rawurlencode('Re: ' . ($object->label ?: $reference) . ($ticketRef !== '' ? ' [' . $ticketRef . ']' : ''));
            ?>
            <div class="dolitmd-public__contact">
                <i class="far fa-envelope"></i>
                <span class="dolitmd-public__contact-body">
                    <span><?php echo $langs->trans('TmdPublicContact', $reference); ?></span>
                    <a class="dolitmd-public__contact-link" href="<?php echo dol_escape_htmltag($mailto); ?>"><?php echo dol_escape_htmltag($contact); ?></a>
                </span>
            </div>
        <?php endif; ?>
    <?php endif; ?>
    </div>

    <?php if ($logoFile !== '' && $companyName !== '') : ?>
        <p class="dolitmd-public__footer"><?php echo dol_escape_htmltag($companyName); ?></p>
    <?php endif; ?>
</div>
