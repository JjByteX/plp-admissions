<?php
// Run: php tests/doc_pick_slot_check.php
// Checks doc_category_of(), doc_categories(), doc_pick_slot(), doc_pick_slots() and doc_resolve_pick() (Phase 4A to 4C).
// ponytail: plain asserts, no framework; add cases here when slot rules change (4B).
require_once __DIR__ . '/../config/app.php';
require_once __DIR__ . '/../core/helpers.php';

function check(bool $ok, string $msg): void
{
    if (!$ok) { fwrite(STDERR, "FAIL: $msg\n"); exit(1); }
}
function rows(array $m): array
{
    $o = [];
    foreach ($m as $slot => $status) $o[$slot] = ['status' => $status];
    return $o;
}

// Category names
check(doc_category_of('valid_id_1') === 'valid_id', 'valid_id_1 category');
check(doc_category_of('photo_1') === 'photo', 'photo_1 category');
check(doc_category_of('form_138') === 'form_138', 'form_138 is its own category');
check(doc_category_of('tor') === 'tor', 'tor is its own category');

// Grouping
$cats = doc_categories(docs_for_type(TYPE_FRESHMAN, ['grade12' => true]));
check($cats['valid_id']['slots'] === ['valid_id_1'], 'valid_id has one slot (front and back in one file)');
check($cats['photo']['slots'] === ['photo_1'], 'photo has one slot');
check($cats['psa_birth_cert']['slots'] === ['psa_birth_cert'], 'psa has one slot');
check($cats['valid_id']['label'] === 'Valid Government-issued ID (front and back)', 'category label keeps (front and back)');
check(isset($cats['form_138']) && !isset($cats['form_137']), 'only ticked conditional categories');

// Generic two-slot rules (no document uses two slots now, the rules still hold)
$id = ['doc_1', 'doc_2'];

// First empty slot wins; a missing row counts as pending
check(doc_pick_slot($id, [], false)['slot'] === 'doc_1', 'empty goes to slot 1');
check(doc_pick_slot($id, rows(['doc_1' => 'uploaded', 'doc_2' => 'pending']), false)['slot'] === 'doc_2', 'second file goes to slot 2');

// Both filled: a third file is blocked (two-slot categories never replace)
$full = doc_pick_slot($id, rows(['doc_1' => 'uploaded', 'doc_2' => 'uploaded']), false);
check($full['slot'] === null && str_contains($full['reason'], 'Both'), 'third file is blocked');
$ph = doc_pick_slot(['photo_1'], rows(['photo_1' => 'uploaded']), false);
check($ph['slot'] === 'photo_1' && $ph['replaced'], 'a new photo replaces the earlier one');

// Approved
$ok = doc_pick_slot(['psa_birth_cert'], rows(['psa_birth_cert' => 'approved']), false);
check($ok['slot'] === null && str_contains($ok['reason'], 'approved'), 'approved is blocked');

// Declined slots can be refilled, before or after submit
check(doc_pick_slot(['tor'], rows(['tor' => 'rejected']), false)['slot'] === 'tor', 'declined refills');
check(doc_pick_slot(['tor'], rows(['tor' => 'resubmission_required']), true)['slot'] === 'tor', 'declined refills after submit');

// Submitted and not declined
$sub = doc_pick_slot(['tor'], rows(['tor' => 'pending']), true);
check($sub['slot'] === null && str_contains($sub['reason'], 'submitted'), 'submitted is blocked');

// Pending is preferred over declined
check(doc_pick_slot($id, rows(['doc_1' => 'rejected', 'doc_2' => 'pending']), false)['slot'] === 'doc_2', 'pending before declined');

// 4B: one-slot category, a later file replaces the earlier one
$rep = doc_pick_slot(['tor'], rows(['tor' => 'uploaded']), false);
check($rep['slot'] === 'tor' && $rep['replaced'] === true, 'one-slot replaces');
check(doc_pick_slot(['tor'], rows(['tor' => 'pending']), false)['replaced'] === false, 'empty is not a replace');
check(doc_pick_slot(['tor'], rows(['tor' => 'uploaded']), true)['slot'] === null, 'no replace after submit');
check(doc_pick_slot(['tor'], rows(['tor' => 'under_review']), false)['slot'] === null, 'no replace while under review');

// 4B: two-slot rules fill slot 1 then slot 2 as separate files
check(doc_pick_slots($id, [], false)['slots'] === ['doc_1'], 'first file');
check(doc_pick_slots($id, rows(['doc_1' => 'uploaded']), false)['slots'] === ['doc_2'], 'second file');

// The ID is one slot: a new file replaces the earlier one
$idr = doc_pick_slots(['valid_id_1'], rows(['valid_id_1' => 'uploaded']), false);
check($idr['slots'] === ['valid_id_1'] && $idr['replaced'] === true, 'a new ID file replaces the earlier one');

// 4B: one image with two photos fills both slots
$two = doc_pick_slots(['photo_1', 'photo_2'], [], false, 2);
check($two['slots'] === ['photo_1', 'photo_2'], 'two photos fill both slots');
$one = doc_pick_slots(['photo_1', 'photo_2'], rows(['photo_1' => 'uploaded']), false, 2);
check($one['slots'] === ['photo_2'], 'two photos but one open slot fills one');
$none = doc_pick_slots(['photo_1', 'photo_2'], rows(['photo_1' => 'uploaded', 'photo_2' => 'uploaded']), false, 2);
check($none['slots'] === [] && $none['reason'] !== null, 'two photos, none open');
check(count(doc_pick_slots(['tor'], rows(['tor' => 'uploaded']), false, 2)['slots']) === 1, 'one-slot never takes two');

// 4C: dropdown pick, by slot or by category
$req = docs_for_type(TYPE_FRESHMAN);
check(doc_resolve_pick('psa_birth_cert', $req, [], false)['slot'] === 'psa_birth_cert', 'pick by slot');
check(doc_resolve_pick('valid_id', $req, [], false)['slot'] === 'valid_id_1', 'pick category, first empty slot');
check(doc_resolve_pick('valid_id', $req, rows(['valid_id_1' => 'uploaded']), false)['slot'] === 'valid_id_1', 'pick category, replaces the earlier ID');
$done = doc_resolve_pick('valid_id', $req, rows(['valid_id_1' => 'approved']), false);
check($done['slot'] === null && $done['reason'] !== null, 'pick category, approved ID is blocked');
check(doc_resolve_pick('tor', $req, [], false)['slot'] === null, 'pick a slot that does not apply');
check(doc_resolve_pick('nonsense', $req, [], false)['reason'] === 'Invalid document type.', 'pick unknown');

echo "doc_pick_slot: all checks passed\n";
