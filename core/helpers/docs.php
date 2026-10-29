<?php
// ============================================================
// core/helpers/docs.php
// Applicant document helpers: required docs per applicant type, upload categories, AI flag review.
// Loaded by core/helpers.php; plain functions, no side effects
// beyond defining them.
// ============================================================

// -- Document helpers -------------------------------------------
// Decode applicants.doc_flags (jsonb string from PDO, array, or null) into
// a clean ['married'=>bool,'guardian'=>bool,'grade12'=>bool,'shs_grad'=>bool].
function doc_flags_decode(array|string|null $flags): array
{
    if (is_string($flags)) {
        $flags = json_decode($flags, true);
    }
    if (!is_array($flags)) {
        $flags = [];
    }
    $out = [];
    foreach (array_unique(array_values(DOCS_CONDITIONAL)) as $key) {
        $out[$key] = !empty($flags[$key]);
    }
    return $out;
}

// Slots that apply to an applicant: type slots minus conditional slots
// whose flag is not ticked. Pass applicants.doc_flags as $flags.
function docs_for_type(string $applicantType, array|string|null $flags = null): array
{
    $docs = DOCS_CORE;
    if ($applicantType === TYPE_FRESHMAN) {
        $docs = array_merge($docs, DOCS_FRESHMAN);
    } elseif ($applicantType === TYPE_TRANSFEREE) {
        $docs = array_merge($docs, DOCS_TRANSFEREE);
    } elseif ($applicantType === TYPE_FOREIGN) {
        $docs = array_merge($docs, DOCS_FOREIGN);
    }
    $on = doc_flags_decode($flags);
    foreach (DOCS_CONDITIONAL as $slug => $flag) {
        if (empty($on[$flag])) {
            unset($docs[$slug]);
        }
    }
    return $docs;
}

// Normalise ticked boxes from a form (e.g. $_POST['flags']) for an applicant type.
// Grade 12 / SHS graduate only apply to freshmen. The forms send them as one radio
// (flags[stage] = grade12 | shs_grad), so exactly one can be on. Input without a
// stage key (stored flags, old forms) keeps its two booleans as they are.
function doc_flags_from_input(string $applicantType, mixed $input): array
{
    $in    = is_array($input) ? $input : [];
    $flags = doc_flags_decode($in);
    if ($applicantType !== TYPE_FRESHMAN) {
        $flags['grade12']  = false;
        $flags['shs_grad'] = false;
    } elseif (isset($in['stage'])) {
        $flags['grade12']  = $in['stage'] === 'grade12';
        $flags['shs_grad'] = $in['stage'] === 'shs_grad';
    }
    return $flags;
}

// A freshman's school stage: 'grade12', 'shs_grad', or '' when neither is chosen.
// A freshman must have one before submitting (they decide Form 138 or Form 137).
function doc_stage_of(array|string|null $flags): string
{
    $on = doc_flags_decode($flags);
    return $on['grade12'] ? 'grade12' : ($on['shs_grad'] ? 'shs_grad' : '');
}

// Make the documents rows match the slots that apply: add missing pending
// rows, remove rows for slots that no longer apply. Approved rows are kept.
// ponytail: an uploaded (not approved) file on a removed slot is dropped from
// the row but stays in storage; upgrade path is a storage cleanup job.
function sync_doc_rows(PDO $db, int $applicantId, array $required): void
{
    $slugs = array_keys($required);
    $stmt = $db->prepare('SELECT doc_type FROM documents WHERE applicant_id = ?');
    $stmt->execute([$applicantId]);
    $have = $stmt->fetchAll(PDO::FETCH_COLUMN);

    $ins = $db->prepare('INSERT INTO documents (applicant_id, doc_type, status) VALUES (?, ?, \'pending\')');
    foreach (array_diff($slugs, $have) as $slug) {
        $ins->execute([$applicantId, $slug]);
    }
    if ($slugs) {
        $in = implode(',', array_fill(0, count($slugs), '?'));
        $db->prepare(
            "DELETE FROM documents
              WHERE applicant_id = ? AND status <> 'approved' AND doc_type NOT IN ($in)"
        )->execute(array_merge([$applicantId], $slugs));
    }
}

