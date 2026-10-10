<?php
// Run: php tests/ai_classify_check.php
//   Offline asserts for the classifier rules (no model needed).
// Run: php tests/ai_classify_check.php <sample-folder> [--type=freshman] [--name="Juan Dela Cruz"] [--birth=2007-01-31] [--flags=married,grade12] [--threshold=80]
//   Also sends every image in the folder to the model and prints a table.
//   Name files <expected category>__anything.jpg (e.g. valid_id__front.jpg). Use "bad__" or "other__" for files that must NOT pass.
//   Needs AI_MODEL_URL and AI_MODEL_KEY in the environment (same values as .env).
// ponytail: plain asserts, no framework; add cases here when rules change.
require_once __DIR__ . '/../config/app.php';
require_once __DIR__ . '/../core/helpers.php';
require_once __DIR__ . '/../core/ai_classify.php';

function check(bool $ok, string $msg): void
{
    if (!$ok) { fwrite(STDERR, "FAIL: $msg\n"); exit(1); }
}

$me  = ['applicant_type' => TYPE_FRESHMAN, 'first_name' => 'Juan', 'middle_name' => 'Santos', 'last_name' => 'Dela Cruz', 'birthdate' => '2007-01-31'];
$cat = ai_categories(TYPE_FRESHMAN, ['married' => true, 'shs_grad' => true]);
$num = fn(string $slug) => array_search($slug, array_column($cat, 'slug')) + 1;
$t80 = ['threshold' => 80];
$ok  = fn(array $f, int $choice, array $extra = []) => array_merge(['choice' => $choice, 'legible' => true, 'reason' => '', 'fields' => $f], $extra);

// -- categories per type and flags
check(array_column(ai_categories(TYPE_FRESHMAN), 'slug') === ['psa_birth_cert', 'valid_id', 'barangay_cert', 'passport_photo'], 'freshman, no flags');
check(in_array('tor', array_column(ai_categories(TYPE_TRANSFEREE), 'slug'), true), 'transferee has tor');
check(!in_array('form_137', array_column(ai_categories(TYPE_TRANSFEREE), 'slug'), true), 'transferee has no form_137');
check(ai_categories(TYPE_FOREIGN) === [], 'foreign gets no AI categories');
check(array_count_values(array_column($cat, 'slug'))['form_137'] === 1 && !in_array('form_138_g12', array_column($cat, 'slug'), true), 'Form 138 (Grade 12) and Form 137 are one category');
check(count(array_filter($cat, fn($c) => in_array('form_137', $c['slots'], true))) === 1, 'only one category fills the form_137 slot');

// -- confidence from the choice token
$lp = [['token' => '{"', 'logprob' => 0], ['token' => 'choice', 'logprob' => 0], ['token' => '":', 'logprob' => 0], ['token' => ' ', 'logprob' => -0.0001], ['token' => '2', 'logprob' => log(0.9)], ['token' => ',', 'logprob' => 0]];
check(ai_choice_confidence($lp) === 90.0, 'confidence reads the digit token');
check(ai_choice_confidence([['token' => 'hi', 'logprob' => 0]]) === null, 'no choice key gives null');

// -- parse
check(ai_parse_reply('nope') === null, 'bad json');
check(ai_parse_reply("```json\n{\"choice\":2,\"legible\":true}\n```")['choice'] === 2, 'fenced json');

// -- name match
check(ai_name_matches('DELA CRUZ, JUAN SANTOS', $me), 'last, first order');
check(ai_name_matches('Juan S. Dela Cruz and Maria Reyes', $me), 'marriage cert with two names');
check(!ai_name_matches('Pedro Reyes', $me), 'different name');
check(!ai_name_matches('', $me), 'blank name');

