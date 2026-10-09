<?php
// ============================================================
// core/ai_classify.php
// Classify ONE admission document image with the local model
// (Qwen3-VL via llama-server, reached through the tunnel).
// Never approves or rejects a document. Result status is
// 'passed', 'uncertain' or 'failed'. The caller decides what to save.
// Needs config/app.php (slots, AI_* constants) and core/helpers.php.
// ============================================================

// AI category => label for the prompt, and the document slots it can fill.
// ponytail: a hand-written map; add a row when a new slot is added.
function ai_category_map(): array
{
    return [
        'psa_birth_cert'         => ['PSA birth certificate',                                   ['psa_birth_cert']],
        'marriage_cert'          => ['Marriage certificate',                                    ['marriage_cert']],
        'valid_id'               => ['Government-issued ID (school ID, National ID, PhilHealth, driver\'s license, voter\'s ID or certificate, UMID, PWD ID, PRC ID)', ['valid_id_1', 'valid_id_2']],
        'barangay_cert'          => ['Barangay certificate of residence',                       ['barangay_cert']],
        'guardianship_affidavit' => ['Affidavit of guardianship or support',                    ['guardianship_affidavit']],
        'passport_photo'         => ['Passport-size photo (one or two photos on a white background)', ['photo_1', 'photo_2']],
        'form_138_g11'           => ['Certified true copy of Form 138 (Grade 11 report card)',  ['form_138']],
        'form_138_g12'           => ['Form 138 (Grade 12 report card)',                         ['form_137']],
        'form_137'               => ['Form 137 with the remark "For Evaluation Purposes Only"', ['form_137']],
        'tor'                    => ['Transcript of Records or Certificate of Grades',          ['tor']],
    ];
}

// Numbered category list for one applicant: [1 => ['slug','label','slots'], ...].
// 0 is always "other" and is not in the list. Foreign applicants get none (version 1).
function ai_categories(string $applicantType, array|string|null $flags = null): array
{
    if ($applicantType !== TYPE_FRESHMAN && $applicantType !== TYPE_TRANSFEREE) return [];
    $applies = array_keys(docs_for_type($applicantType, $flags));
    $list = [];
    $n = 1;
    foreach (ai_category_map() as $slug => [$label, $slots]) {
        $slots = array_values(array_intersect($slots, $applies));
        if (!$slots) continue;
        $list[$n++] = ['slug' => $slug, 'label' => $label, 'slots' => $slots];
    }
    return $list;
}

function ai_applicant_name(array $a): string
{
    return trim(preg_replace('/\s+/', ' ', ($a['first_name'] ?? '') . ' ' . ($a['middle_name'] ?? '') . ' ' . ($a['last_name'] ?? '')));
}

function ai_build_prompt(array $categories, array $applicant): string
{
    $lines = ["0 = other, or not an admission document"];
    foreach ($categories as $n => $c) $lines[] = "$n = {$c['label']}";
    $birth = trim((string)($applicant['birthdate'] ?? '')) ?: 'unknown';
    return "You sort one image for a university admission. Applicant: " . ai_applicant_name($applicant)
        . ", born $birth.\nWhich document is it?\n" . implode("\n", $lines)
        . "\n\nReply with JSON only, no other text, keys in this order:\n"
        . '{"choice": <number from the list>, "legible": <true or false>, "reason": "<short plain message for the applicant, empty if fine>", '
        . '"fields": {"name": "", "birthdate": "", "id_type": "", "id_number": "", "photos": 0, "school": "", "program": "", "academic_year": "", '
        . '"units": 0, "complete": false, "seal": false, "signature": false, "notation": false, "notary": false}}'
        . "\nchoice must be the first key. legible is false if the image is blurry, too dark, cut off, or not a document. "
        . "fields.name is the person's name as printed (for a marriage certificate, both spouses). fields.photos is how many passport photos are in the image. "
        . "fields.units is the total units shown. fields.complete is true only if one full academic year with all subjects and grades is shown. "
        . "seal, signature, notation (\"For Evaluation Purposes Only\") and notary are true only if clearly visible. Use \"\", 0 or false when not visible.";
}

