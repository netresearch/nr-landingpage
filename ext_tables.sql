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
    be_groups text
);