// -- decisions
$birth = $num('psa_birth_cert');
check(ai_decide($ok(['name' => 'Juan Dela Cruz'], $birth), 95, $cat, $me, $t80)['status'] === 'passed', 'good birth cert passes');
check(ai_decide($ok(['name' => 'Juan Dela Cruz'], $birth), 79.9, $cat, $me, $t80)['status'] === 'uncertain', 'below threshold');
check(ai_decide($ok(['name' => 'Juan Dela Cruz'], $birth), null, $cat, $me, $t80)['status'] === 'uncertain', 'no logprobs');
check(ai_decide($ok(['name' => 'Pedro Reyes'], $birth), 99, $cat, $me, $t80)['status'] === 'uncertain', 'name mismatch');
check(ai_decide($ok(['name' => ''], $birth), 99, $cat, $me, $t80)['status'] === 'uncertain', 'blank key field');
check(ai_decide($ok([], 0), 99, $cat, $me, $t80)['status'] === 'uncertain', 'choice 0');
check(ai_decide($ok([], 99), 99, $cat, $me, $t80)['status'] === 'uncertain', 'choice out of range');
check(ai_decide(null, null, $cat, $me, $t80)['status'] === 'uncertain', 'bad json');
check(ai_decide($ok([], $birth, ['legible' => false]), 99, $cat, $me, $t80)['status'] === 'failed', 'illegible fails');

$id = $num('valid_id');
$idf = ['name' => 'Juan Dela Cruz', 'id_type' => "Driver's License", 'id_number' => 'N01-23-456789'];
check(ai_decide($ok($idf, $id), 95, $cat, $me, $t80)['status'] === 'passed', 'good id');
check(ai_decide($ok(array_merge($idf, ['id_type' => 'Library card']), $id), 95, $cat, $me, $t80)['status'] === 'uncertain', 'id type not accepted');
check(ai_decide($ok($idf, $id), 95, $cat, $me, $t80 + ['other_id_numbers' => ['n01-23-456789']])['status'] === 'uncertain', 'same id number on both ids');

// back of an ID: asks for the front instead of "name not found"
$back = ai_decide($ok(['name' => '', 'id_type' => 'Philippine National ID', 'id_side' => 'back'], $id), 95, $cat, $me, $t80);
check($back['status'] === 'uncertain' && str_contains($back['reason'], 'back of an ID'), 'back of an ID gets its own message');
check(ai_decide($ok(array_merge($idf, ['id_side' => 'front']), $id), 95, $cat, $me, $t80)['status'] === 'passed', 'front of an ID still passes');
check(ai_decide($ok(array_merge($idf, ['id_side' => 'back']), $id), 95, $cat, $me, $t80)['status'] === 'passed', 'a read name wins over a wrong back flag');
check(str_contains(ai_decide($ok(['name' => '', 'id_type' => 'Driver\'s License'], $id), 95, $cat, $me, $t80)['reason'], 'name not found'), 'blank name without a back flag still says name not found');

// the one Form 138 / 137 category passes at normal confidence
$frm = $num('form_137');
check(ai_decide($ok(['name' => 'BASSIG, JJ SANCHEZ'], $frm), 90, $cat, array_merge($me, ['first_name' => 'JJ', 'middle_name' => 'Sanchez', 'last_name' => 'Bassig']), $t80)['status'] === 'passed', 'report card passes');

// prompt: hints and only the fields this applicant needs
$pf = ai_build_prompt($cat, $me);
check(str_contains($pf, 'Punong Barangay') || !in_array('barangay_cert', array_column($cat, 'slug'), true), 'prompt carries the hints');
check(str_contains($pf, '"id_side"') && str_contains($pf, '"id_number"'), 'prompt asks for id fields when valid_id is listed');
check(!str_contains($pf, '"units"') && !str_contains($pf, '"seal"'), 'freshman prompt has no tor fields');
$pt = ai_build_prompt(ai_categories(TYPE_TRANSFEREE), array_merge($me, ['applicant_type' => TYPE_TRANSFEREE]));
check(str_contains($pt, '"units"') && str_contains($pt, '"notation"'), 'transferee prompt has tor fields');
foreach ([$pf, $pt] as $p) {
    check(preg_match('/"fields": (\{[^}]*\})/', $p, $m) === 1 && is_array(json_decode($m[1], true)), 'the fields template in the prompt is valid JSON');
}

$ph = $num('passport_photo');
check(ai_decide($ok(['photos' => 2], $ph), 95, $cat, $me, $t80)['status'] === 'passed', 'two photos pass without a name');
check(ai_decide($ok(['photos' => 0], $ph), 95, $cat, $me, $t80)['status'] === 'uncertain', 'no photos counted');

