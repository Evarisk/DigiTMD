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
 * \file    js/modules/inbox.js
 * \ingroup dolitmd
 * \brief   « Link to a TMD request » of a mail, in a window of Inbox (view/tmd/tmd_inbox_link.php): Inbox is told when
 *          the mail is linked (it closes the window and reads the panel of the mail again), the buttons close it
 */

'use strict';

/**
 * Init inbox JS
 *
 * @type {Object}
 */
window.dolitmd.inbox = {};

/**
 * Time the confirmation stays before the window closes, in milliseconds
 *
 * @type {Number}
 */
window.dolitmd.inbox.doneDelay = 900;

/**
 * Inbox init
 *
 * @returns {void}
 */
window.dolitmd.inbox.init = function() {
  window.dolitmd.inbox.event();
  if ($('.dolitmd-inbox-link[data-dolitmd-inbox-done]').length) {
    setTimeout(function() {
      window.dolitmd.inbox.tell('done');
    }, window.dolitmd.inbox.doneDelay);
  }
};

/**
 * Inbox events
 *
 * @returns {void}
 */
window.dolitmd.inbox.event = function() {
  $(document).on('click', '.dolitmd-inbox-close', window.dolitmd.inbox.close);
};

/**
 * The page is in a window of Inbox (a frame of the same site)
 *
 * @returns {Boolean}
 */
window.dolitmd.inbox.inWindow = function() {
  try {
    return window.parent !== window && window.parent.location.origin === window.location.origin;
  } catch (error) {
    return false;
  }
};

/**
 * Message to the window of Inbox (done: the mail is linked; close: nothing done)
 *
 * @param   {String}  action done or close
 * @returns {Boolean}        Told, false out of a window of Inbox
 */
window.dolitmd.inbox.tell = function(action) {
  if (!window.dolitmd.inbox.inWindow()) {
    return false;
  }
  window.parent.postMessage({ source: 'inbox-panel', action: action }, window.location.origin);
  return true;
};

/**
 * Close the window of Inbox, or the tab of an Inbox without windows
 *
 * @param   {Event} event Click
 * @returns {void}
 */
window.dolitmd.inbox.close = function(event) {
  event.preventDefault();
  const done = $('.dolitmd-inbox-link[data-dolitmd-inbox-done]').length > 0;
  if (!window.dolitmd.inbox.tell(done ? 'done' : 'close')) {
    window.close();
  }
};
