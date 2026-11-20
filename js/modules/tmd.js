/* Copyright (C) 2026 EVARISK <technique@evarisk.com>
 *
 * This program is free software: you can redistribute it and/or modify
 * it under the terms of the GNU General Public License as published by
 * the Free Software Foundation, either version 3 of the License, or
 * (at your option) any later version.
 *
 * This program is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
 * GNU General Public License for more details.
 *
 * You should have received a copy of the GNU General Public License
 * along with this program.  If not, see <https://www.gnu.org/licenses/>.
 */

/**
 * \file    js/modules/tmd.js
 * \ingroup dolitmd
 * \brief   State and pictos done / invoiced of the TMD requests, saved at once (list and card); thirdparty of an
 *          unknown sender; files dropped anywhere on the card
 */

'use strict';

/**
 * Init tmd JS
 *
 * @type {Object}
 */
window.dolitmd.tmd = {};

/**
 * Tmd init
 *
 * @returns {void}
 */
window.dolitmd.tmd.init = function() {
  window.dolitmd.tmd.event();
  window.dolitmd.tmd.markRows($('[data-dolitmd-state]').closest('tr[data-rowid]'));
};

/**
 * Rows of the list greyed while their request is subcontracted: its state is given by the column « State » or
 * « Follow-up » (either may be hidden)
 *
 * @param   {jQuery} rows Rows of the list (tr[data-rowid])
 * @returns {void}
 */
window.dolitmd.tmd.markRows = function(rows) {
  rows.each(function() {
    const row = $(this);
    row.toggleClass('dolitmd-row--subcontracted', String(row.find('[data-dolitmd-state]').first().attr('data-dolitmd-state')) === '3');
  });
};

/**
 * Picto of a request by its category (Tmd::CATEGORY_PICTOS): the truck for the road and for no category
 *
 * @type {Object<string, string>}
 */
window.dolitmd.tmd.categoryPictos = {ADR: 'fa-truck-moving', IATA: 'fa-plane', IMDG: 'fa-ship'};

/**
 * Category chosen in the list (select of Saturne, saved by it): the picto of the request follows at once
 *
 * @returns {void}
 */
window.dolitmd.tmd.showCategoryPicto = function() {
  const pictos = window.dolitmd.tmd.categoryPictos;
  const picto  = pictos[$(this).val()] || pictos.ADR;
  $(this).closest('tr').find('a[href*="tmd_card.php"] .fas').filter('.' + Object.values(pictos).join(', .')).each(function() {
    $(this).removeClass(Object.values(pictos).join(' ')).addClass(picto);
  });
};

/**
 * Tmd events
 *
 * @returns {void}
 */
window.dolitmd.tmd.event = function() {
  $(document).on('change', '.dolitmd-flag-input', window.dolitmd.tmd.saveFlag);
  $(document).on('change', '.dolitmd-state', window.dolitmd.tmd.saveState);
  $(document).on('change', '.saturne-inline-select[data-field="category"]', window.dolitmd.tmd.showCategoryPicto);
  $(document).on('click', '.dolitmd-sender-create', window.dolitmd.tmd.openThirdpartyModal);
  $(document).on('submit', '.dolitmd-soc-form', window.dolitmd.tmd.createThirdparty);
  $(document).on('click', '.dolitmd-soc-link', window.dolitmd.tmd.linkThirdparty);
  $(document).on('click', '.dolitmd-soc-link-existing', window.dolitmd.tmd.linkExistingThirdparty);
  $(document).on('keydown', '.select2-container, .dolitmd-soc-modal', window.dolitmd.tmd.thirdpartyModalEscape);
  $(document).on('dragenter', window.dolitmd.tmd.onDragEnter);
  $(document).on('dragover', window.dolitmd.tmd.onDragOver);
  $(document).on('dragleave', window.dolitmd.tmd.onDragLeave);
  $(document).on('drop', window.dolitmd.tmd.onDrop);
};

/**
 * Token of the page, for the POST
 *
 * @returns {string}
 */
window.dolitmd.tmd.token = function() {
  if (window.saturne && window.saturne.toolbox && typeof window.saturne.toolbox.getToken === 'function') {
    const token = window.saturne.toolbox.getToken();
    if (token) {
      return token;
    }
  }
  return $('input[name="token"]').first().val() || '';
};

/**
 * Message to the user
 *
 * @param   {string}  message Text
 * @param   {boolean} error   An error
 * @returns {void}
 */
