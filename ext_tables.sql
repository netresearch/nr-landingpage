# SPDX-License-Identifier: GPL-2.0-or-later
# SPDX-FileCopyrightText: Netresearch DTT GmbH

CREATE TABLE tx_nrlandingpage_domain_model_template (
    title varchar(255) NOT NULL DEFAULT '',
    identifier varchar(255) NOT NULL DEFAULT '',
    description text,
    llm_configuration int(11) unsigned NOT NULL DEFAULT 0,
    system_prompt text,
    allowed_ctypes text,
    page_fields text,
    reference_pages text,
    briefing_mode varchar(20) NOT NULL DEFAULT 'optional',
    publish_mode varchar(20) NOT NULL DEFAULT 'hidden',
    be_groups text,
    backend_layout varchar(255) NOT NULL DEFAULT '',
    prompt_optimizer_context text,
    prompt_optimizer_meta_prompt text,
    image_task int(11) unsigned NOT NULL DEFAULT 0,
    generation_mode varchar(20) NOT NULL DEFAULT 'structured',
    color_primary varchar(7) NOT NULL DEFAULT '',
    color_secondary varchar(7) NOT NULL DEFAULT '',
    color_background varchar(7) NOT NULL DEFAULT '',
    color_text varchar(7) NOT NULL DEFAULT '',
    animation_enabled tinyint(1) unsigned NOT NULL DEFAULT 1,
    content_columns text,
    ctype_rules text,
    image_size varchar(20) NOT NULL DEFAULT '1024x1024',
    image_folder varchar(255) NOT NULL DEFAULT ''
);

CREATE TABLE pages (
    tx_nrlandingpage_template_uid int(11) unsigned DEFAULT 0,
    tx_nrlandingpage_briefing_data text,
    tx_nrlandingpage_config_hash varchar(64) NOT NULL DEFAULT '',
    tx_nrlandingpage_generated_at int(11) unsigned DEFAULT 0,
    tx_nrlandingpage_source_page_uid int(11) unsigned DEFAULT 0,

    KEY idx_template_uid (tx_nrlandingpage_template_uid)
);

CREATE TABLE tx_nrlandingpage_workspace_draft (
    uid int(11) unsigned NOT NULL auto_increment,
    pid int(11) unsigned NOT NULL DEFAULT 0,
    tstamp int(11) unsigned NOT NULL DEFAULT 0,
    crdate int(11) unsigned NOT NULL DEFAULT 0,
    deleted tinyint(1) unsigned NOT NULL DEFAULT 0,
    owner int(11) unsigned NOT NULL DEFAULT 0,
    be_groups text,
    title varchar(255) NOT NULL DEFAULT '',
    template_uid int(11) unsigned NOT NULL DEFAULT 0,
    parent_page_uid int(11) unsigned NOT NULL DEFAULT 0,
    briefing_data mediumtext,
    page_fields mediumtext,
    sections mediumtext,
    version int(11) unsigned NOT NULL DEFAULT 1,
    status varchar(20) NOT NULL DEFAULT 'draft',
    approved_by int(11) unsigned NOT NULL DEFAULT 0,
    approved_at int(11) unsigned NOT NULL DEFAULT 0,
    approved_version int(11) unsigned NOT NULL DEFAULT 0,
    created_page_uid int(11) unsigned NOT NULL DEFAULT 0,

    PRIMARY KEY (uid),
    KEY idx_owner (owner)
);

CREATE TABLE tx_nrlandingpage_workspace_link (
    uid int(11) unsigned NOT NULL auto_increment,
    pid int(11) unsigned NOT NULL DEFAULT 0,
    tstamp int(11) unsigned NOT NULL DEFAULT 0,
    crdate int(11) unsigned NOT NULL DEFAULT 0,
    draft_uid int(11) unsigned NOT NULL DEFAULT 0,
    label varchar(255) NOT NULL DEFAULT '',
    token_prefix varchar(16) NOT NULL DEFAULT '',
    token_hash varchar(64) NOT NULL DEFAULT '',
    permission varchar(20) NOT NULL DEFAULT 'view',
    expires int(11) unsigned NOT NULL DEFAULT 0,
    passphrase_hash varchar(255) NOT NULL DEFAULT '',
    max_uses int(11) unsigned NOT NULL DEFAULT 0,
    use_count int(11) unsigned NOT NULL DEFAULT 0,
    created_by int(11) unsigned NOT NULL DEFAULT 0,
    revoked_at int(11) unsigned NOT NULL DEFAULT 0,
    revoked_by int(11) unsigned NOT NULL DEFAULT 0,

    PRIMARY KEY (uid),
    UNIQUE KEY uniq_token_prefix (token_prefix),
    KEY idx_draft (draft_uid),
    KEY idx_expires (expires)
);

CREATE TABLE tx_nrlandingpage_workspace_session (
    uid int(11) unsigned NOT NULL auto_increment,
    pid int(11) unsigned NOT NULL DEFAULT 0,
    tstamp int(11) unsigned NOT NULL DEFAULT 0,
    crdate int(11) unsigned NOT NULL DEFAULT 0,
    link_uid int(11) unsigned NOT NULL DEFAULT 0,
    session_hash varchar(64) NOT NULL DEFAULT '',
    reviewer_name varchar(255) NOT NULL DEFAULT '',
    expires int(11) unsigned NOT NULL DEFAULT 0,

    PRIMARY KEY (uid),
    UNIQUE KEY uniq_session_hash (session_hash),
    KEY idx_link (link_uid),
    KEY idx_expires (expires)
);

CREATE TABLE tx_nrlandingpage_workspace_comment (
    uid int(11) unsigned NOT NULL auto_increment,
    pid int(11) unsigned NOT NULL DEFAULT 0,
    tstamp int(11) unsigned NOT NULL DEFAULT 0,
    crdate int(11) unsigned NOT NULL DEFAULT 0,
    draft_uid int(11) unsigned NOT NULL DEFAULT 0,
    link_uid int(11) unsigned NOT NULL DEFAULT 0,
    be_user int(11) unsigned NOT NULL DEFAULT 0,
    section_id varchar(32) NOT NULL DEFAULT '',
    field varchar(20) NOT NULL DEFAULT '',
    kind varchar(20) NOT NULL DEFAULT 'comment',
    author_name varchar(255) NOT NULL DEFAULT '',
    body text,
    base_text mediumtext,
    suggested_text mediumtext,
    status varchar(20) NOT NULL DEFAULT 'open',
    resolved_by int(11) unsigned NOT NULL DEFAULT 0,
    resolved_at int(11) unsigned NOT NULL DEFAULT 0,

    PRIMARY KEY (uid),
    KEY idx_draft (draft_uid),
    KEY idx_link (link_uid)
);

CREATE TABLE tx_nrlandingpage_workspace_audit (
    uid int(11) unsigned NOT NULL auto_increment,
    pid int(11) unsigned NOT NULL DEFAULT 0,
    tstamp int(11) unsigned NOT NULL DEFAULT 0,
    crdate int(11) unsigned NOT NULL DEFAULT 0,
    draft_uid int(11) unsigned NOT NULL DEFAULT 0,
    link_uid int(11) unsigned NOT NULL DEFAULT 0,
    be_user int(11) unsigned NOT NULL DEFAULT 0,
    action varchar(30) NOT NULL DEFAULT '',

    PRIMARY KEY (uid),
    KEY idx_draft (draft_uid)
);
