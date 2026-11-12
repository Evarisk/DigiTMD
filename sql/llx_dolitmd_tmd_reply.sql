-- Copyright (C) 2026 EVARISK <technique@evarisk.com>
--
-- This program is free software: you can redistribute it and/or modify
-- it under the terms of the GNU General Public License as published by
-- the Free Software Foundation, either version 3 of the License, or
-- (at your option) any later version.
--
-- This program is distributed in the hope that it will be useful,
-- but WITHOUT ANY WARRANTY; without even the implied warranty of
-- MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
-- GNU General Public License for more details.
--
-- You should have received a copy of the GNU General Public License
-- along with this program.  If not, see https://www.gnu.org/licenses/.

-- Answers about a TMD request added to its ticket: one row per mail, so that a mail is never added twice
CREATE TABLE llx_dolitmd_tmd_reply(
	rowid         integer AUTO_INCREMENT PRIMARY KEY NOT NULL,
	entity        integer DEFAULT 1 NOT NULL,
	fk_tmd        integer NOT NULL,
	message_id    varchar(255) NOT NULL,
	email_from    varchar(255),
	date_mail     datetime,
	date_creation datetime NOT NULL
) ENGINE=innodb;
