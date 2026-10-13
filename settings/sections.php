<?php
/* ═══════════════════════════════════════════
   SETTINGS — section registry (used by settings.php)
   One entry per section, in sidebar order. Key = the ?s= slug.
     group    — sidebar group: you | collection | site   (title: settings.group.<group>)
     admin    — false: everyone · true: admins only (Site group)
     prefixes — language prefixes the section's JS needs (appScript); 'admin' is added on admin sections
     scripts  — extra files from assets/js/ loaded after settings.js / admin.js
     badge    — optional language key for a small badge in the sidebar (e.g. settings.badge_new)
   Title: settings.nav.<slug, - as _> · partial: settings/s_<slug, - as _>.php
   ═══════════════════════════════════════════ */
if (!defined('IN_SETTINGS')) exit;

/** POST actions handled by settings/actions_admin.php (everything else goes to actions_user.php). */
const CP_ADMIN_ACTIONS = [
    'gen_invite', 'delete_invite', 'user_status', 'reset_password', 'unlock', 'unlock_all',
    'add_game', 'edit_game', 'toggle_game', 'set_default_image', 'add_system', 'set_system_icon', 'remove_system_icon', 'set_system_region',
    'save_site_settings', 'upload_language', 'set_default_language', 'set_default_theme',
    'check_updates', 'fetch_update', 'delete_update', 'run_migrations', 'save_backup_settings',
    'save_log_settings', 'purge_log', 'save_barcode_settings', 'save_export_settings',
    'save_cover_default', 'clear_cover_overrides', 'set_cover_source',
];

return [
    'account'        => ['group' => 'you',        'admin' => false,   'prefixes' => ['settings']],
    'appearance'     => ['group' => 'you',        'admin' => false,   'prefixes' => ['settings']],
    'sharing'        => ['group' => 'you',        'admin' => false,   'prefixes' => ['settings']],
    'grading'        => ['group' => 'collection', 'admin' => false,   'prefixes' => ['settings']],
    'labels'         => ['group' => 'collection', 'admin' => false,   'prefixes' => ['settings', 'lbl'], 'scripts' => ['vendor/qrcode.js', 'labels.js', 'labels-editor.js']],
    'options'        => ['group' => 'collection', 'admin' => false,   'prefixes' => ['settings']],
    'editions'       => ['group' => 'collection', 'admin' => false,   'prefixes' => ['settings', 'ed']],
    'systems'        => ['group' => 'collection', 'admin' => false,   'prefixes' => ['settings']],
    'columns'        => ['group' => 'collection', 'admin' => false,   'prefixes' => ['settings']],
    'backup'         => ['group' => 'collection', 'admin' => false,   'prefixes' => ['settings', 'import']],
    'general'        => ['group' => 'site',       'admin' => true,    'prefixes' => []],
    'site-appearance'=> ['group' => 'site',       'admin' => true,    'prefixes' => []],
    'grading-system' => ['group' => 'site',       'admin' => true,    'prefixes' => ['grading', 'ga'], 'scripts' => ['grading-admin.js']],
    'label-sizes'    => ['group' => 'site',       'admin' => true,    'prefixes' => ['lbl'], 'scripts' => ['labels-editor.js']],
    'catalogue'      => ['group' => 'site',       'admin' => true,    'prefixes' => ['prices', 'import', 'ed', 'comp'], 'scripts' => ['editions-admin.js', 'compilations-admin.js', 'catalogue-games.js']],
    'barcodes'       => ['group' => 'site',       'admin' => true,    'prefixes' => ['bc'], 'scripts' => ['barcode.js', 'barcodes-admin.js'], 'badge' => 'settings.badge_new'],
    'igdb'           => ['group' => 'site',       'admin' => true,    'prefixes' => ['igdb'], 'scripts' => ['igdb-admin.js'], 'badge' => 'settings.badge_new'],
    'data-export'    => ['group' => 'site',       'admin' => true,    'prefixes' => ['dx'], 'scripts' => ['data-export.js'], 'badge' => 'settings.badge_new'],
    'users'          => ['group' => 'site',       'admin' => true,    'prefixes' => []],
    'activity'       => ['group' => 'site',       'admin' => true,    'prefixes' => ['log'], 'scripts' => ['activity.js'], 'badge' => 'settings.badge_new'],
    'site-backup'    => ['group' => 'site',       'admin' => true,    'prefixes' => ['sbackup'], 'scripts' => ['site-backup.js'], 'badge' => 'settings.badge_new'],
    'updates'        => ['group' => 'site',       'admin' => true,    'prefixes' => []],
];
