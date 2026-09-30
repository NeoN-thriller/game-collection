<?php
/* SETTINGS › Catalogue (admin): systems, edition suggestions, game lists per system (&sys=)
   with edition linking, PriceCharting import */
if (!defined('IN_SETTINGS')) exit;

$systems = db()->query("SELECT * FROM systems ORDER BY sort_order")->fetchAll();
$viewSys = (int)($_GET['sys'] ?? ($systems[0]['id'] ?? 0));
$gamesSt = db()->prepare("SELECT * FROM games WHERE system_id=? ORDER BY sort_title");
$gamesSt->execute([$viewSys]);
$games = $gamesSt->fetchAll();

// Editions: groups of this system with their members (main release first), placed in the list by group title
$groupSt = db()->prepare("SELECT * FROM game_groups WHERE system_id=?");
$groupSt->execute([$viewSys]);
$groups = [];
foreach ($groupSt->fetchAll() as $gr) $groups[(int)$gr['id']] = $gr + ['members' => []];
$listItems = [];
foreach ($games as $g) {
    if ($g['group_id'] !== null && isset($groups[(int)$g['group_id']])) $groups[(int)$g['group_id']]['members'][] = $g;
    else $listItems[] = ['sort' => $g['sort_title'], 'game' => $g];
}
foreach ($groups as $gr) {
    if (!$gr['members']) continue;
    usort($gr['members'], fn($a, $b) => [(int)$a['edition_sort'], (int)$a['id']] <=> [(int)$b['edition_sort'], (int)$b['id']]);
    $listItems[] = ['sort' => $gr['sort_title'], 'group' => $gr];
}
usort($listItems, fn($a, $b) => strcasecmp($a['sort'], $b['sort']));

// Data for assets/js/editions-admin.js (link dialog)
$edData = ['system_id' => $viewSys, 'games' => [], 'groups' => []];
foreach ($games as $g) {
    $edData['games'][(int)$g['id']] = [
        'title' => $g['title'], 'group_id' => $g['group_id'] !== null ? (int)$g['group_id'] : null,
        'edition_label' => $g['edition_label'], 'cib_price' => $g['cib_price'] !== null ? (float)$g['cib_price'] : null,
    ];
}
foreach ($groups as $gid => $gr) {
    if (!$gr['members']) continue;
    $edData['groups'][$gid] = ['title' => $gr['title'], 'main_game_id' => (int)$gr['main_game_id'], 'members' => array_map(fn($m) => (int)$m['id'], $gr['members'])];
}
$edSuggestSys = ($_GET['esys'] ?? '') === 'all' ? 0 : $viewSys;

