<?php
/* ═══════════════════════════════════════════
   PHOTO TAGS — which part unit, and which of its recorded defects, a copy's photo shows.
   Evidence for the shared condition report; tags never change a score. Loaded by core.php.

   Tags use stable keys, because writeEntryParts() re-creates every part/unit/defect row on each save:
     part_ref  'c<profile component id>' for profile parts, 'o:<own item name, lower case>' for the owner's
               own items ('~2', '~3' … when a name repeats, in grading order), '' = general / overview
     unit_no   1-based unit of that part (a part with qty 2 has units 1 and 2)
     defect id grade_defects.id
   Mirror of GradingCore.partRefs() in assets/js/grading.js.
   Tags that no longer match the grading (part removed, profile switched, qty lowered, defect set to 0)
   are ignored when read and deleted on the copy's next grading save (photoTagsPrune()).
   ═══════════════════════════════════════════ */

/** Stable keys of a copy's parts, in the same order as $parts (from loadEntryGrading() or gradeScoreParts()). */
function photoPartRefs(array $parts): array {
    $out = []; $seen = [];
    foreach (array_values($parts) as $p) {
        if (($p['pc'] ?? null) !== null && $p['pc'] !== '') { $out[] = 'c' . (int)$p['pc']; continue; }
        $base = 'o:' . mb_substr(mb_strtolower(trim((string)($p['name'] ?? '')), 'UTF-8'), 0, 150);
        $n = $seen[$base] = ($seen[$base] ?? 0) + 1;
        $out[] = $n > 1 ? $base . '~' . $n : $base;
    }
    return $out;
}

/** Valid tag targets of a copy's grading: [part_ref => [unit_no => [defect_id => count]]] (parts with qty > 0). */
function photoTagUnitsFromParts(array $parts): array {
    $refs = photoPartRefs($parts);
    $out = [];
    foreach (array_values($parts) as $i => $p) {
        if ((int)($p['qty'] ?? 0) <= 0) continue;
        foreach (array_values($p['units'] ?? []) as $u => $unit) {
            $d = [];
            foreach ((array)($unit['d'] ?? []) as $did => $n) if ((int)$n > 0) $d[(int)$did] = (int)$n;
            $out[$refs[$i]][$u + 1] = $d;
        }
    }
    return $out;
}

function photoTagValidUnits(int $entryId): array {
    return photoTagUnitsFromParts(loadEntryGrading([$entryId])[$entryId] ?? []);
}

/** Stored tags: [entry_id => [photo_id => ['part_ref', 'unit_no', 'defects' => [ids]]]]. */
function photoTagsLoad(array $entryIds): array {
    $entryIds = array_values(array_unique(array_filter(array_map('intval', $entryIds))));
    if (!$entryIds) return [];
    $in = implode(',', $entryIds);
    $out = []; $byTag = [];
    foreach (db()->query("SELECT id, photo_id, entry_id, part_ref, unit_no FROM copy_photo_tags WHERE entry_id IN ($in)") as $r) {
        $out[(int)$r['entry_id']][(int)$r['photo_id']] = ['part_ref' => $r['part_ref'], 'unit_no' => (int)$r['unit_no'], 'defects' => []];
        $byTag[(int)$r['id']] = [(int)$r['entry_id'], (int)$r['photo_id']];
    }
    if ($byTag) {
        foreach (db()->query("SELECT tag_id, defect_id FROM copy_photo_tag_defects WHERE tag_id IN (" . implode(',', array_keys($byTag)) . ") ORDER BY defect_id") as $r) {
            [$e, $p] = $byTag[(int)$r['tag_id']];
            $out[$e][$p]['defects'][] = (int)$r['defect_id'];
        }
    }
    return $out;
}

/**
 * Tags of some copies, keeping only what matches their current grading:
 * [entry_id => [photo_id => {part_ref, unit_no, defects}]]. $grading: entry id → parts (loadEntryGrading()), loaded when null.
 */
function photoTagsForEntries(array $entryIds, ?array $grading = null): array {
    $raw = photoTagsLoad($entryIds);
    if (!$raw) return [];
    $grading ??= loadEntryGrading(array_keys($raw));
    $out = [];
    foreach ($raw as $eid => $tags) {
        $valid = photoTagUnitsFromParts($grading[$eid] ?? []);
        foreach ($tags as $pid => $t) {
            if ($t['part_ref'] === '') { $out[$eid][$pid] = ['part_ref' => '', 'unit_no' => 1, 'defects' => []]; continue; }
            $unit = $valid[$t['part_ref']][$t['unit_no']] ?? null;
            if ($unit === null) continue;
            $t['defects'] = array_values(array_filter($t['defects'], fn($d) => isset($unit[$d])));
            $out[$eid][$pid] = $t;
        }
    }
    return $out;
}

/**
 * A copy's tags as the condition report stores them: {photo_id: {ref, unit_no, defects}} ('' ref = overview),
 * in the order of $photoIds, only for photos in that list.
 */
function photoTagsReportMap(int $entryId, array $photoIds): array {
    $tags = photoTagsForEntries([$entryId])[$entryId] ?? [];
    $out = [];
    foreach ($photoIds as $pid) if (isset($tags[(int)$pid])) {
        $t = $tags[(int)$pid];
        $d = $t['defects']; sort($d);
        $out[(int)$pid] = ['ref' => $t['part_ref'], 'unit_no' => $t['unit_no'], 'defects' => $d];
    }
    return $out;
}

