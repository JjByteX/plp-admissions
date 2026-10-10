<?php
// Run: php tests/docs_for_type_check.php
// Checks docs_for_type() for each applicant type and flag combination.
// ponytail: plain asserts, no framework; add cases here when slots change.
require_once __DIR__ . '/../config/app.php';
require_once __DIR__ . '/../core/helpers.php';

function check(bool $ok, string $msg): void
{
    if (!$ok) { fwrite(STDERR, "FAIL: $msg\n"); exit(1); }
}
function slots(string $type, $flags = null): array
{
    $s = array_keys(docs_for_type($type, $flags));
    sort($s);
    return $s;
}
function expect(array $list): array { sort($list); return $list; }

$base = ['psa_birth_cert','valid_id_1','barangay_cert','photo_1'];

// No flags: only always-required slots
check(slots(TYPE_FRESHMAN)   === expect($base), 'freshman, no flags');
check(slots(TYPE_TRANSFEREE) === expect(array_merge($base, ['tor'])), 'transferee, no flags');
check(slots(TYPE_FOREIGN)    === expect(array_merge($base, ['tor','passport','visa_permit','alien_cert'])), 'foreign, no flags');

// Each flag adds its own slot (freshman)
check(slots(TYPE_FRESHMAN, ['married' => true])  === expect(array_merge($base, ['marriage_cert'])), 'married');
check(slots(TYPE_FRESHMAN, ['guardian' => true]) === expect(array_merge($base, ['guardianship_affidavit'])), 'guardian');
check(slots(TYPE_FRESHMAN, ['grade12' => true])  === expect(array_merge($base, ['form_138'])), 'grade12');
check(slots(TYPE_FRESHMAN, ['shs_grad' => true]) === expect(array_merge($base, ['form_137'])), 'shs_grad');

// All flags on
check(slots(TYPE_FRESHMAN, ['married'=>true,'guardian'=>true,'grade12'=>true,'shs_grad'=>true])
    === expect(array_merge($base, ['marriage_cert','guardianship_affidavit','form_138','form_137'])), 'freshman, all flags');

// Freshman-only slots never appear for other types, even with flags on
check(!in_array('form_138', slots(TYPE_TRANSFEREE, ['grade12' => true]), true), 'transferee has no form_138');
check(!in_array('form_137', slots(TYPE_FOREIGN, ['shs_grad' => true]), true), 'foreign has no form_137');

// Married and guardian apply to transferee and foreign too
check(in_array('marriage_cert', slots(TYPE_TRANSFEREE, ['married' => true]), true), 'transferee married');
check(in_array('guardianship_affidavit', slots(TYPE_FOREIGN, ['guardian' => true]), true), 'foreign guardian');

// jsonb string from the database works the same as an array
check(slots(TYPE_FRESHMAN, '{"married":true,"grade12":true}')
    === expect(array_merge($base, ['marriage_cert','form_138'])), 'json string flags');
check(slots(TYPE_FRESHMAN, 'not json') === expect($base), 'bad json falls back to no flags');

// Removed slots are gone everywhere
foreach ([TYPE_FRESHMAN, TYPE_TRANSFEREE, TYPE_FOREIGN] as $t) {
    foreach (['applicant_id','parent_id','proof_of_income','passport_photos','good_moral'] as $old) {
        check(!in_array($old, slots($t, ['married'=>true,'guardian'=>true,'grade12'=>true,'shs_grad'=>true]), true), "$t still has $old");
    }
}

// Grade 12 / SHS graduate is one radio (flags[stage]) for freshmen: exactly one, never both
$g12 = doc_flags_from_input(TYPE_FRESHMAN, ['stage' => 'grade12']);
check($g12['grade12'] === true && $g12['shs_grad'] === false, 'stage grade12');
$shs = doc_flags_from_input(TYPE_FRESHMAN, ['stage' => 'shs_grad', 'married' => '1']);
check($shs['shs_grad'] === true && $shs['grade12'] === false && $shs['married'] === true, 'stage shs_grad keeps the checkboxes');
$bad = doc_flags_from_input(TYPE_FRESHMAN, ['stage' => 'nonsense']);
check($bad['grade12'] === false && $bad['shs_grad'] === false, 'unknown stage is neither');
$tr = doc_flags_from_input(TYPE_TRANSFEREE, ['stage' => 'grade12']);
check($tr['grade12'] === false && $tr['shs_grad'] === false, 'transferee never has a stage');
$keep = doc_flags_from_input(TYPE_FRESHMAN, ['grade12' => true, 'shs_grad' => false]);
check($keep['grade12'] === true, 'input without a stage key keeps stored flags');
check(doc_stage_of($g12) === 'grade12' && doc_stage_of($shs) === 'shs_grad', 'doc_stage_of reads the choice');
check(doc_stage_of([]) === '' && doc_stage_of(null) === '' && doc_stage_of(['married' => true]) === '', 'no stage chosen is empty');
check(doc_stage_of('{"shs_grad":true}') === 'shs_grad', 'doc_stage_of reads a json string');

echo "docs_for_type: all checks passed\n";
