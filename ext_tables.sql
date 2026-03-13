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
    animation_enabled tinyint(1) unsigned NOT NULL DEFAULT 1
);

CREATE TABLE pages (
    tx_nrlandingpage_template_uid int(11) unsigned DEFAULT 0,
    tx_nrlandingpage_briefing_data text,
    tx_nrlandingpage_config_hash varchar(64) NOT NULL DEFAULT '',
    tx_nrlandingpage_generated_at int(11) unsigned DEFAULT 0,
    tx_nrlandingpage_source_page_uid int(11) unsigned DEFAULT 0,

    KEY idx_template_uid (tx_nrlandingpage_template_uid)
);
