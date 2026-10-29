<?php
// ============================================================
// modules/documents/student_upload.php
// M3 — Student: view & upload required documents
// ============================================================

require_once CORE_PATH . '/bootstrap.php';
Auth::requireRole(ROLE_STUDENT);

$userId = Auth::id();
$db     = db();

// Fetch applicant
$applicant = require_applicant();
$applicantId  = $applicant['id'];
$isSubmitted  = ($applicant['overall_status'] ?? '') === 'submitted';

// Fetch existing document rows
$stmt = $db->prepare('SELECT * FROM documents WHERE applicant_id = ?');
$stmt->execute([$applicantId]);
$docRows = array_column($stmt->fetchAll(), null, 'doc_type');

$requiredDocs = docs_for_type($applicant['applicant_type'], $applicant['doc_flags'] ?? null);

// Applicant can change the conditional ticks until they submit.
$canEditFlags = in_array($applicant['overall_status'] ?? '', ['pending', 'documents'], true);

// A freshman must choose Grade 12 or SHS graduate (it decides Form 138 or Form 137) before submitting.
$stageMissing = $applicant['applicant_type'] === TYPE_FRESHMAN
    && doc_stage_of($applicant['doc_flags'] ?? null) === '';

// Self-heal: if every required doc is approved but overall_status didn't
// auto-advance (rejected-then-replaced-then-approved edge case), fix it
// now so the stepper / exam page unblock immediately.
if (in_array($applicant['overall_status'] ?? '', ['submitted', 'documents'], true) && !empty($requiredDocs)) {
    $slugs = array_keys($requiredDocs);
    [$placeholders, $slugParams] = sql_in($slugs);
    $stmt = $db->prepare(
        "SELECT COUNT(*) FROM documents
          WHERE applicant_id = ?
            AND doc_type IN ($placeholders)
            AND status = 'approved'"
    );
    $stmt->execute(array_merge([$applicantId], $slugParams));
    if ((int)$stmt->fetchColumn() === count($requiredDocs)) {
        $db->prepare(
            'UPDATE applicants
                SET overall_status = \'exam\',
                    documents_approved_at = COALESCE(documents_approved_at, NOW())
              WHERE id = ?
                AND overall_status NOT IN (\'exam\',\'interview\',\'result\',\'released\',\'withdrawn\')'
        )->execute([$applicantId]);

        $stmt = $db->prepare('SELECT * FROM applicants WHERE id = ?');
        $stmt->execute([$applicantId]);
        $applicant = $stmt->fetch() ?: $applicant;
        $isSubmitted = ($applicant['overall_status'] ?? '') === 'submitted';

        if (function_exists('notify_stage_transition')) notify_stage_transition($applicantId, 'exam');
        if (function_exists('auto_assign_exam_slot'))   auto_assign_exam_slot($applicantId);
        audit_log(AuditAction::APPLICANT_ADVANCED_EXAM_SELFHEAL, "Self-healed advance to exam for applicant {$applicantId}", 'applicant', $applicantId);
    }
}

// Document deadline enforcement
$docDeadlineStr = school_setting('document_deadline', '');
$docDeadlinePassed = false;
if ($docDeadlineStr) {
    $docDeadlinePassed = (new DateTime())->format('Y-m-d') > $docDeadlineStr;
}

$errors   = [];
$success  = [];

// Detect AJAX upload
$isAjax = !empty($_SERVER['HTTP_X_REQUESTED_WITH'])
    && strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) === 'xmlhttprequest';

