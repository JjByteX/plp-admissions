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

$base = ['psa_birth_cert','valid_id_1','valid_id_2','barangay_cert','photo_1'];

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

echo "docs_for_type: all checks passed\n";