// Probability (0-100) of the token that holds the value of "choice".
// $content is choices[0].logprobs.content from llama-server. Null if not found.
function ai_choice_confidence(array $content): ?float
{
    $text = '';
    $spans = [];
    foreach ($content as $t) {
        $tok = (string)($t['token'] ?? '');
        $spans[] = [strlen($text), strlen($text) + strlen($tok), (float)($t['logprob'] ?? -INF)];
        $text .= $tok;
    }
    if (!preg_match('/"choice"\s*:\s*/', $text, $m, PREG_OFFSET_CAPTURE)) return null;
    $pos = $m[0][1] + strlen($m[0][0]);
    foreach ($spans as [$s, $e, $lp]) {
        if ($pos >= $s && $pos < $e) return round(max(0.0, min(1.0, exp($lp))) * 100, 1);
    }
    return null;
}

// Parse the model text into the output contract, or null when it is not usable.
function ai_parse_reply(string $text): ?array
{
    $text = trim($text);
    $a = strpos($text, '{');
    $b = strrpos($text, '}');
    if ($a === false || $b === false || $b < $a) return null;
    $j = json_decode(substr($text, $a, $b - $a + 1), true);
    if (!is_array($j) || !isset($j['choice']) || !is_numeric($j['choice'])) return null;
    return [
        'choice'  => (int)$j['choice'],
        'legible' => !array_key_exists('legible', $j) || filter_var($j['legible'], FILTER_VALIDATE_BOOLEAN),
        'reason'  => trim((string)($j['reason'] ?? '')),
        'fields'  => is_array($j['fields'] ?? null) ? $j['fields'] : [],
    ];
}

function ai_name_tokens(string $s): array
{
    $s = strtolower(iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $s) ?: $s);
    return array_values(array_filter(preg_split('/[^a-z]+/', $s)));
}

// True when the first name and every last-name token appear in the printed name.
// ponytail: tolerates one typo in long words; no nickname or married-name logic (reviewer decides).
function ai_name_matches(string $printed, array $applicant): bool
{
    $have = ai_name_tokens($printed);
    if (!$have) return false;
    $find = function (string $w) use ($have): bool {
        foreach ($have as $h) {
            if ($h === $w || (strlen($w) >= 5 && strlen($h) >= 5 && levenshtein($h, $w) <= 1)) return true;
        }
        return false;
    };
    $first = ai_name_tokens((string)($applicant['first_name'] ?? ''))[0] ?? '';
    $last  = ai_name_tokens((string)($applicant['last_name'] ?? ''));
    if ($first === '' || !$last) return true; // nothing to compare against
    if (!$find($first)) return false;
    foreach ($last as $w) if (!$find($w)) return false;
    return true;
}

function ai_id_type_accepted(string $type): bool
{
    $t = strtolower($type);
    foreach (['school', 'national', 'philsys', 'philhealth', 'driver', 'voter', 'umid', 'pwd', 'prc', 'professional regulation'] as $k) {
        if (str_contains($t, $k)) return true;
    }
    return false;
}

function ai_id_number_key(string $n): string
{
    return strtolower(preg_replace('/[^a-z0-9]/i', '', $n));
}

function ai_result(string $status, ?string $guess, float $conf, string $reason, array $fields = [], array $slots = []): array
{
    return ['status' => $status, 'guess' => $guess, 'confidence' => $conf, 'reason' => $reason, 'fields' => $fields, 'slots' => $slots];
}