window.dolitmd.tmd.notify = function(message, error) {
  if (typeof $.jnotify === 'function' && message) {
    // An error stays until closed, a success goes by itself
    $.jnotify(message, error ? 'error' : 'ok', !!error);
  }
};

/**
 * Send a change of a request; the answer draws it again, the failure calls back
 *
 * @param   {jQuery}   element  Picto or select changed
 * @param   {Object}   data     Data of the POST
 * @param   {Function} onError  Called with the message when it fails
 * @returns {void}
 */
window.dolitmd.tmd.send = function(element, data, onError) {
  $.ajax({
    url: element.data('url'),
    type: 'POST',
    dataType: 'json',
    data: $.extend({id: element.data('id'), token: window.dolitmd.tmd.token()}, data)
  }).done(function(answer) {
    if (!answer || !answer.success) {
      onError(answer && answer.error);
      return;
    }
    window.dolitmd.tmd.refresh(answer);
    window.dolitmd.tmd.notify(answer.message, false);
  }).fail(function(xhr) {
    onError((xhr.responseJSON || {}).error);
  });
};

/**
 * A picto clicked: done or invoiced, set or cancelled (setting invoiced sets done too)
 *
 * @returns {void}
 */
window.dolitmd.tmd.saveFlag = function() {
  const input = $(this);
  const label = input.closest('.dolitmd-flag');
  const value = input.is(':checked') ? 1 : 0;

  label.addClass('dolitmd-flag--saving');
  input.prop('disabled', true);

  window.dolitmd.tmd.send(input, {action: 'set_flag', field: input.data('field'), value: value}, function(error) {
    input.prop('checked', !value).prop('disabled', false);
    label.removeClass('dolitmd-flag--saving').toggleClass('dolitmd-flag--on', !value);
    window.dolitmd.tmd.notify(error || input.data('error'), true);
  });
};

/**
 * A state chosen in the list: the pictos follow
 *
 * @returns {void}
 */
window.dolitmd.tmd.saveState = function() {
  const select   = $(this);
  const previous = String(select.data('state') !== undefined ? select.data('state') : (select.attr('class').match(/dolitmd-state--(\d)/) || [])[1]);

  select.addClass('dolitmd-state--saving').prop('disabled', true);

  window.dolitmd.tmd.send(select, {action: 'set_state', state: select.val()}, function(error) {
    select.val(previous).prop('disabled', false).removeClass('dolitmd-state--saving');
    window.dolitmd.tmd.notify(error || select.data('error'), true);
  });
};

/**
 * State and pictos of the request drawn again, in the list (its row and the cards) and on the card (its banner)
 *
 * @param   {Object} answer Answer of ajax/tmd.php
 * @returns {void}
 */
window.dolitmd.tmd.refresh = function(answer) {
  ['done', 'invoiced'].forEach(function(field) {
    $('.dolitmd-flag-input[data-id="' + answer.id + '"][data-field="' + field + '"]').closest('.dolitmd-flag').replaceWith(answer.flags[field]);
  });
  // The link to the invoice, after the pictos of the list: shown while the request is invoiced
  var flags = $('.dolitmd-flag-input[data-id="' + answer.id + '"][data-field="invoiced"]').closest('.dolitmd-flags');
  flags.find('.dolitmd-invoice-link').remove();
  flags.append(answer.invoice || '');
  flags.attr('data-dolitmd-state', answer.code);
  $('tr[data-rowid="' + answer.id + '"] td[data-colkey="status"]').html(answer.state);
  window.dolitmd.tmd.markRows($('tr[data-rowid="' + answer.id + '"]'));
  // Deadline: a request done is late no more
  $('tr[data-rowid="' + answer.id + '"] td[data-colkey="date_due"]').html(answer.due || '');
  $.each(answer.counts || {}, function(state, count) {
    $('.saturne-kpi-card[data-kpi-id="' + state + '"] .saturne-kpi-card-value').text(count);
  });
  // Card « Late »: red while there are some, grey when none
  if (answer.counts && answer.counts.late !== undefined) {
    $('.saturne-kpi-card[data-kpi-id="late"]').toggleClass('saturne-kpi-card-red', answer.counts.late > 0).toggleClass('saturne-kpi-card-grey', answer.counts.late === 0);
  }
  if ($('.dolitmd-flag-input[data-id="' + answer.id + '"]').closest('.fichecenter').length) {
    $('.statusref').first().html(answer.status);
  }
};

/**
 * Button « + » of an unknown sender: the modal, filled with its address and the name proposed
 *
 * @returns {void}
 */