/** One game row of the admin list. $member: shown inside a group; $isMain: the group's main release. */
$gameRow = function (array $g, bool $member = false, bool $isMain = false) { ?>
    <tr class="<?= $member ? 'ed-member-row' : '' ?>" data-game-id="<?= $g['id'] ?>" data-linked="<?= $member ? 1 : 0 ?>"<?= $member ? ' data-group-id="'.(int)$g['group_id'].'"' : '' ?> style="<?= !$g['active']?'opacity:.45':'' ?>">
      <td><input type="checkbox" class="ed-pick" value="<?= $g['id'] ?>" aria-label="<?= t('ed.pick') ?>: <?= htmlspecialchars($g['title']) ?>"></td>
      <td style="color:var(--muted);font-size:.7rem"><?= $g['sort_order'] ?></td>
      <td class="ed-title"><?php if ($member): ?><span class="ed-indent" aria-hidden="true">└</span> <?php endif; ?><?= htmlspecialchars($g['title']) ?></td>
      <td><?php if ($member): ?><?= htmlspecialchars((string)$g['edition_label']) ?><?php if ($isMain): ?> <span class="chip chip-y"><?= t('ed.main_tag') ?></span><?php endif; ?><?php else: ?><span style="color:var(--muted)">—</span><?php endif; ?></td>
      <td>
        <?php if ($g['default_image']): ?>
          <img src="<?= BASE_URL ?>/uploads/defaults/<?= htmlspecialchars($g['default_image']) ?>" alt="" style="height:32px;border:1px solid var(--border2)">
        <?php else: ?>
          <span style="color:var(--muted);font-size:.7rem">—</span>
        <?php endif; ?>
      </td>
      <td><span class="tag <?= $g['active']?'tag-active':'tag-inactive' ?>"><?= t($g['active'] ? 'admin.site.yes' : 'admin.site.no') ?></span></td>
      <td style="display:flex;gap:6px;flex-wrap:wrap">
        <!-- Toggle active -->
        <form method="POST" style="display:inline">
          <input type="hidden" name="csrf"    value="<?= csrf() ?>">
          <input type="hidden" name="action"  value="toggle_game">
          <input type="hidden" name="game_id" value="<?= $g['id'] ?>">
          <button class="btn-icon" type="submit"><?= t($g['active'] ? 'admin.games.disable' : 'admin.games.enable') ?></button>
        </form>
        <!-- Set default image -->
        <form method="POST" enctype="multipart/form-data" style="display:flex;gap:4px">
          <input type="hidden" name="csrf"    value="<?= csrf() ?>">
          <input type="hidden" name="action"  value="set_default_image">
          <input type="hidden" name="game_id" value="<?= $g['id'] ?>">
          <input type="file" name="default_image" accept="image/*" aria-label="<?= t('admin.games.default_image') ?>" style="font-size:.65rem;width:160px">
          <button class="btn-icon" type="submit"><?= t('admin.games.set_image') ?></button>
        </form>
        <?php if ($member): ?>
        <button class="btn-icon" type="button" onclick="edRemoveMember(<?= $g['id'] ?>)"><?= t('ed.remove_member') ?></button>
        <?php endif; ?>
      </td>
    </tr>
<?php };
?>
<section class="cp-card">
  <h2><?= t('dashboard.systems') ?></h2>
  <form method="POST" style="display:flex;gap:8px;margin-bottom:16px;flex-wrap:wrap">
    <input type="hidden" name="csrf"   value="<?= csrf() ?>">
    <input type="hidden" name="action" value="add_system">
    <input type="text" name="name"       placeholder="<?= t('admin.sys.name_ph') ?>" aria-label="<?= t('admin.sys.name') ?>" style="width:260px">
    <input type="text" name="short_name" placeholder="<?= t('admin.sys.short_ph') ?>" aria-label="<?= t('admin.sys.short') ?>" style="width:100px">
    <select name="region" style="width:auto" title="<?= t('admin.site.region') ?>" aria-label="<?= t('admin.site.region') ?>">
      <?php foreach (REGIONS as $r): ?><option value="<?= $r ?>" <?= setting('default_region') === $r ? 'selected' : '' ?>><?= $r === 'Mixed' ? t('admin.site.region_mixed') : $r ?></option><?php endforeach; ?>
    </select>
    <button class="btn btn-sm" type="submit"><?= t('admin.sys.add') ?></button>
  </form>
  <table class="admin-table">
    <thead><tr><th>#</th><th><?= t('admin.sys.icon') ?></th><th><?= t('admin.sys.name') ?></th><th><?= t('admin.sys.short') ?></th><th><?= t('admin.site.region') ?></th><th><?= t('admin.sys.active') ?></th><th><?= t('admin.sys.set_icon') ?></th></tr></thead>
    <tbody>
    <?php foreach ($systems as $sy): ?>
    <tr>
      <td style="color:var(--muted);font-size:.7rem"><?= $sy['sort_order'] ?></td>
      <td><?php if (!empty($sy['icon_image'])): ?>
        <img src="<?= BASE_URL ?>/uploads/icons/<?= htmlspecialchars($sy['icon_image']) ?>" alt="" style="width:28px;height:28px;object-fit:contain">
      <?php else: ?><span style="color:var(--muted);font-size:.7rem">—</span><?php endif; ?></td>
      <td><?= htmlspecialchars($sy['name']) ?></td>
      <td style="color:var(--wiiu)"><?= htmlspecialchars($sy['short_name']) ?></td>
      <td>
        <form method="POST" style="display:inline">
          <input type="hidden" name="csrf"      value="<?= csrf() ?>">
          <input type="hidden" name="action"    value="set_system_region">
          <input type="hidden" name="system_id" value="<?= $sy['id'] ?>">
          <select name="region" onchange="this.form.requestSubmit()" aria-label="<?= t('admin.site.region') ?>" style="font-size:.68rem;padding:3px 6px;width:auto">
            <?php foreach (REGIONS as $r): ?><option value="<?= $r ?>" <?= $sy['region'] === $r ? 'selected' : '' ?>><?= $r === 'Mixed' ? t('admin.site.region_mixed') : $r ?></option><?php endforeach; ?>
          </select>
        </form>
      </td>
      <td><span class="tag <?= $sy['active']?'tag-active':'tag-inactive' ?>"><?= t($sy['active'] ? 'admin.site.yes' : 'admin.site.no') ?></span></td>
      <td>
        <form method="POST" enctype="multipart/form-data" style="display:flex;gap:4px">
          <input type="hidden" name="csrf" value="<?= csrf() ?>">
          <input type="hidden" name="action" value="set_system_icon">
          <input type="hidden" name="system_id" value="<?= $sy['id'] ?>">
          <input type="file" name="icon_image" accept="image/*" aria-label="<?= t('admin.sys.icon') ?>" style="font-size:.65rem;width:140px">
          <button class="btn-icon" type="submit"><?= t('admin.sys.set_icon') ?></button>
        </form>
      </td>
    </tr>
    <?php endforeach; ?>
    </tbody>
  </table>