$tr = ai_categories(TYPE_TRANSFEREE);
$tor = array_search('tor', array_column($tr, 'slug')) + 1;
$torf = ['name' => 'Juan Dela Cruz', 'school' => 'ABC University', 'program' => 'BSIT', 'academic_year' => '2024-2025', 'units' => 24, 'complete' => true, 'seal' => true, 'signature' => true, 'notation' => true];
$tme = array_merge($me, ['applicant_type' => TYPE_TRANSFEREE]);
check(ai_decide($ok($torf, $tor), 95, $tr, $tme, $t80)['status'] === 'passed', 'good tor');
check(ai_decide($ok(array_merge($torf, ['units' => 18]), $tor), 95, $tr, $tme, $t80)['status'] === 'uncertain', 'tor under 21 units');
check(ai_decide($ok(array_merge($torf, ['seal' => false]), $tor), 95, $tr, $tme, $t80)['status'] === 'uncertain', 'tor no seal');

// -- PDF skips the model
check(ai_classify_image('/nope.pdf', 'application/pdf', $me)['status'] === 'uncertain', 'pdf is uncertain');
echo "ai_classify rules: OK\n";

// -- optional: sample set through the real model
$dir = $argv[1] ?? '';
if ($dir === '' || str_starts_with($dir, '--')) exit(0);
check(AI_MODEL_URL !== '', 'AI_MODEL_URL is not set in the environment');

$o = ['type' => 'freshman', 'name' => 'Juan Santos Dela Cruz', 'birth' => '2007-01-31', 'flags' => '', 'threshold' => '80'];
foreach (array_slice($argv, 2) as $arg) {
    if (preg_match('/^--(\w+)=(.*)$/', $arg, $m)) $o[$m[1]] = $m[2];
}
$parts = preg_split('/\s+/', trim($o['name']));
$applicant = [
    'applicant_type' => $o['type'], 'first_name' => $parts[0], 'last_name' => count($parts) > 1 ? end($parts) : '',
    'middle_name' => implode(' ', array_slice($parts, 1, -1)), 'birthdate' => $o['birth'],
    'doc_flags' => array_fill_keys(array_filter(explode(',', $o['flags'])), true),
];
$mimes = ['jpg' => 'image/jpeg', 'jpeg' => 'image/jpeg', 'png' => 'image/png', 'webp' => 'image/webp', 'pdf' => 'application/pdf'];
$rows = [];
$goodConf = []; $badConf = [];
foreach (glob(rtrim($dir, '/\\') . '/*') as $file) {
    $mime = $mimes[strtolower(pathinfo($file, PATHINFO_EXTENSION))] ?? null;
    if (!$mime) continue;
    $expected = explode('__', basename($file))[0];
    $t = microtime(true);
    $r = ai_classify_image($file, $mime, $applicant, ['threshold' => (float)$o['threshold']]);
    $should = !in_array($expected, ['bad', 'other'], true);
    $right = $should ? ($r['guess'] === $expected && $r['status'] === 'passed') : ($r['status'] !== 'passed');
    $rows[] = [basename($file), $expected, (string)$r['guess'], $r['status'], (string)$r['confidence'], sprintf('%.1fs', microtime(true) - $t), $right ? 'ok' : 'WRONG', substr($r['reason'], 0, 60)];
    if ($should && $r['guess'] === $expected) $goodConf[] = $r['confidence']; else $badConf[] = $r['confidence'];
}
array_unshift($rows, ['file', 'expected', 'guess', 'status', 'conf', 'time', 'result', 'reason']);
$w = [];
foreach ($rows as $row) foreach ($row as $i => $c) $w[$i] = max($w[$i] ?? 0, strlen($c));
foreach ($rows as $row) echo implode('  ', array_map(fn($c, $i) => str_pad($c, $w[$i]), $row, array_keys($row))) . "\n";
$wrong = count(array_filter($rows, fn($r) => $r[6] === 'WRONG'));
echo "\n" . (count($rows) - 1) . " files, $wrong wrong\n";
if ($goodConf && $badConf) echo 'Threshold hint: lowest right guess ' . min($goodConf) . ', highest wrong or bad guess ' . max($badConf) . "\n";