// Turn a parsed reply into a result. Pure (no network), so it can be asserted.
// $opts: threshold (0-100), other_id_numbers (ID numbers already on the applicant's other ID).
function ai_decide(?array $parsed, ?float $confidence, array $categories, array $applicant, array $opts = []): array
{
    $threshold = (float)($opts['threshold'] ?? school_setting('ai_confidence_threshold', '80'));
    if (!$parsed) return ai_result('uncertain', null, 0, 'We could not read the AI answer. Please pick the document type.');

    $f = $parsed['fields'];
    $cat = $categories[$parsed['choice']] ?? null;
    $slug = $cat['slug'] ?? null;
    $slots = $cat['slots'] ?? [];
    $conf = $confidence ?? 0.0;

    if (!$parsed['legible']) {
        return ai_result('failed', $slug, $conf, $parsed['reason'] ?: 'The image is blurry, too dark, or cut off. Please retake a clearer photo.', $f);
    }
    if (!$cat) return ai_result('uncertain', null, $conf, 'This does not look like a required document. Please pick the type.', $f);

    $miss = [];
    $str = fn(string $k): string => trim((string)($f[$k] ?? ''));
    $units = (float)($f['units'] ?? 0);
    $name = $str('name');

    // Key fields that must not be blank
    $need = match ($slug) {
        'passport_photo' => [],
        'valid_id'       => ['name', 'id_type'],
        'tor'            => ['name', 'school', 'program', 'academic_year'],
        default          => ['name'],
    };
    foreach ($need as $k) if ($str($k) === '') $miss[] = "$k not found";

    if ($name !== '' && !ai_name_matches($name, $applicant)) $miss[] = 'name does not match the applicant';

    if ($slug === 'passport_photo') {
        $n = (int)($f['photos'] ?? 0);
        if ($n < 1 || $n > 2) $miss[] = 'could not count the photos';
    }
    if ($slug === 'guardianship_affidavit' && empty($f['notary'])) $miss[] = 'no notary seen';
    if ($slug === 'valid_id') {
        if ($str('id_type') !== '' && !ai_id_type_accepted($str('id_type'))) $miss[] = 'ID type is not accepted';
        $mine = ai_id_number_key($str('id_number'));
        foreach ($opts['other_id_numbers'] ?? [] as $o) {
            if ($mine !== '' && $mine === ai_id_number_key((string)$o)) $miss[] = 'same ID number as the other ID';
        }
    }
    if ($slug === 'tor') {
        if ($units < 21) $miss[] = 'less than 21 units';
        foreach (['complete' => 'not one full academic year', 'seal' => 'no school seal', 'signature' => 'no registrar signature', 'notation' => 'no "For Evaluation Purposes Only" note'] as $k => $msg) {
            if (empty($f[$k])) $miss[] = $msg;
        }
    }

    if ($miss) return ai_result('uncertain', $slug, $conf, 'Needs review: ' . implode(', ', $miss) . '.', $f, $slots);
    if ($confidence === null || $conf < $threshold) {
        return ai_result('uncertain', $slug, $conf, 'Not sure about this document type. Please confirm it.', $f, $slots);
    }
    return ai_result('passed', $slug, $conf, '', $f, $slots);
}

// Classify one file. $applicant needs applicant_type, doc_flags, first_name, middle_name, last_name, birthdate.
function ai_classify_image(string $path, string $mime, array $applicant, array $opts = []): array
{
    $categories = ai_categories((string)($applicant['applicant_type'] ?? ''), $applicant['doc_flags'] ?? null);
    if (!$categories) return ai_result('uncertain', null, 0, 'AI is not used for this applicant type. Please pick the document type.');
    if ($mime === 'application/pdf') return ai_result('uncertain', null, 0, 'PDF files are not read by AI. Please pick the document type.');

    $unavailable = fn() => ai_result('uncertain', null, 0, 'AI unavailable. Please pick the document type.');
    if (AI_MODEL_URL === '' || !is_readable($path)) return $unavailable();

    $body = json_encode([
        'messages' => [[
            'role'    => 'user',
            'content' => [
                ['type' => 'image_url', 'image_url' => ['url' => 'data:' . $mime . ';base64,' . base64_encode((string)file_get_contents($path))]],
                ['type' => 'text', 'text' => ai_build_prompt($categories, $applicant)],
            ],
        ]],
        'max_tokens'      => 400,
        'temperature'     => 0,
        'logprobs'        => true,
        'top_logprobs'    => 1,
        'response_format' => ['type' => 'json_object'],
    ]);

    $headers = ['Content-Type: application/json'];
    if (AI_MODEL_KEY !== '') $headers[] = 'Authorization: Bearer ' . AI_MODEL_KEY;
    $ch = curl_init(rtrim(AI_MODEL_URL, '/') . '/v1/chat/completions');
    curl_setopt_array($ch, [
        CURLOPT_POST           => true,
        CURLOPT_POSTFIELDS     => $body,
        CURLOPT_HTTPHEADER     => $headers,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_CONNECTTIMEOUT => min(10, AI_TIMEOUT),
        CURLOPT_TIMEOUT        => AI_TIMEOUT,
    ]);
    $raw = curl_exec($ch);
    $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    if ($raw === false || $code !== 200) return $unavailable();

    $choice = (json_decode((string)$raw, true)['choices'][0] ?? null);
    if (!is_array($choice)) return $unavailable();

    $parsed = ai_parse_reply((string)($choice['message']['content'] ?? ''));
    $conf = ai_choice_confidence($choice['logprobs']['content'] ?? []);
    return ai_decide($parsed, $conf, $categories, $applicant, $opts);
}
