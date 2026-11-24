-- SPDX-License-Identifier: GPL-2.0-or-later
-- SPDX-FileCopyrightText: Netresearch DTT GmbH

CREATE TABLE tx_nrlpfixture_item (
	align varchar(20) DEFAULT 'left' NOT NULL,
	parent_content int(11) unsigned DEFAULT '0' NOT NULL,
	header varchar(255) DEFAULT '' NOT NULL,
	bodytext text
);

CREATE TABLE tt_content (
	tx_nrlpfixture_items int(11) unsigned DEFAULT '0' NOT NULL
);