window.dolitmd.tmd.openThirdpartyModal = function() {
  const button = $(this);
  const modal  = $('#dolitmd-soc-modal');

  // Fields of the previous sender emptied, country and type back to their default
  modal.find('.dolitmd-soc-form')[0].reset();
  // The existing thirdparty chosen for the previous sender: a hidden field (search by AJAX) is not emptied by the reset
  modal.find('input[type="hidden"][name="existing_socid"], #search_existing_socid').val('');
  // The selects are drawn by select2 on these pages: shown again with the value the reset gave them
  modal.find('select').trigger('change.select2');
  modal.data('id', button.data('id')).removeData('socid');
  modal.find('.dolitmd-soc-email').val(button.data('email') || '');
  modal.find('.dolitmd-soc-name').val(button.data('name') || '');
  window.dolitmd.tmd.thirdpartyError(modal, '', null);
  window.dolitmd.tmd.thirdpartySaving(modal, false);
  modal.addClass('modal-active');
  modal.find('.dolitmd-soc-name').trigger('focus').trigger('select');
};

/**
 * Escape closes the modal of the thirdparty, a field focused or not (Saturne would not close it then), but an Escape
 * that only closed a list of the modal (select2, autocompletion of the zip code: they prevent its default) leaves it
 * open with the fields typed. Enter in a list of the modal chooses its option, never closes the modal
 *
 * @param   {KeyboardEvent} event Key pressed
 * @returns {void}
 */
window.dolitmd.tmd.thirdpartyModalEscape = function(event) {
  const modal = $('#dolitmd-soc-modal');
  if (!modal.hasClass('modal-active')) {
    return;
  }

  // Enter that chose an option of a list (select2 gives the focus back to its box first): Saturne would take it for an
  // Enter out of the fields and click the first button of the footer, Cancel
  if (event.key === 'Enter' && $(event.target).closest('.select2-container').length) {
    event.stopPropagation();
    return;
  }
  if (event.key !== 'Escape') {
    return;
  }

  // Saturne handles Escape on the whole page: this one is done
  event.stopPropagation();
  if (!event.isDefaultPrevented()) {
    modal.removeClass('modal-active');
  }
};

/**
 * Error of the modal of the thirdparty, with the thirdparty of the same name to link instead
 *
 * @param   {jQuery}      modal    Modal
 * @param   {string}      message  Error, empty to hide it
 * @param   {Object|null} existing Thirdparty of the same name: id and label of its button
 * @returns {void}
 */
window.dolitmd.tmd.thirdpartyError = function(modal, message, existing) {
  const link = modal.find('.dolitmd-soc-link');

  modal.find('.dolitmd-soc-error').toggleClass('hidden', !message);
  modal.find('.dolitmd-soc-error-text').text(message || '');
  link.toggleClass('hidden', !existing);
  link.find('.dolitmd-soc-link-label').text(existing ? existing.label : '');
  if (existing) {
    modal.data('socid', existing.id);
  } else {
    modal.removeData('socid');
  }
};

/**
 * Buttons of the modal of the thirdparty blocked while it is saved
 *
 * @param   {jQuery}  modal  Modal
 * @param   {boolean} saving Being saved
 * @returns {void}
 */
window.dolitmd.tmd.thirdpartySaving = function(modal, saving) {
  modal.find('.dolitmd-soc-save, .dolitmd-soc-link, .dolitmd-soc-link-existing').prop('disabled', saving).toggleClass('button-disable', saving);
};

/**
 * Fields filled: the thirdparty created with them
 *
 * @param   {SubmitEvent} event Submit of the form of the modal
 * @returns {void}
 */
window.dolitmd.tmd.createThirdparty = function(event) {
  event.preventDefault();
  const modal  = $('#dolitmd-soc-modal');
  const fields = {};

  // A multiple choice (custcats[]) goes as a list, sent back as custcats[]=1&custcats[]=2
  $.each($(this).serializeArray(), function(index, field) {
    if (field.name.slice(-2) === '[]') {
      const name = field.name.slice(0, -2);
      fields[name] = (fields[name] || []).concat(field.value);
    } else {
      fields[field.name] = field.value;
    }
  });
  window.dolitmd.tmd.sendThirdparty(modal, fields);
};

/**
 * Name taken: the request linked to the thirdparty of this name
 *
 * @returns {void}
 */
window.dolitmd.tmd.linkThirdparty = function() {
  const modal = $('#dolitmd-soc-modal');

  window.dolitmd.tmd.sendThirdparty(modal, {socid: modal.data('socid')});
};

