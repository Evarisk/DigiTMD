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

-- A TMD request: one mail received on the TMD mailbox (or one typed by hand), followed by its ticket
CREATE TABLE llx_dolitmd_tmd(
	rowid            integer AUTO_INCREMENT PRIMARY KEY NOT NULL,
	ref              varchar(128) NOT NULL,
	ref_ext          varchar(128),
	entity           integer DEFAULT 1 NOT NULL,
	date_creation    datetime NOT NULL,
	tms              timestamp DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
	import_key       varchar(14),
	status           smallint DEFAULT 0 NOT NULL,
	label            varchar(255),
	software_ref     varchar(128),
	fk_soc           integer,
	fk_socpeople     integer,
	fk_ticket        integer,
	email_from       varchar(255),
	message_id       varchar(255),
	track_id         varchar(32),
	date_mail        datetime,
	date_due         datetime,
	nb_replies       integer DEFAULT 0 NOT NULL,
	date_last_reply  datetime,
	fk_facture       integer,
	done             smallint DEFAULT 0 NOT NULL,
	date_done        datetime,
	fk_user_done     integer,
	invoiced         smallint DEFAULT 0 NOT NULL,
	date_invoiced    datetime,
	fk_user_invoiced integer,
	fk_user_creat    integer NOT NULL,
	fk_user_modif    integer
) ENGINE=innodb;