/** The photos of some copies in display order: [entry_id => ['photos' => [files], 'photo_items' => [{id, file}]]]. */
function entryPhotoData(array $entryIds): array {
    $entryIds = array_values(array_unique(array_filter(array_map('intval', $entryIds))));
    if (!$entryIds) return [];
    $out = [];
    foreach (db()->query("SELECT id, entry_id, filename FROM copy_photos WHERE entry_id IN (" . implode(',', $entryIds) . ") ORDER BY entry_id, sort_order, id") as $r) {
        $out[(int)$r['entry_id']]['photos'][] = $r['filename'];
        $out[(int)$r['entry_id']]['photo_items'][] = ['id' => (int)$r['id'], 'file' => $r['filename']];
    }
    return $out;
}

/**
 * Sets (or with $tag null, removes) one photo's tag. $tag: {part_ref, unit_no, defects:[ids]}.
 * Part tags must point at a unit of the copy's current grading; only defects recorded on that unit are kept.
 * Throws DomainException when the photo isn't this user's photo of this copy, or the target doesn't exist.
 * The caller runs it inside a transaction.
 */
function photoTagSet(int $entryId, int $userId, int $photoId, ?array $tag, ?array $valid = null): void {
    $pdo = db();
    $st = $pdo->prepare("SELECT 1 FROM copy_photos WHERE id=? AND entry_id=? AND user_id=?");
    $st->execute([$photoId, $entryId, $userId]);
    if (!$st->fetchColumn()) throw new DomainException(tRaw('api.photo_not_found'));
    $pdo->prepare("DELETE FROM copy_photo_tags WHERE photo_id=?")->execute([$photoId]);
    if ($tag === null) return;

    $ref = mb_substr((string)($tag['part_ref'] ?? ''), 0, 160);
    $unitNo = 1; $defects = [];
    if ($ref !== '') {
        $valid ??= photoTagValidUnits($entryId);
        $unitNo = (int)($tag['unit_no'] ?? 0);
        $unit = $valid[$ref][$unitNo] ?? null;
        if ($unit === null) throw new DomainException(tRaw('pt.err_invalid'));
        foreach ((array)($tag['defects'] ?? []) as $d) if (isset($unit[(int)$d])) $defects[(int)$d] = true;
    }
    $pdo->prepare("INSERT INTO copy_photo_tags (photo_id, entry_id, part_ref, unit_no) VALUES (?,?,?,?)")->execute([$photoId, $entryId, $ref, $unitNo]);
    $tagId = (int)$pdo->lastInsertId();
    $ins = $pdo->prepare("INSERT INTO copy_photo_tag_defects (tag_id, defect_id) VALUES (?,?)");
    foreach (array_keys($defects) as $d) $ins->execute([$tagId, $d]);
}

/**
 * After a grading save (called by saveEntryGrading()): deletes tags whose part unit is gone and defect links
 * whose defect is no longer recorded on that unit. Overview tags stay; nothing happens for simple-graded copies.
 */
function photoTagsPrune(int $entryId): void {
    $st = db()->prepare("SELECT grade_method FROM collection_entries WHERE id=?");
    $st->execute([$entryId]);
    if ($st->fetchColumn() !== 'points') return;
    $tags = photoTagsLoad([$entryId])[$entryId] ?? [];
    if (!$tags) return;
    $valid = photoTagValidUnits($entryId);
    $delTag = db()->prepare("DELETE FROM copy_photo_tags WHERE photo_id=?");
    $delDef = db()->prepare("DELETE d FROM copy_photo_tag_defects d JOIN copy_photo_tags t ON t.id = d.tag_id WHERE t.photo_id=? AND d.defect_id=?");
    foreach ($tags as $pid => $t) {
        if ($t['part_ref'] === '') continue;
        $unit = $valid[$t['part_ref']][$t['unit_no']] ?? null;
        if ($unit === null) { $delTag->execute([$pid]); continue; }
        foreach ($t['defects'] as $d) if (!isset($unit[$d])) $delDef->execute([$pid, $d]);
    }
}

/**
 * Board 2 (from a defect): the photos that show defect $defectId of unit $ref/$unitNo become exactly $photoIds.
 * Untagged photos get tagged to that unit; a photo tagged to another unit (or overview) makes the whole call fail.
 * Photos no longer in the list lose only this defect. The caller runs it inside a transaction.
 */
function photoTagsSetDefectPhotos(int $entryId, int $userId, string $ref, int $unitNo, int $defectId, array $photoIds): void {
    $valid = photoTagValidUnits($entryId);
    if (!isset($valid[$ref][$unitNo][$defectId])) throw new DomainException(tRaw('pt.err_invalid'));
    $tags = photoTagsLoad([$entryId])[$entryId] ?? [];
    $want = array_values(array_unique(array_map('intval', $photoIds)));
    foreach ($want as $pid) {
        $t = $tags[$pid] ?? null;
        if ($t && ($t['part_ref'] !== $ref || $t['unit_no'] !== $unitNo)) throw new DomainException(tRaw('pt.err_other_part'));
    }
    foreach ($want as $pid) {
        $t = $tags[$pid] ?? ['part_ref' => $ref, 'unit_no' => $unitNo, 'defects' => []];
        if (!in_array($defectId, $t['defects'], true)) $t['defects'][] = $defectId;
        photoTagSet($entryId, $userId, $pid, $t, $valid);
    }
    foreach ($tags as $pid => $t) {
        if (in_array($pid, $want, true) || $t['part_ref'] !== $ref || $t['unit_no'] !== $unitNo || !in_array($defectId, $t['defects'], true)) continue;
        $t['defects'] = array_values(array_diff($t['defects'], [$defectId]));
        photoTagSet($entryId, $userId, $pid, $t, $valid);
    }
}