</section>

<section class="cp-card" id="edition-suggestions">
  <div class="ed-sug-head">
    <h2 style="margin:0"><?= t('ed.sug_title') ?></h2>
    <span class="cp-badge ed-count" id="ed-sug-count" hidden></span>
    <select id="ed-sug-sys" aria-label="<?= t('common.col.system') ?>" style="width:auto;margin-left:auto">
      <option value="0"<?= $edSuggestSys === 0 ? ' selected' : '' ?>><?= t('ed.sug_all_systems') ?></option>
      <?php foreach ($systems as $sy): ?>
      <option value="<?= $sy['id'] ?>"<?= (int)$sy['id'] === $edSuggestSys ? ' selected' : '' ?>><?= htmlspecialchars($sy['name']) ?></option>
      <?php endforeach; ?>
    </select>
    <button class="btn-ghost btn-sm" type="button" id="ed-sug-scan"><?= t('ed.sug_scan') ?></button>
  </div>
  <p class="ga-desc" style="margin:10px 0 12px"><?= t('ed.sug_intro') ?></p>
  <button class="btn btn-sm" type="button" id="ed-accept-exact" hidden></button>
  <div id="ed-sug-list" aria-live="polite"></div>
</section>

<section class="cp-card">
  <h2><?= t('admin.games.title') ?></h2>

  <!-- System tabs -->
  <div style="display:flex;gap:4px;flex-wrap:wrap;margin-bottom:16px;border-bottom:1px solid var(--border2);padding-bottom:0">
    <?php foreach ($systems as $sy): ?>
    <a href="<?= BASE_URL ?>/settings.php?s=catalogue&amp;sys=<?= $sy['id'] ?>" <?= $sy['id']==$viewSys ? 'aria-current="page"' : '' ?> style="padding:8px 14px;font-size:.68rem;letter-spacing:.1em;text-transform:uppercase;color:<?= $sy['id']==$viewSys?'var(--accent2)':'var(--muted)' ?>;border-bottom:2px solid <?= $sy['id']==$viewSys?'var(--accent2)':'transparent' ?>;white-space:nowrap;margin-bottom:-1px"><?= htmlspecialchars($sy['short_name']) ?></a>
    <?php endforeach; ?>
  </div>

  <!-- Add game -->
  <form method="POST" style="display:flex;gap:8px;margin-bottom:12px;flex-wrap:wrap">
    <input type="hidden" name="csrf"      value="<?= csrf() ?>">
    <input type="hidden" name="action"    value="add_game">
    <input type="hidden" name="system_id" value="<?= $viewSys ?>">
    <input type="text" name="title" placeholder="<?= t('admin.games.title_ph') ?>" aria-label="<?= t('admin.games.title_ph') ?>" style="flex:1;min-width:200px">
    <button class="btn btn-sm" type="submit"><?= t('admin.games.add') ?></button>
  </form>

  <div id="games-admin-wrap">
  <!-- Filter -->
  <div style="display:flex;gap:8px;flex-wrap:wrap;margin-bottom:12px">
    <div class="search-wrap" style="max-width:340px;flex:1">
      <span class="search-icon">⌕</span>
      <input type="text" id="game-filter" placeholder="<?= t('admin.games.filter') ?>" aria-label="<?= t('admin.games.filter') ?>">
    </div>
    <select id="ed-link-filter" aria-label="<?= t('ed.edition') ?>" style="width:auto">
      <option value="all"><?= t('ed.filter_all') ?></option>
      <option value="linked"><?= t('ed.filter_linked') ?></option>
      <option value="unlinked"><?= t('ed.filter_unlinked') ?></option>
    </select>
  </div>

  <!-- Selection bar (manual linking) -->
  <div class="ed-selbar" id="ed-selbar" hidden>
    <span id="ed-sel-count"></span>
    <button class="btn btn-sm" type="button" id="ed-sel-link"><?= t('ed.link') ?></button>
    <span class="ga-desc" id="ed-sel-hint" style="margin:0"></span>
    <button class="btn-ghost btn-sm" type="button" id="ed-sel-clear"><?= t('ed.clear') ?></button>
  </div>

  <table class="admin-table" id="games-admin-table">
    <thead><tr><th><?= t('ed.pick') ?></th><th>#</th><th><?= t('common.col.title') ?></th><th><?= t('ed.edition') ?></th><th><?= t('admin.games.default_image') ?></th><th><?= t('admin.sys.active') ?></th><th><?= t('settings.actions') ?></th></tr></thead>
    <tbody>
    <?php foreach ($listItems as $item): ?>
      <?php if (isset($item['game'])): $gameRow($item['game']); else: $gr = $item['group']; ?>
      <tr class="ed-group-row" data-group-id="<?= $gr['id'] ?>">
        <td></td>
        <td colspan="5">
          <strong><?= htmlspecialchars($gr['title']) ?></strong>
          <span class="chip chip-blue"><?= t('ed.n_editions', ['n' => count($gr['members'])]) ?></span>
        </td>
        <td style="display:flex;gap:6px;flex-wrap:wrap">
          <button class="btn-icon" type="button" onclick="edEditGroup(<?= $gr['id'] ?>)"><?= t('common.edit') ?></button>
          <button class="btn-icon ed-danger" type="button" onclick="edUnlinkGroup(<?= $gr['id'] ?>)"><?= t('ed.unlink') ?></button>
        </td>
      </tr>
      <?php foreach ($gr['members'] as $m) $gameRow($m, true, (int)$m['id'] === (int)$gr['main_game_id']); ?>
      <?php endif; ?>
    <?php endforeach; ?>
    </tbody>
  </table>
  <script type="application/json" id="ed-cat-data"><?= json_encode($edData, JSON_HEX_TAG | JSON_HEX_AMP | JSON_UNESCAPED_UNICODE) ?></script>
  </div>