/**
 * An existing thirdparty chosen: the requests of the address linked to it, never a new one
 *
 * @returns {void}
 */
window.dolitmd.tmd.linkExistingThirdparty = function() {
  const modal = $('#dolitmd-soc-modal');
  const socId = parseInt(modal.find('[name="existing_socid"]').val(), 10);

  if (!(socId > 0)) {
    window.dolitmd.tmd.thirdpartyError(modal, $(this).data('empty'), null);
    return;
  }
  window.dolitmd.tmd.sendThirdparty(modal, {socid: socId});
};

/**
 * Send the thirdparty of the modal; the answer puts it on the requests of the address (list or card)
 *
 * @param   {jQuery} modal Modal
 * @param   {Object} data  Name typed, or thirdparty chosen
 * @returns {void}
 */
window.dolitmd.tmd.sendThirdparty = function(modal, data) {
  window.dolitmd.tmd.thirdpartySaving(modal, true);

  $.ajax({
    url: modal.data('url'),
    type: 'POST',
    dataType: 'json',
    data: $.extend({action: 'create_thirdparty', id: modal.data('id'), token: window.dolitmd.tmd.token()}, data)
  }).done(function(answer) {
    if (!answer || !answer.success) {
      window.dolitmd.tmd.thirdpartySaving(modal, false);
      window.dolitmd.tmd.thirdpartyError(modal, (answer && answer.error) || modal.data('error'), answer && answer.existing);
      return;
    }
    $.each(answer.ids || [], function(index, id) {
      $('.dolitmd-sender[data-id="' + id + '"]').replaceWith(answer.thirdparty);
    });
    modal.removeClass('modal-active');
    window.dolitmd.tmd.notify(answer.message, false);
  }).fail(function(xhr) {
    const answer = xhr.responseJSON || {};
    window.dolitmd.tmd.thirdpartySaving(modal, false);
    window.dolitmd.tmd.thirdpartyError(modal, answer.error || modal.data('error'), answer.existing || null);
  });
};

/**
 * Dragged files entered and not left yet: the elements of the page fire one dragenter and one dragleave each
 *
 * @type {number}
 */
window.dolitmd.tmd.dragDepth = 0;

/**
 * Cover of the card of a request, taking the files dropped anywhere on it: none on the other pages, whose drops are
 * left to their own handlers (zone of the tab Documents of Saturne...)
 *
 * @param   {Object} event Drag event
 * @returns {jQuery}       Cover, empty when the event is not for it
 */
window.dolitmd.tmd.dropZone = function(event) {
  const zone = $('.dolitmd-dropzone');
  const saturneDocument = window.saturne && window.saturne.document;

  if (!zone.length || !saturneDocument || typeof saturneDocument.isFileDrag !== 'function' || !saturneDocument.isFileDrag(event)) {
    return $();
  }
  return zone;
};

/**
 * Files dragged over the card: the cover shows where they go
 *
 * @param   {Object} event Drag event
 * @returns {void}
 */
window.dolitmd.tmd.onDragEnter = function(event) {
  const zone = window.dolitmd.tmd.dropZone(event);
  if (!zone.length) {
    return;
  }
  event.preventDefault();
  window.dolitmd.tmd.dragDepth++;
  zone.addClass('dolitmd-dropzone--visible');
};

/**
 * The files may be dropped anywhere on the card, so the browser never opens them instead
 *
 * @param   {Object} event Drag event
 * @returns {void}
 */
window.dolitmd.tmd.onDragOver = function(event) {
  const zone = window.dolitmd.tmd.dropZone(event);
  if (!zone.length) {
    return;
  }
  event.preventDefault();
  event.originalEvent.dataTransfer.dropEffect = 'copy';
  zone.addClass('dolitmd-dropzone--visible');
};

/**
 * The files left the window: the cover goes
 *
 * @param   {Object} event Drag event
 * @returns {void}
 */
window.dolitmd.tmd.onDragLeave = function(event) {
  const zone = window.dolitmd.tmd.dropZone(event);
  if (!zone.length) {
    return;
  }
  window.dolitmd.tmd.dragDepth--;
  // relatedTarget is null once the pointer is out of the window
  if (window.dolitmd.tmd.dragDepth <= 0 || event.originalEvent.relatedTarget === null) {
    window.dolitmd.tmd.dragDepth = 0;
    zone.removeClass('dolitmd-dropzone--visible');
  }
};

