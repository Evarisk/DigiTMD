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

-- Templates of the automatic mails of the TMD requests (Setup > Emails > Templates, type « TMD requests »). Played at
-- each activation: inserted once only. Keys: __TMD_REF__, __TMD_SUBJECT__, __TMD_DATE_RECEIVED__, __TMD_DATE_DUE__,
-- __TICKET_REF__, __THIRDPARTY_NAME__, __SENDER_EMAIL__, __TMD_TRACK_ID__ (tracking number), __TMD_PUBLIC_URL__ (public
-- page of the request: state and documents). Never the nomeref ID, internal

INSERT INTO llx_c_email_templates (entity, module, type_template, lang, private, fk_user, datec, label, position, enabled, active, topic, content, content_lines, joinfiles)
SELECT 0, 'dolitmd', 'dolitmd_tmd', '', 0, null, null, '(TmdMailAckTemplate)', 10, "isModEnabled('dolitmd')", 1,
       'Re: __TMD_SUBJECT__ [__TICKET_REF__]',
       '<p>Bonjour,</p><p>Nous avons bien reçu votre demande « __TMD_SUBJECT__ » du __TMD_DATE_RECEIVED__. Elle est enregistrée sous la référence <strong>__TICKET_REF__</strong> et sera traitée au plus tard le __TMD_DATE_DUE__.</p><p>Suivez son avancement et retrouvez ses documents sur votre page de suivi : <a href="__TMD_PUBLIC_URL__">suivre ma demande</a> (numéro de suivi : <strong>__TMD_TRACK_ID__</strong>).</p><p>Pour toute question, répondez simplement à ce mail en gardant la référence dans l''objet.</p><p>Cordialement,<br>__MYCOMPANY_NAME__</p>',
       null, 0
FROM DUAL
WHERE NOT EXISTS (SELECT rowid FROM llx_c_email_templates WHERE type_template = 'dolitmd_tmd' AND label = '(TmdMailAckTemplate)');

INSERT INTO llx_c_email_templates (entity, module, type_template, lang, private, fk_user, datec, label, position, enabled, active, topic, content, content_lines, joinfiles)
SELECT 0, 'dolitmd', 'dolitmd_tmd', '', 0, null, null, '(TmdMailDoneTemplate)', 20, "isModEnabled('dolitmd')", 1,
       'Re: __TMD_SUBJECT__ [__TICKET_REF__] - demande traitée',
       '<p>Bonjour,</p><p>Votre demande « __TMD_SUBJECT__ » (référence <strong>__TICKET_REF__</strong>) a été traitée.</p><p>Retrouvez-la, avec ses documents, sur votre page de suivi : <a href="__TMD_PUBLIC_URL__">voir ma demande</a> (numéro de suivi : <strong>__TMD_TRACK_ID__</strong>).</p><p>Si vous avez besoin d''autre chose, répondez simplement à ce mail.</p><p>Cordialement,<br>__MYCOMPANY_NAME__</p>',
       null, 0
FROM DUAL
WHERE NOT EXISTS (SELECT rowid FROM llx_c_email_templates WHERE type_template = 'dolitmd_tmd' AND label = '(TmdMailDoneTemplate)');

INSERT INTO llx_c_email_templates (entity, module, type_template, lang, private, fk_user, datec, label, position, enabled, active, topic, content, content_lines, joinfiles)
SELECT 0, 'dolitmd', 'dolitmd_tmd', '', 0, null, null, '(TmdMailLateTemplate)', 30, "isModEnabled('dolitmd')", 1,
       'Demandes TMD en retard : __TMD_LATE_COUNT__',
       '<p>Bonjour __USER_FIRSTNAME__,</p><p>__TMD_LATE_COUNT__ demande(s) TMD ont dépassé leur échéance :</p>__TMD_LATE_LIST__<p><a href="__TMD_LIST_URL__">Ouvrir la liste des demandes TMD</a></p>',
       null, 0
FROM DUAL
WHERE NOT EXISTS (SELECT rowid FROM llx_c_email_templates WHERE type_template = 'dolitmd_tmd' AND label = '(TmdMailLateTemplate)');