</section>

<!-- Link dialog (manual linking and editing a group) -->
<div class="modal-backdrop" id="ed-dlg" role="dialog" aria-modal="true" aria-labelledby="ed-dlg-title">
  <div class="modal" style="max-width:640px">
    <div class="modal-header">
      <h3 id="ed-dlg-title"><?= t('ed.link') ?></h3>
      <button class="btn-icon" type="button" onclick="edCloseDialog()" aria-label="<?= t('common.close') ?>">✕</button>
    </div>
    <div class="modal-body">
      <div class="field">
        <label for="ed-dlg-name"><?= t('ed.game_name') ?></label>
        <input type="text" id="ed-dlg-name" maxlength="255">
      </div>
      <table class="admin-table">
        <thead><tr><th><?= t('ed.main') ?></th><th><?= t('ed.col_title') ?></th><th><?= t('ed.col_label') ?></th></tr></thead>
        <tbody id="ed-dlg-rows"></tbody>
      </table>
      <p class="ga-desc" style="margin:0"><?= t('ed.dlg_note') ?></p>
    </div>
    <div class="modal-footer">
      <button class="btn-ghost btn-sm" type="button" onclick="edCloseDialog()"><?= t('common.cancel') ?></button>
      <button class="btn btn-sm" type="button" id="ed-dlg-save"><?= t('ed.btn_link') ?></button>
    </div>
  </div>
</div>

<section class="cp-card">
  <h2><?= t('common.nav.pc_import') ?></h2>
  <p style="font-size:.74rem;color:var(--muted);margin-bottom:14px;line-height:1.7">
    <?= t('admin.pc.desc') ?><br>
    <span style="color:var(--orange)"><?= t('common.currency_note', ['symbol' => setting('currency_symbol')]) ?></span>
  </p>
  <textarea id="pc-csv" aria-label="<?= t('common.nav.pc_import') ?>" style="width:100%;height:130px;background:var(--surface);border:1px solid var(--border2);color:var(--text);font-family:var(--font-body);font-size:.7rem;padding:10px;resize:vertical;outline:none" placeholder="<?= t('pc.placeholder') ?>
console,name,data-product,link,loose,cib,new,coverArt,coverArtBase64
WiiU,007 Legends,63286,https://...,17.51,24.86,40.83,63286.jpg,data:image/jpeg;base64,..."></textarea>
  <div style="display:flex;gap:8px;margin-top:10px;flex-wrap:wrap">
    <button class="btn btn-sm" onclick="pcPreview()"><?= t('import.preview') ?> →</button>
    <a href="<?= BASE_URL ?>/pc_import.php" class="btn-outline" style="padding:8px 14px;font-size:.72rem"><?= t('admin.pc.full_page') ?> ↗</a>
    <a href="<?= BASE_URL ?>/api/pc_example.php" class="btn-outline" style="padding:8px 14px;font-size:.72rem" download>⬇ <?= t('pc.example') ?></a>
  </div>
  <p class="ga-desc" style="margin:8px 0 0"><?= t('pc.example_note') ?></p>
  <div id="pc-result" style="display:none;margin-top:14px;background:var(--surface2);border:1px solid var(--border2);padding:12px 16px;font-size:.75rem;line-height:1.9"></div>
</section>