// True when every slot that applies to the applicant has an approved file.
// Used by approve / approve-all / advance so extra or old rows never block.
function required_docs_all_approved(PDO $db, int $applicantId): bool
{
    $stmt = $db->prepare('SELECT applicant_type, doc_flags FROM applicants WHERE id = ?');
    $stmt->execute([$applicantId]);
    $a = $stmt->fetch();
    if (!$a) {
        return false;
    }
    $slugs = array_keys(docs_for_type($a['applicant_type'], $a['doc_flags'] ?? null));
    if (!$slugs) {
        return false;
    }
    $in = implode(',', array_fill(0, count($slugs), '?'));
    $stmt = $db->prepare(
        "SELECT COUNT(DISTINCT doc_type) FROM documents
          WHERE applicant_id = ? AND status = 'approved' AND doc_type IN ($in)"
    );
    $stmt->execute(array_merge([$applicantId], $slugs));
    return (int)$stmt->fetchColumn() === count($slugs);
}

// True when every required slot has a file the student actually submitted
// (uploaded, under review or already approved). A missing or rejected slot
// means the submission is incomplete and must not be bulk-approved.
function required_docs_all_submitted(PDO $db, int $applicantId): bool
{
    $stmt = $db->prepare('SELECT applicant_type, doc_flags FROM applicants WHERE id = ?');
    $stmt->execute([$applicantId]);
    $a = $stmt->fetch();
    if (!$a) {
        return false;
    }
    $slugs = array_keys(docs_for_type($a['applicant_type'], $a['doc_flags'] ?? null));
    if (!$slugs) {
        return false;
    }
    $in = implode(',', array_fill(0, count($slugs), '?'));
    $stmt = $db->prepare(
        "SELECT COUNT(DISTINCT doc_type) FROM documents
          WHERE applicant_id = ? AND file_path IS NOT NULL AND file_path <> ''
            AND status IN ('uploaded','under_review','approved') AND doc_type IN ($in)"
    );
    $stmt->execute(array_merge([$applicantId], $slugs));
    return (int)$stmt->fetchColumn() === count($slugs);
}

// Slots that would be dropped by $newFlags but already hold an approved file.
// Returns their labels; empty array means the flag change is allowed.
function doc_flags_blocked_slots(PDO $db, int $applicantId, array $newFlags): array
{
    $stmt = $db->prepare('SELECT applicant_type, doc_flags FROM applicants WHERE id = ?');
    $stmt->execute([$applicantId]);
    $a = $stmt->fetch();
    if (!$a) {
        return [];
    }
    $dropped = array_diff_key(
        docs_for_type($a['applicant_type'], $a['doc_flags'] ?? null),
        docs_for_type($a['applicant_type'], $newFlags)
    );
    if (!$dropped) {
        return [];
    }
    $in = implode(',', array_fill(0, count($dropped), '?'));
    $stmt = $db->prepare(
        "SELECT doc_type FROM documents
          WHERE applicant_id = ? AND status = 'approved' AND doc_type IN ($in)"
    );
    $stmt->execute(array_merge([$applicantId], array_keys($dropped)));
    $blocked = [];
    foreach ($stmt->fetchAll(PDO::FETCH_COLUMN) as $slug) {
        $blocked[] = $dropped[$slug];
    }
    return $blocked;
}

// -- Upload categories (Phase 4) --------------------------------
// A category is a kind of document. A slot name ending in _1 or _2 shares a
// category with its siblings (valid_id_1 and photo_1 are the categories
// valid_id and photo). The ID is one slot: one file showing front and back.
function doc_category_of(string $slot): string
{
    return preg_replace('/_[12]$/', '', $slot);
}

// Group the slots that apply to an applicant by category:
// [category => ['label' => string, 'slots' => [slot, ...]]].
function doc_categories(array $required): array
{
    $out = [];
    foreach ($required as $slot => $label) {
        $cat = doc_category_of($slot);
        if (!isset($out[$cat])) {
            $out[$cat] = ['label' => trim(preg_replace('/\s*\(\d of \d\)/', '', $label)), 'slots' => []];
        }
        $out[$cat]['slots'][] = $slot;
    }
    return $out;
}

