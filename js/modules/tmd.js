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
 * \brief   State and pictos done / invoiced of the TMD requests, saved at once (list and card)
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
};

/**
 * Tmd events
 *
 * @returns {void}
 */
window.dolitmd.tmd.event = function() {
  $(document).on('change', '.dolitmd-flag-input', window.dolitmd.tmd.saveFlag);
  $(document).on('change', '.dolitmd-state', window.dolitmd.tmd.saveState);
  $(document).on('click', '.dolitmd-sender-create', window.dolitmd.tmd.openThirdpartyModal);
  $(document).on('submit', '.dolitmd-soc-form', window.dolitmd.tmd.createThirdparty);
  $(document).on('click', '.dolitmd-soc-link', window.dolitmd.tmd.linkThirdparty);
  $(document).on('keydown', '.select2-container, .dolitmd-soc-modal', window.dolitmd.tmd.thirdpartyModalEscape);
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
  $('tr[data-rowid="' + answer.id + '"] td[data-colkey="status"]').html(answer.state);
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
 * open with the fields typed
 *
 * @param   {KeyboardEvent} event Key pressed
 * @returns {void}
 */
window.dolitmd.tmd.thirdpartyModalEscape = function(event) {
  const modal = $('#dolitmd-soc-modal');
  if (event.key !== 'Escape' || !modal.hasClass('modal-active')) {
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
  modal.find('.dolitmd-soc-save, .dolitmd-soc-link').prop('disabled', saving).toggleClass('button-disable', saving);
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
