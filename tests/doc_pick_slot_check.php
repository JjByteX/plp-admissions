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
check(doc_category_of('photo_2') === 'photo', 'photo_2 category');
check(doc_category_of('form_138') === 'form_138', 'form_138 is its own category');
check(doc_category_of('tor') === 'tor', 'tor is its own category');

// Grouping
$cats = doc_categories(docs_for_type(TYPE_FRESHMAN, ['grade12' => true]));
check($cats['valid_id']['slots'] === ['valid_id_1', 'valid_id_2'], 'valid_id has two slots');
check($cats['photo']['slots'] === ['photo_1', 'photo_2'], 'photo has two slots');
check($cats['psa_birth_cert']['slots'] === ['psa_birth_cert'], 'psa has one slot');
check(!str_contains($cats['valid_id']['label'], '(1 of 2)'), 'category label drops the (1 of 2)');
check(isset($cats['form_138']) && !isset($cats['form_137']), 'only ticked conditional categories');

$id = ['valid_id_1', 'valid_id_2'];

// First empty slot wins; a missing row counts as pending
check(doc_pick_slot($id, [], false)['slot'] === 'valid_id_1', 'empty goes to slot 1');
check(doc_pick_slot($id, rows(['valid_id_1' => 'uploaded', 'valid_id_2' => 'pending']), false)['slot'] === 'valid_id_2', 'second ID goes to slot 2');

// Both filled: a third ID is blocked (two-slot categories never replace)
$full = doc_pick_slot($id, rows(['valid_id_1' => 'uploaded', 'valid_id_2' => 'uploaded']), false);
check($full['slot'] === null && str_contains($full['reason'], 'Both'), 'third ID is blocked');
$ph = doc_pick_slot(['photo_1', 'photo_2'], rows(['photo_1' => 'uploaded', 'photo_2' => 'uploaded']), false);
check($ph['slot'] === null, 'third photo is blocked');

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
check(doc_pick_slot($id, rows(['valid_id_1' => 'rejected', 'valid_id_2' => 'pending']), false)['slot'] === 'valid_id_2', 'pending before declined');

// 4B: one-slot category, a later file replaces the earlier one
$rep = doc_pick_slot(['tor'], rows(['tor' => 'uploaded']), false);
check($rep['slot'] === 'tor' && $rep['replaced'] === true, 'one-slot replaces');
check(doc_pick_slot(['tor'], rows(['tor' => 'pending']), false)['replaced'] === false, 'empty is not a replace');
check(doc_pick_slot(['tor'], rows(['tor' => 'uploaded']), true)['slot'] === null, 'no replace after submit');
check(doc_pick_slot(['tor'], rows(['tor' => 'under_review']), false)['slot'] === null, 'no replace while under review');

// 4B: IDs fill slot 1 then slot 2 as separate files
check(doc_pick_slots($id, [], false)['slots'] === ['valid_id_1'], 'first ID');
check(doc_pick_slots($id, rows(['valid_id_1' => 'uploaded']), false)['slots'] === ['valid_id_2'], 'second ID');

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
check(doc_resolve_pick('valid_id', $req, rows(['valid_id_1' => 'uploaded']), false)['slot'] === 'valid_id_2', 'pick category, second slot');
$third = doc_resolve_pick('valid_id', $req, rows(['valid_id_1' => 'uploaded', 'valid_id_2' => 'uploaded']), false);
check($third['slot'] === null && $third['reason'] !== null, 'pick category, third blocked');
check(doc_resolve_pick('tor', $req, [], false)['slot'] === null, 'pick a slot that does not apply');
check(doc_resolve_pick('nonsense', $req, [], false)['reason'] === 'Invalid document type.', 'pick unknown');

echo "doc_pick_slot: all checks passed\n";