// Pick the slot a classified file goes to, or say why it cannot go anywhere.
// $slots = the category's slots, $rows = documents rows keyed by doc_type.
// Returns ['slot' => string|null, 'reason' => string|null, 'replaced' => bool].
// Rules: an empty slot first, then a declined one. A one-slot category lets a
// later file replace the earlier one (replaced = true). A two-slot category
// never replaces: a third file is blocked. Approved slots and a
// submitted application never take a file, except declined slots.
function doc_pick_slot(array $slots, array $rows, bool $isSubmitted): array
{
    $declined = ['rejected', 'resubmission_required'];
    $pending  = [];
    $redo     = [];
    $uploaded = [];
    $approved = 0;
    foreach ($slots as $slot) {
        $status = $rows[$slot]['status'] ?? 'pending';
        if ($status === 'approved') {
            $approved++;
            continue;
        }
        if (in_array($status, $declined, true)) {
            $redo[] = $slot;
            continue;
        }
        if ($isSubmitted) {
            continue;
        }
        if ($status === 'pending') {
            $pending[]  = $slot;
        }
        if ($status === 'uploaded') {
            $uploaded[] = $slot;
        }
    }
    $slot = $pending[0] ?? $redo[0] ?? null;
    if ($slot !== null) {
        return ['slot' => $slot, 'reason' => null, 'replaced' => false];
    }
    if (count($slots) === 1 && $uploaded) {
        return ['slot' => $uploaded[0], 'reason' => null, 'replaced' => true];
    }
    $why = 'All slots for this document are already filled.';
    if ($slots && $approved === count($slots)) {
        $why = 'This document is already approved.';
    } elseif ($isSubmitted) {
        $why = 'Your application is submitted. Only declined documents can be replaced.';
    } elseif (count($slots) === 2) {
        $why = 'Both slots for this document are already filled.';
    }
    return ['slot' => null, 'reason' => $why, 'replaced' => false];
}

// Turn what the applicant picked into one slot. $pick is a slot (psa_birth_cert,
// valid_id_1) or a category (valid_id, photo); a category follows the 4B slot rules.
// Returns ['slot' => string|null, 'reason' => string|null].
function doc_resolve_pick(string $pick, array $required, array $rows, bool $isSubmitted): array
{
    if (isset($required[$pick])) {
        return ['slot' => $pick, 'reason' => null];
    }
    $cats = doc_categories($required);
    if (!isset($cats[$pick])) {
        return ['slot' => null, 'reason' => 'Invalid document type.'];
    }
    $p = doc_pick_slot($cats[$pick]['slots'], $rows, $isSubmitted);
    return ['slot' => $p['slot'], 'reason' => $p['reason']];
}

// Pick up to $want slots for one file (two when one image holds two photos).
// Falls back to fewer when fewer slots are open.
// Returns ['slots' => [slot, ...], 'replaced' => bool, 'reason' => string|null].
function doc_pick_slots(array $slots, array $rows, bool $isSubmitted, int $want = 1): array
{
    $picked   = [];
    $replaced = false;
    $reason   = null;
    for ($i = 0, $n = max(1, min($want, count($slots))); $i < $n; $i++) {
        $p = doc_pick_slot($slots, $rows, $isSubmitted);
        if ($p['slot'] === null) {
            $reason = $p['reason'];
            break;
        }
        $picked[]  = $p['slot'];
        $replaced  = $replaced || $p['replaced'];
        $rows[$p['slot']] = ['status' => 'uploaded'];
    }
    return ['slots' => $picked, 'replaced' => $replaced, 'reason' => $picked ? null : $reason];
}

// Run the Phase 3 classifier (core/ai_classify.php) on one file.
// Never throws. Returns status (passed|uncertain|failed), guess, confidence,
// reason, fields and slots (the document slots the guess can fill).
function classify_upload(string $path, string $mime, array $applicant): array
{
    $none = ['status' => 'uncertain', 'guess' => null, 'confidence' => null,
             'reason' => 'AI unavailable. Please pick the document type.', 'fields' => [], 'slots' => []];
    $lib = CORE_PATH . '/ai_classify.php';
    if (is_file($lib)) {
        require_once $lib;
    }
    if (!function_exists('ai_classify_image')) {
        return $none;
    }
    try {
        $r = ai_classify_image($path, $mime, $applicant);
    } catch (Throwable $e) {
        error_log('AI classify failed: ' . $e->getMessage());
        return $none;
    }
    if (!in_array($r['status'] ?? '', ['passed', 'uncertain', 'failed'], true)) {
        return $none;
    }
    return [
        'status'     => $r['status'],
        'guess'      => isset($r['guess']) && $r['guess'] !== '' ? (string)$r['guess'] : null,
        'confidence' => isset($r['confidence']) ? (float)$r['confidence'] : null,
        'reason'     => (string)($r['reason'] ?? ''),
        'fields'     => is_array($r['fields'] ?? null) ? $r['fields'] : [],
        'slots'      => is_array($r['slots'] ?? null) ? array_values($r['slots']) : [],
    ];
}

