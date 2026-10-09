<?php
// Run: php tests/doc_ai_flags_check.php
// Checks doc_ai_flag_from_row() (Phase 6): which validation rows count as an AI flag.
// ponytail: plain asserts, no framework. The DB queries (doc_ai_flags, doc_approve_unflagged)
// are covered by the manual review test in 8.4/8.5.
require_once __DIR__ . '/../config/app.php';
require_once __DIR__ . '/../core/helpers.php';

function check(bool $ok, string $msg): void
{
    if (!$ok) { fwrite(STDERR, "FAIL: $msg\n"); exit(1); }
}

$pick = doc_ai_flag_from_row([
    'id' => 7, 'validation_type' => 'ai', 'status' => 'uncertain', 'confidence' => null, 'review_result' => null,
    'details' => json_encode(['applicant_pick' => 'valid_id', 'ai_guess' => 'barangay_cert']),
]);
check($pick !== null, 'ai uncertain row is flagged');
check($pick['source'] === 'applicant_pick', 'dropdown pick source');
check($pick['pick'] === 'valid_id' && $pick['guess'] === 'barangay_cert', 'pick and guess kept');
check($pick['validation_id'] === 7, 'validation id kept');

$low = doc_ai_flag_from_row([
    'id' => 8, 'validation_type' => 'ai', 'status' => 'uncertain', 'confidence' => '55.00', 'review_result' => null,
    'details' => json_encode(['category' => 'tor']),
]);
check($low !== null && $low['source'] === 'low_confidence', 'no pick means low confidence');
check($low['guess'] === 'tor' && $low['confidence'] === 55.0, 'category and confidence read');

$noGuess = doc_ai_flag_from_row(['id' => 9, 'validation_type' => 'ai', 'status' => 'uncertain', 'details' => 'not json']);
check($noGuess !== null && $noGuess['guess'] === null, 'bad details still flags, no guess');

check(doc_ai_flag_from_row(['validation_type' => 'ai', 'status' => 'passed']) === null, 'ai passed is not flagged');
check(doc_ai_flag_from_row(['validation_type' => 'file_check', 'status' => 'passed']) === null, 'file_check clears the flag');
check(doc_ai_flag_from_row(['validation_type' => 'ai', 'status' => 'uncertain', 'review_result' => 'approved']) === null, 'approved review is not flagged');
check(doc_ai_flag_from_row(['validation_type' => 'ai', 'status' => 'uncertain', 'review_result' => 'corrected']) === null, 'corrected review is not flagged');

echo "doc_ai_flags_check: ok\n";