// ----------------------------------------------------------------
// POST — handle file upload, submit, or withdraw
// ----------------------------------------------------------------
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();

    // Block all document submissions after the deadline
    if ($docDeadlinePassed && !$isSubmitted) {
        $errors[] = 'The document submission deadline has passed.';
        if ($isAjax) {
            header('Content-Type: application/json');
            echo json_encode(['ok' => false, 'message' => $errors[0]]);
            exit;
        }
        redirect('/student/documents');
    }

    $action = $_POST['action'] ?? 'upload';

    // ---- Submit application ----
    if ($action === 'submit_application') {
        try {
            $uploadedCount = 0;
            foreach ($requiredDocs as $slug => $_) {
                $s = $docRows[$slug]['status'] ?? 'pending';
                if (in_array($s, ['uploaded', 'approved'], true)) $uploadedCount++;
            }
            $readyToSubmit = $uploadedCount === count($requiredDocs) && !$stageMissing;

            if ($readyToSubmit && !$isSubmitted) {
                $db->prepare('UPDATE applicants SET overall_status = \'submitted\' WHERE id = ?')
                   ->execute([$applicantId]);
                $isSubmitted = true;
            } elseif ($isSubmitted) {
                $errors[] = 'Application is already submitted.';
            } elseif ($stageMissing) {
                $errors[] = 'Choose whether you are currently in Grade 12 or a Senior High School graduate before submitting.';
            } else {
                $errors[] = 'All documents must be uploaded before submitting.';
            }
        } catch (Throwable $e) {
            $errors[] = 'Server error: ' . $e->getMessage();
        }

        if ($isAjax) {
            header('Content-Type: application/json');
            echo json_encode(['ok' => empty($errors), 'message' => empty($errors) ? 'Application submitted!' : implode(' ', $errors)]);
            exit;
        }
        redirect('/student/documents');
    }

    // ---- Withdraw submission ----
    if ($action === 'withdraw_submission') {
        try {
            if ($isSubmitted) {
                $db->prepare('UPDATE applicants SET overall_status = \'documents\' WHERE id = ?')
                   ->execute([$applicantId]);
                $isSubmitted = false;
            }
        } catch (Throwable $e) {
            $errors[] = 'Server error: ' . $e->getMessage();
        }

        if ($isAjax) {
            header('Content-Type: application/json');
            echo json_encode(['ok' => empty($errors), 'message' => empty($errors) ? 'Submission withdrawn.' : implode(' ', $errors)]);
            exit;
        }
        redirect('/student/documents');
    }

    // ---- Change conditional ticks (married / guardian / Grade 12 / SHS grad) ----
    if ($action === 'update_flags') {
        if (!$canEditFlags) {
            $errors[] = 'You can only change these before submitting your documents.';
        } else {
            $newFlags = doc_flags_from_input($applicant['applicant_type'], $_POST['flags'] ?? []);
            $blocked  = doc_flags_blocked_slots($db, $applicantId, $newFlags);
            if ($blocked) {
                $errors[] = 'Cannot remove an approved document: ' . implode(', ', $blocked) . '.';
            } else {
                $db->prepare('UPDATE applicants SET doc_flags = ?::jsonb WHERE id = ?')
                   ->execute([json_encode($newFlags), $applicantId]);
                $applicant['doc_flags'] = json_encode($newFlags);
                $requiredDocs = docs_for_type($applicant['applicant_type'], $applicant['doc_flags']);
                sync_doc_rows($db, $applicantId, $requiredDocs);
                audit_log(AuditAction::DOC_FLAGS_CHANGED, "Applicant {$applicantId} updated document ticks", 'applicant', $applicantId);
            }
        }

        if ($isAjax) {
            header('Content-Type: application/json');
            echo json_encode(['ok' => empty($errors), 'message' => empty($errors) ? 'Requirements updated.' : implode(' ', $errors)]);
            exit;
        }
        if ($errors) Session::flash('error', implode(' ', $errors));
        redirect('/student/documents');
    }

    // ---- Change applicant type ----
    if ($action === 'change_type') {
        $newType = post_str('applicant_type');
        $allowedTypes = ['freshman', 'transferee', 'foreign'];
        if (!in_array($newType, $allowedTypes, true)) {
            $errors[] = 'Invalid applicant type.';
        } elseif ($isSubmitted || !in_array($applicant['overall_status'], ['documents'], true)) {
            $errors[] = 'You can only change your applicant type before submitting documents.';
        } else {
            $typeFlags = doc_flags_from_input($newType, doc_flags_decode($applicant['doc_flags'] ?? null));
            $db->prepare('UPDATE applicants SET applicant_type = ?, doc_flags = ?::jsonb WHERE id = ?')
                ->execute([$newType, json_encode($typeFlags), $applicantId]);
            $applicant['applicant_type'] = $newType;
            $applicant['doc_flags']      = json_encode($typeFlags);
            $requiredDocs = docs_for_type($newType, $applicant['doc_flags']);
            sync_doc_rows($db, $applicantId, $requiredDocs);
            audit_log(AuditAction::TYPE_CHANGED, "Applicant {$applicantId} changed type to {$newType}", 'applicant', $applicantId);
            Session::flash('success', 'Applicant type updated. Please review the updated document requirements.');
        }
        redirect('/student/documents');
    }

    // ---- Classify one file (batch upload backend) ----
    // One file per request. passed: saved to the first open slot of its
    // category. uncertain / failed / blocked: nothing is saved.
    if ($action === 'classify') {
        header('Content-Type: application/json');
        $reply = static function (array $data): never {
            echo json_encode($data);
            exit;
        };
        $error = static fn(string $msg): array => ['ok' => false, 'status' => 'error', 'message' => $msg];
        @set_time_limit(60);

        $f = $_FILES['doc_file'] ?? null;
        if (!$f || is_array($f['name']) || ($f['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
            $reply($error('Send exactly one file. It may be too large or missing.'));
        }
        if ($f['size'] > MAX_UPLOAD_BYTES) {
            $reply($error('File size exceeds the 4 MB limit.'));
        }
        $mimeType = (new finfo(FILEINFO_MIME_TYPE))->file($f['tmp_name']);
        if (!in_array($mimeType, ALLOWED_MIME_TYPES, true)) {
            $reply($error('Only PDF, JPG, PNG, and WEBP files are accepted.'));
        }

        // A PDF is read through a picture of its first page, made by the browser (ai_preview).
        // The picture is only for the AI; the PDF itself is what gets saved.
        $aiPath = $f['tmp_name'];
        $aiMime = $mimeType;
        if ($mimeType === 'application/pdf') {
            $pv = $_FILES['ai_preview'] ?? null;
            if ($pv && !is_array($pv['name']) && ($pv['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_OK
                && $pv['size'] > 0 && $pv['size'] <= 2 * 1024 * 1024
                && (new finfo(FILEINFO_MIME_TYPE))->file($pv['tmp_name']) === 'image/jpeg') {
                $aiPath = $pv['tmp_name'];
                $aiMime = 'image/jpeg';
            }
        }

        // The classifier applies ai_confidence_threshold itself, so 'passed' is final here.
        $categories = doc_categories($requiredDocs);
        $r          = classify_upload($aiPath, $aiMime, $applicant);
        $slots      = array_values(array_intersect($r['slots'], array_keys($requiredDocs)));
        $cat        = $slots ? doc_category_of($slots[0]) : null;

        // Category dropdown list; sent with failed and uncertain so the page can offer a manual pick.
        $buildCats = static function () use ($categories, $docRows, $isSubmitted): array {
            $list = [];
            foreach ($categories as $key => $c) {
                $pick   = doc_pick_slot($c['slots'], $docRows, $isSubmitted);
                $list[] = [
                    'category'  => $key,
                    'label'     => $c['label'],
                    'available' => $pick['slot'] !== null,
                    'note'      => $pick['reason'] ?? ($pick['replaced'] ? 'Replaces your earlier file.' : null),
                ];
            }
            return $list;
        };

        $out = [
            'ok'          => true,
            'status'      => $r['status'],
            'file'        => $f['name'],
            'guess'       => $r['guess'],
            'guess_label' => $categories[$cat ?? '']['label'] ?? null,
            'guess_category' => $cat,
            'confidence'  => $r['confidence'],
            'reason'      => $r['reason'],
        ];

        if ($r['status'] === 'failed') {
            $out['categories'] = $buildCats();
            $out['message'] = $r['reason'] !== '' ? $r['reason'] : 'This file is not clear enough. Please upload a better copy.';
            $reply($out);
        }

        if ($r['status'] === 'uncertain' || !$slots) {
            $out['status']     = 'uncertain';
            $out['categories'] = $buildCats();
            $out['message'] = $r['reason'] !== ''
                ? $r['reason']
                : ($out['guess_label']
                    ? 'This looks like ' . $out['guess_label'] . ', but we are not sure. Please pick its type.'
                    : 'We could not tell what this document is. Please pick its type.');
            $reply($out);
        }

        // passed
        $db->beginTransaction();
        try {
            // Lock this category's rows so two files sent together never take the same slot.
            [$in] = sql_in($slots);
            $stmt = $db->prepare("SELECT doc_type, status FROM documents WHERE applicant_id = ? AND doc_type IN ($in) FOR UPDATE");
            $stmt->execute(array_merge([$applicantId], $slots));
            $fresh = [];
            foreach ($stmt->fetchAll() as $row) $fresh[$row['doc_type']] = $row;

            $pick = doc_pick_slots($slots, $fresh, $isSubmitted);
            if (!$pick['slots']) {
                $db->rollBack();
                $out['status']  = 'blocked';
                $out['message'] = $pick['reason'];
                $out['can_replace'] = !$isSubmitted && in_array('uploaded', array_column($fresh, 'status'), true);
                $reply($out);
            }
            $slot = $pick['slots'][0];

            $ext      = strtolower(pathinfo($f['name'], PATHINFO_EXTENSION));
            $filename = $applicantId . '_' . $slot . '_' . time() . '.' . $ext;
            $fileUrl  = uploadcare_upload($f['tmp_name'], $filename, $mimeType);
            if (!$fileUrl) {
                $db->rollBack();
                $reply($error('File upload failed. Please try again.'));
            }

            $update = $db->prepare(
                "UPDATE documents SET file_path = ?, status = 'uploaded', staff_remarks = NULL, reviewed_by = NULL
                  WHERE applicant_id = ? AND doc_type = ? RETURNING id"
            );
            $insert = $db->prepare(
                "INSERT INTO documents (applicant_id, doc_type, file_path, status) VALUES (?, ?, ?, 'uploaded') RETURNING id"
            );
            $valid = $db->prepare(
                "INSERT INTO document_validations (document_id, validation_type, status, confidence, details)
                 VALUES (?, 'ai', 'passed', ?, ?)"
            );
            $details = json_encode(['category' => $r['guess'], 'reason' => $r['reason'], 'fields' => $r['fields']]);
            // ponytail: a replaced file stays in storage; upgrade path is a storage cleanup job.
            foreach ($pick['slots'] as $s) {
                $update->execute([$fileUrl, $applicantId, $s]);
                $docId = $update->fetchColumn();
                if (!$docId) {
                    $insert->execute([$applicantId, $s, $fileUrl]);
                    $docId = $insert->fetchColumn();
                }
                $valid->execute([$docId, $r['confidence'], $details]);
            }
            $db->commit();
        } catch (Throwable $e) {
            if ($db->inTransaction()) $db->rollBack();
            error_log('Classify save failed: ' . $e->getMessage());
            $reply($error('Server error. Please try again.'));
        }

        audit_log(AuditAction::DOC_AI_PASSED, "Applicant {$applicantId}: {$r['guess']} saved to " . implode(', ', $pick['slots']), 'applicant', $applicantId);
        $out['slot']       = $slot;
        $out['slots']      = $pick['slots'];
        $out['slot_label'] = $requiredDocs[$slot];
        $out['replaced']   = $pick['replaced'];
        if ($pick['replaced']) {
            $out['message'] = $requiredDocs[$slot] . ' replaced your earlier file.';
        } else {
            $out['message'] = $requiredDocs[$slot] . ' saved.';
        }
        $reply($out);
    }

    // ---- File upload ----
    $docSlug = post_str('doc_slug');

    // picked=1: the applicant chose the type from the dropdown after the AI was unsure.
    // doc_slug may then be a slot or a category (valid_id, photo); a category follows the 4B slot rules.
    // ai_guess is the AI's guess, sent back by the page, only used for the validation row.
    // ponytail: picks are not locked; the upload page sends one file at a time. Upgrade path: lock like classify does.
    $picked  = ($_POST['picked'] ?? '') === '1';
    $aiGuess = substr(trim((string)($_POST['ai_guess'] ?? '')), 0, 64);
    if ($picked) {
        $res = doc_resolve_pick($docSlug, $requiredDocs, $docRows, $isSubmitted);
        if ($res['slot'] === null) $errors[] = $res['reason'];
        else $docSlug = $res['slot'];
    }

    if (!$errors && !array_key_exists($docSlug, $requiredDocs)) {
        $errors[] = 'Invalid document type.';
    }

    // Only allow replace based on submission state
    $currentStatus  = $docRows[$docSlug]['status'] ?? 'pending';
    $allowedStatuses = $isSubmitted ? ['rejected', 'resubmission_required'] : ['pending', 'rejected', 'resubmission_required', 'uploaded'];
    if (!in_array($currentStatus, $allowedStatuses, true)) {
        $errors[] = 'This document cannot be replaced at this time.';
    }

    if (empty($_FILES['doc_file']['name'])) {
        $errors[] = 'Please select a file to upload.';
    }

    if (empty($errors)) {
        $file     = $_FILES['doc_file'];
        $fileSize = $file['size'];
        $tmpPath  = $file['tmp_name'];

        if ($fileSize > MAX_UPLOAD_BYTES) {
            $errors[] = 'File size exceeds the 4 MB limit.';
        }

        // Check MIME via finfo
        $finfo    = new finfo(FILEINFO_MIME_TYPE);
        $mimeType = $finfo->file($tmpPath);
        if (!in_array($mimeType, ALLOWED_MIME_TYPES, true)) {
            $errors[] = 'Only PDF, JPG, PNG, and WEBP files are accepted.';
        }
    }

    if (empty($errors)) {
        $ext      = pathinfo($file['name'], PATHINFO_EXTENSION);
        $filename = $applicantId . '_' . $docSlug . '_' . time() . '.' . strtolower($ext);

        // Upload to Uploadcare
        $fileUrl = uploadcare_upload($tmpPath, $filename, $mimeType);

        if (!$fileUrl) {
            $errors[] = 'File upload failed. Please try again.';
        } else {
            $filePath = $fileUrl; // Store full CDN URL

            if (isset($docRows[$docSlug])) {
                $stmt = $db->prepare(
                    'UPDATE documents SET file_path=?, status=\'uploaded\', staff_remarks=NULL, reviewed_by=NULL
                     WHERE applicant_id=? AND doc_type=?'
                );
                $stmt->execute([$filePath, $applicantId, $docSlug]);
            } else {
                $stmt = $db->prepare(
                    'INSERT INTO documents (applicant_id, doc_type, file_path, status) VALUES (?,?,?,\'uploaded\')'
                );
                $stmt->execute([$applicantId, $docSlug, $filePath]);
            }

            // Re-fetch updated doc rows
            $stmt = $db->prepare('SELECT * FROM documents WHERE applicant_id = ?');
            $stmt->execute([$applicantId]);
            $docRows = array_column($stmt->fetchAll(), null, 'doc_type');

            // Newest validation row wins. A picked file is flagged for staff (ai, uncertain);
            // a plain upload clears any old flag (file_check, passed).
            try {
                $docId = $docRows[$docSlug]['id'] ?? null;
                if ($docId) {
                    if ($picked) {
                        $vType = 'ai';
                        $vStat = 'uncertain';
                        $vInfo = ['applicant_pick' => doc_category_of($docSlug), 'ai_guess' => $aiGuess !== '' ? $aiGuess : null];
                    } else {
                        $vType = 'file_check';
                        $vStat = 'passed';
                        $vInfo = ['check' => 'type_and_size'];
                    }
                    $db->prepare(
                        'INSERT INTO document_validations (document_id, validation_type, status, confidence, details) VALUES (?,?,?,NULL,?)'
                    )->execute([$docId, $vType, $vStat, json_encode($vInfo)]);
                }
            } catch (Throwable $e) {
                error_log('Validation row failed: ' . $e->getMessage());
            }

            $success[] = $requiredDocs[$docSlug] . ' uploaded successfully.';
        }
    }

    // Return JSON for AJAX requests
    if ($isAjax) {
        header('Content-Type: application/json');
        if (empty($errors)) {
            echo json_encode([
                'ok'      => true,
                'message' => $success[0] ?? 'Uploaded successfully.',
                'slot'    => $docSlug,
            ]);
        } else {
            echo json_encode(['ok' => false, 'message' => implode(' ', $errors)]);
        }
        exit;
    }
}

// Count statuses (only slots that apply; old or extra rows are ignored)
$reqRows      = array_intersect_key($docRows, $requiredDocs);
$statusCounts = array_count_values(array_column($reqRows, 'status'));
$allApproved  = count($reqRows) === count($requiredDocs)
    && ($statusCounts['approved'] ?? 0) === count($requiredDocs);

// Submission state helpers
$uploadedOrApproved = ($statusCounts['uploaded'] ?? 0) + ($statusCounts['approved'] ?? 0);
$allUploaded  = count($reqRows) === count($requiredDocs) && $uploadedOrApproved === count($requiredDocs);
$pastDocuments = in_array($applicant['overall_status'] ?? '', ['exam', 'interview', 'released'], true);
$hasRejected   = ($statusCounts['rejected'] ?? 0) > 0;
$canSubmit     = $allUploaded && !$stageMissing && !$isSubmitted && !$pastDocuments;
$canWithdraw   = $isSubmitted && !$allApproved && !$pastDocuments && !$hasRejected;

// Stepper current step
$stmt = $db->prepare('SELECT * FROM exam_results WHERE applicant_id=? LIMIT 1');
$stmt->execute([$applicantId]);
$_examResult = $stmt->fetch() ?: null;

$stmt = $db->prepare('SELECT q.* FROM interview_queue q WHERE q.applicant_id=? LIMIT 1');
$stmt->execute([$applicantId]);
$_interviewSlot = $stmt->fetch() ?: null;

$stmt = $db->prepare('SELECT * FROM admission_results WHERE applicant_id=? LIMIT 1');
$stmt->execute([$applicantId]);
$_admissionResult = $stmt->fetch() ?: null;

$stepperCurrent = current_step($applicant, $_examResult, $_interviewSlot, $_admissionResult);

// ----------------------------------------------------------------
// Interview data (needed when step = interview)
// ----------------------------------------------------------------
$interviewErrors = [];
$myEntry         = null;
$openSessions    = [];
$queuePosition   = null;

if ($_examResult) {
    // Load the student's booking with full slot details. After the
    // desk/session merge, the interviewer + location both live on the slot.
    $stmt = $db->prepare(
        'SELECT q.*,
                s.slot_date, s.slot_time, s.end_time, s.capacity,
                COALESCE(au.name, cu.name)                           AS staff_name,
                COALESCE(NULLIF(s.location_label, \'\'), cu.desk_label) AS desk_label,
                COALESCE(s.location_notes, cu.desk_notes)            AS desk_notes
         FROM   interview_queue q
         JOIN   interview_slots s ON s.id = q.slot_id
         JOIN   users           cu ON cu.id = s.created_by
         LEFT JOIN users        au ON au.id = s.assigned_to
         WHERE  q.applicant_id = ?
         LIMIT 1'
    );
    $stmt->execute([$applicantId]);
    $myEntry = $stmt->fetch() ?: null;

    // POST actions — book or check in
    if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['interview_action'])) {
        csrf_check();
        $iAction = $_POST['interview_action'];

        if ($iAction === 'book') {
            $slotId = post_int('slot_id');
            $db->beginTransaction();
            try {
                $stmt = $db->prepare(
                    'SELECT s.id, s.capacity
                     FROM   interview_slots s
                     WHERE  s.id = ? AND s.status = \'open\'
                     FOR UPDATE'
                );
                $stmt->execute([$slotId]);
                $slot = $stmt->fetch();
                if ($slot) {
                    $bookedStmt = $db->prepare('SELECT COUNT(*) FROM interview_queue WHERE slot_id = ?');
                    $bookedStmt->execute([$slotId]);
                    $slot['booked'] = (int)$bookedStmt->fetchColumn();
                }

                if (!$slot || (int)$slot['booked'] >= (int)$slot['capacity']) {
                    $db->rollBack();
                    $interviewErrors[] = 'That session is no longer available or is full.';
                } else {
                    $db->prepare(
                        'INSERT INTO interview_queue (slot_id, applicant_id, status) VALUES (?, ?, \'scheduled\')'
                    )->execute([$slotId, $applicantId]);
                    $db->prepare(
                        'UPDATE applicants SET overall_status=\'interview\' WHERE id=?'
                    )->execute([$applicantId]);
                    $db->commit();
                    Session::flash('success', 'Your interview session has been booked!');
                    redirect('/student/documents');
                }
            } catch (Throwable $e) {
                $db->rollBack();
                $interviewErrors[] = 'Booking failed. Please try again.';
            }
        }

        if ($iAction === 'checkin' && $myEntry && $myEntry['slot_date'] === now_date() && $myEntry['status'] === 'scheduled') {
            $db->beginTransaction();
            try {
                // After the desk/session merge, an interviewer is identified by
                // assigned_to (with created_by fallback for legacy rows).
                $stmt = $db->prepare(
                    'SELECT COALESCE(MAX(q.queue_number), 0) + 1
                     FROM   interview_queue q
                     JOIN   interview_slots s ON s.id = q.slot_id
                     WHERE  s.slot_date = ? AND COALESCE(s.assigned_to, s.created_by) = (
                         SELECT COALESCE(assigned_to, created_by) FROM interview_slots WHERE id = ?
                     ) AND q.queue_number IS NOT NULL'
                );
                $stmt->execute([now_date(), $myEntry['slot_id']]);
                $nextNum = (int)$stmt->fetchColumn();

                $db->prepare(
                    'UPDATE interview_queue
                     SET    status = \'checked_in\', queue_number = ?, checked_in_at = NOW()
                     WHERE  id = ? AND status = \'scheduled\''
                )->execute([$nextNum, $myEntry['id']]);
                $db->commit();
                Session::flash('success', 'You are now in the queue!');
            } catch (Throwable $e) {
                $db->rollBack();
                $interviewErrors[] = 'Check-in failed. Please try again.';
            }
            // Reload
            $stmt = $db->prepare(
                'SELECT q.*, s.slot_date, s.slot_time, s.end_time, s.capacity,
                        COALESCE(au.name, cu.name)                           AS staff_name,
                        COALESCE(NULLIF(s.location_label, \'\'), cu.desk_label) AS desk_label,
                        COALESCE(s.location_notes, cu.desk_notes)            AS desk_notes
                 FROM   interview_queue q
                 JOIN   interview_slots s ON s.id = q.slot_id
                 JOIN   users           cu ON cu.id = s.created_by
                 LEFT JOIN users        au ON au.id = s.assigned_to
                 WHERE  q.applicant_id = ? LIMIT 1'
            );
            $stmt->execute([$applicantId]);
            $myEntry = $stmt->fetch() ?: null;
        }
    }

    // Load open sessions if not booked yet. After the desk/session merge,
    // location info lives on each session row, so no JOIN to a desks table.
    if (!$myEntry) {
        $nowTime = date('H:i:s');
        $stmt = $db->prepare(
            'SELECT s.*,
                    COALESCE(au.name, cu.name)                           AS staff_name,
                    COALESCE(NULLIF(s.location_label, \'\'), cu.desk_label) AS desk_label,
                    COALESCE(s.location_notes, cu.desk_notes)            AS desk_notes,
                    (SELECT COUNT(*) FROM interview_queue q WHERE q.slot_id = s.id) AS booked
             FROM   interview_slots s
             JOIN   users           cu ON cu.id = s.created_by
             LEFT JOIN users        au ON au.id = s.assigned_to
             WHERE  s.slot_date >= ? AND s.status = \'open\'
               AND  NOT (s.slot_date = ? AND s.end_time IS NOT NULL AND s.end_time <= ?)
               AND  (SELECT COUNT(*) FROM interview_queue q2 WHERE q2.slot_id = s.id) < s.capacity
             ORDER BY s.slot_date ASC, s.slot_time ASC NULLS FIRST'
        );
        $stmt->execute([now_date(), now_date(), $nowTime]);
        $openSessions = $stmt->fetchAll();
    }

    // Queue position — scoped to this interviewer's queue for today, using
    // assigned_to with created_by fallback for legacy rows.
    if ($myEntry && $myEntry['status'] === 'checked_in') {
        $stmt = $db->prepare(
            'SELECT COUNT(*) FROM interview_queue q
             JOIN   interview_slots s ON s.id = q.slot_id
             WHERE  s.slot_date = ? AND COALESCE(s.assigned_to, s.created_by) = (
                 SELECT COALESCE(assigned_to, created_by) FROM interview_slots WHERE id = ?
             ) AND q.status = \'checked_in\' AND q.queue_number < ?'
        );
        $stmt->execute([now_date(), $myEntry['slot_id'], $myEntry['queue_number']]);
        $queuePosition = (int)$stmt->fetchColumn() + 1;
    }
}

// Two pages share this module (both are still the Submit Documents step), each one card:
//   /student/documents          drop box (AI sorting) + the document list as read-only status
//   /student/documents/manual   no drop box, no AI: the list with Upload / Resubmit buttons
// Applicants who cannot use the batch box (foreign, or past the documents step) get the full list on the main page.
$manualPage = str_ends_with(rtrim((string) parse_url($_SERVER['REQUEST_URI'] ?? '', PHP_URL_PATH), '/'), '/student/documents/manual');
$hasBatch   = $applicant['applicant_type'] !== 'foreign' && !$pastDocuments && !$docDeadlinePassed;
$fullList   = $manualPage || !$hasBatch;

// Build viewable files for modal
$viewableFiles = [];
foreach ($requiredDocs as $slug => $label) {
    $doc = $docRows[$slug] ?? null;
    if ($doc && $doc['file_path']) {
        $viewableFiles[] = [
            'label'     => $label,
            'file_path' => $doc['file_path'],
            'url'       => file_url($doc['file_path']),
        ];
    }
}

// ----------------------------------------------------------------
// Deadline-passed page for students who haven't submitted
// ----------------------------------------------------------------
if ($docDeadlinePassed && !$isSubmitted && !$pastDocuments) {
    ob_start();
    $deadlineFormatted = date('F j, Y', strtotime($docDeadlineStr));
?>
<div class="flex align-items-center justify-content-center" style="min-height:60vh">
    <div class="text-center mwpx-480 p-8">
        <div class="mb-4">
            <?= icon('ic_fluent_dismiss_circle_24_regular', 48) ?>
        </div>
        <h2 class="text-xl fw-semibold mb-3 text-main">
            Document Submission Closed
        </h2>
        <p class="text-sm text-secondary mb-4" style="line-height:1.6">
            The deadline for submitting documents was <strong><?= e($deadlineFormatted) ?></strong>.
            Unfortunately, we are no longer accepting document submissions for this admissions period.
        </p>
        <p class="text-xs text-tertiary" style="line-height:1.5">
            If you believe this is an error or have special circumstances, please contact the admissions office for assistance.
        </p>
    </div>
</div>
<?php
    $content   = ob_get_clean();
    $pageTitle = 'Document Submission Closed';
    include VIEWS_PATH . '/layouts/app.php';
    return;
}

// ----------------------------------------------------------------
// View
// ----------------------------------------------------------------
ob_start();
include __DIR__ . '/_student_upload_view.php';
$content     = ob_get_clean();
$pageTitle   = $manualPage ? 'Upload Documents Manually' : 'My Documents';
$pageBreadcrumb = $manualPage ? [['label' => 'My Documents', 'to' => '/student/documents']] : [];
$activeNav   = 'documents';
$showStepper = true;
$pageStyles  = ['css/file-viewer.css', 'css/pages/student-documents.css'];
$pageScripts = ['js/file-viewer.js', 'js/pages/student-documents.js', 'js/pages/student-documents-viewer.js', 'js/pages/student-documents-flags.js', 'js/pages/student-documents-batch.js'];
include VIEWS_PATH . '/layouts/app.php';