// -- AI flag review (Phase 6) -----------------------------------
// A document is flagged when the newest validation row of a document that
// has a file is ai + uncertain and no reviewer has set review_result yet.
// Pure: turns one validation row into flag info, or null when not flagged.
// source is applicant_pick (dropdown pick) or low_confidence (anything else).
function doc_ai_flag_from_row(array $row): ?array
{
    if (($row['validation_type'] ?? '') !== 'ai' || ($row['status'] ?? '') !== 'uncertain') {
        return null;
    }
    if (!empty($row['review_result'])) {
        return null;
    }
    $d = json_decode((string)($row['details'] ?? ''), true);
    if (!is_array($d)) {
        $d = [];
    }
    $pick  = isset($d['applicant_pick']) && $d['applicant_pick'] !== '' ? (string)$d['applicant_pick'] : null;
    $guess = $d['ai_guess'] ?? $d['category'] ?? null;
    return [
        'validation_id' => (int)($row['id'] ?? 0),
        'source'        => $pick !== null ? 'applicant_pick' : 'low_confidence',
        'pick'          => $pick,
        'guess'         => $guess !== null && $guess !== '' ? (string)$guess : null,
        'confidence'    => isset($row['confidence']) ? (float)$row['confidence'] : null,
    ];
}

// Flagged documents of one applicant: [document_id => flag info].
function doc_ai_flags(PDO $db, int $applicantId): array
{
    $stmt = $db->prepare(
        'SELECT DISTINCT ON (v.document_id)
                v.id, v.document_id, v.validation_type, v.status, v.confidence, v.details, v.review_result
           FROM document_validations v
           JOIN documents d ON d.id = v.document_id
          WHERE d.applicant_id = ? AND d.file_path IS NOT NULL
          ORDER BY v.document_id, v.id DESC'
    );
    $stmt->execute([$applicantId]);
    $out = [];
    foreach ($stmt->fetchAll() as $row) {
        $flag = doc_ai_flag_from_row($row);
        if ($flag) {
            $out[(int)$row['document_id']] = $flag;
        }
    }
    return $out;
}

// Mark a flag reviewed: 'approved' (category OK) or 'corrected' (type changed).
// $moveToDocId re-points the validation row to the slot the file moved to.
function doc_ai_confirm(PDO $db, int $validationId, string $result, int $staffId, ?int $moveToDocId = null): void
{
    $db->prepare(
        'UPDATE document_validations
            SET review_result = ?, reviewed_by = ?, reviewed_at = NOW(), document_id = COALESCE(?, document_id)
          WHERE id = ? AND review_result IS NULL'
    )->execute([$result, $staffId, $moveToDocId, $validationId]);
}

// Approve every uploaded / under review document of an applicant except the
// flagged ones. Returns ['approved' => n, 'skipped' => n, 'ids' => [document ids
// approved]]. AI never approves: this only runs from a staff action.
function doc_approve_unflagged(PDO $db, int $applicantId, int $staffId): array
{
    $skip    = array_keys(doc_ai_flags($db, $applicantId));
    $sql     = "UPDATE documents SET status = 'approved', staff_remarks = NULL, reviewed_by = ?
                 WHERE applicant_id = ? AND status IN ('uploaded','under_review')";
    $params  = [$staffId, $applicantId];
    $skipped = 0;
    if ($skip) {
        $in = implode(',', array_fill(0, count($skip), '?'));
        $c  = $db->prepare(
            "SELECT COUNT(*) FROM documents
              WHERE applicant_id = ? AND status IN ('uploaded','under_review') AND id IN ($in)"
        );
        $c->execute(array_merge([$applicantId], $skip));
        $skipped = (int)$c->fetchColumn();
        $sql    .= " AND id NOT IN ($in)";
        $params  = array_merge($params, $skip);
    }
    $stmt = $db->prepare($sql . ' RETURNING id');
    $stmt->execute($params);
    $ids = array_map('intval', $stmt->fetchAll(PDO::FETCH_COLUMN));
    return ['approved' => count($ids), 'skipped' => $skipped, 'ids' => $ids];
}

// Move an applicant to the exam stage once every required document is approved.
// Only applicants still before the exam stage move; a released or withdrawn
// applicant is never pulled back. Returns true only when the status really
// changed, so callers notify the student once and not on every re-approval.
function advance_to_exam_if_docs_approved(PDO $db, int $applicantId): bool
{
    if (!required_docs_all_approved($db, $applicantId)) {
        return false;
    }
    $stmt = $db->prepare(
        'UPDATE applicants
            SET overall_status = \'exam\',
                documents_approved_at = COALESCE(documents_approved_at, NOW())
          WHERE id = ?
            AND overall_status IN (\'pending\',\'documents\',\'submitted\')'
    );
    $stmt->execute([$applicantId]);
    return $stmt->rowCount() > 0;
}