/**
 * Files dropped on the card: sent to the tab Documents of the request, then the card is read again (documents, nomeref
 * ID taken from the name). With a mail to the customer open, the card stays as typed: the files are added to the
 * attachments of the mail, ticked
 *
 * @param   {Object} event Drop event
 * @returns {void}
 */
window.dolitmd.tmd.onDrop = function(event) {
  const zone = window.dolitmd.tmd.dropZone(event);
  if (!zone.length) {
    return;
  }
  event.preventDefault();
  window.dolitmd.tmd.dragDepth = 0;
  zone.removeClass('dolitmd-dropzone--visible');
  if (zone.hasClass('dolitmd-dropzone--sending')) {
    return;
  }

  // A folder comes as a file the browser cannot read: left out, named to the user
  const dataTransfer = event.originalEvent.dataTransfer;
  const items        = $.grep(Array.prototype.slice.call(dataTransfer.items || []), function(item) {
    return item.kind === 'file';
  });
  const files   = [];
  const folders = [];
  let size      = 0;
  $.each(dataTransfer.files || [], function(index, file) {
    const entry = items[index] && typeof items[index].webkitGetAsEntry === 'function' ? items[index].webkitGetAsEntry() : null;
    if (entry && entry.isDirectory) {
      folders.push(file.name);
      return;
    }
    files.push(file);
    size += file.size;
  });
  if (folders.length) {
    window.dolitmd.tmd.notify(String(zone.data('label-folder')).replace('%s', folders.join(', ')), true);
  }
  if (!files.length) {
    return;
  }
  // Over the limit of the server, PHP would drop the whole POST (token included): refused before sending
  const maxSize = parseInt(zone.data('max-size'), 10) || 0;
  if (maxSize > 0 && size > maxSize) {
    window.dolitmd.tmd.notify(zone.data('label-too-big'), true);
    return;
  }

  const list   = $('.dolitmd-sendmail-files');
  const inline = list.length > 0;
  const data   = new window.FormData();
  data.append('action', 'upload_files');
  data.append('id', zone.data('id'));
  data.append('token', window.dolitmd.tmd.token());
  data.append('inline', inline ? '1' : '0');
  $.each(files, function(index, file) {
    data.append('userfile[]', file, file.name);
  });

  window.dolitmd.tmd.dropSending(zone, files.length);
  $.ajax({
    url: zone.data('url'),
    type: 'POST',
    data: data,
    processData: false,
    contentType: false,
    dataType: 'json'
  }).done(function(answer) {
    // The messages of the upload are shown by the card read again
    if (!inline) {
      window.location.href = zone.data('card-url');
      return;
    }
    window.dolitmd.tmd.dropSending(zone, 0);
    if (answer && answer.files && answer.files.length) {
      list.find('.dolitmd-sendmail-nofile').remove();
      $.each(answer.files, function(index, html) {
        list.append(html);
      });
    }
    window.dolitmd.tmd.notifyEvents(answer && answer.events);
  }).fail(function(xhr) {
    window.dolitmd.tmd.dropSending(zone, 0);
    window.dolitmd.tmd.notify((xhr.responseJSON || {}).error || zone.data('label-error'), true);
  });
};

/**
 * Cover kept while the files are sent, with their number; 0 gives the cover back as it was
 *
 * @param   {jQuery} zone  Cover
 * @param   {number} count Files being sent
 * @returns {void}
 */
window.dolitmd.tmd.dropSending = function(zone, count) {
  const title = zone.find('.dolitmd-dropzone-title');
  if (zone.data('title') === undefined) {
    zone.data('title', title.text());
  }
  zone.toggleClass('dolitmd-dropzone--sending', count > 0);
  zone.find('.dolitmd-dropzone-icon').toggleClass('fa-cloud-upload-alt', count === 0).toggleClass('fa-spinner fa-spin', count > 0);
  title.text(count > 0 ? String(zone.data('label-sending')).replace('%s', count) : zone.data('title'));
};

/**
 * Messages of Dolibarr given back by ajax/tmd.php (setEventMessages of the upload), shown as Dolibarr shows them
 *
 * @param   {Object} events Messages by type: mesgs, warnings, errors
 * @returns {void}
 */
window.dolitmd.tmd.notifyEvents = function(events) {
  if (typeof $.jnotify !== 'function') {
    return;
  }
  $.each({mesgs: 'ok', warnings: 'warning', errors: 'error'}, function(key, type) {
    $.each((events && events[key]) || [], function(index, message) {
      $.jnotify(message, type, type !== 'ok');
    });
  });
};
