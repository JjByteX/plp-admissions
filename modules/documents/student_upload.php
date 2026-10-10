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
$stmt = $db->prepare('SELECT * FROM applicants WHERE user_id = ? ORDER BY id DESC LIMIT 1');
$stmt->execute([$userId]);
$applicant = $stmt->fetch();
if (!$applicant) { redirect('/student/documents'); }
$applicantId  = $applicant['id'];
$isSubmitted  = ($applicant['overall_status'] ?? '') === 'submitted';

// Fetch existing document rows
$stmt = $db->prepare('SELECT * FROM documents WHERE applicant_id = ?');
$stmt->execute([$applicantId]);
$docRows = array_column($stmt->fetchAll(), null, 'doc_type');

$requiredDocs = docs_for_type($applicant['applicant_type'], $applicant['doc_flags'] ?? null);

// Applicant can change the conditional ticks until they submit.
$canEditFlags = in_array($applicant['overall_status'] ?? '', ['pending', 'documents'], true);

// Self-heal: if every required doc is approved but overall_status didn't
// auto-advance (rejected-then-replaced-then-approved edge case), fix it
// now so the stepper / exam page unblock immediately.
if (in_array($applicant['overall_status'] ?? '', ['submitted', 'documents'], true) && !empty($requiredDocs)) {
    $slugs = array_keys($requiredDocs);
    $placeholders = implode(',', array_fill(0, count($slugs), '?'));
    $stmt = $db->prepare(
        "SELECT COUNT(*) FROM documents
          WHERE applicant_id = ?
            AND doc_type IN ($placeholders)
            AND status = 'approved'"
    );
    $stmt->execute(array_merge([$applicantId], $slugs));
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
        audit_log('applicant_advanced_exam_selfheal', "Self-healed advance to exam for applicant {$applicantId}", 'applicant', $applicantId);
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
            $readyToSubmit = $uploadedCount === count($requiredDocs);

            if ($readyToSubmit && !$isSubmitted) {
                $db->prepare('UPDATE applicants SET overall_status = \'submitted\' WHERE id = ?')
                   ->execute([$applicantId]);
                $isSubmitted = true;
            } elseif ($isSubmitted) {
                $errors[] = 'Application is already submitted.';
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
                audit_log('doc_flags_changed', "Applicant {$applicantId} updated document ticks", 'applicant', $applicantId);
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
        $newType = trim($_POST['applicant_type'] ?? '');
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
            audit_log('type_changed', "Applicant {$applicantId} changed type to {$newType}", 'applicant', $applicantId);
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
            $in   = implode(',', array_fill(0, count($slots), '?'));
            $stmt = $db->prepare("SELECT doc_type, status FROM documents WHERE applicant_id = ? AND doc_type IN ($in) FOR UPDATE");
            $stmt->execute(array_merge([$applicantId], $slots));
            $fresh = [];
            foreach ($stmt->fetchAll() as $row) $fresh[$row['doc_type']] = $row;

            // One image with two photos fills both photo slots with the same file.
            $want = ($cat === 'photo' && (int)($r['fields']['photos'] ?? 0) === 2) ? 2 : 1;
            $pick = doc_pick_slots($slots, $fresh, $isSubmitted, $want);
            if (!$pick['slots']) {
                $db->rollBack();
                $out['status']  = 'blocked';
                $out['message'] = $pick['reason'];
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

        audit_log('doc_ai_passed', "Applicant {$applicantId}: {$r['guess']} saved to " . implode(', ', $pick['slots']), 'applicant', $applicantId);
        $out['slot']       = $slot;
        $out['slots']      = $pick['slots'];
        $out['slot_label'] = $requiredDocs[$slot];
        $out['replaced']   = $pick['replaced'];
        if (count($pick['slots']) === 2) {
            $out['message'] = $categories[$cat]['label'] . ' saved to both slots.';
        } elseif ($pick['replaced']) {
            $out['message'] = $requiredDocs[$slot] . ' replaced your earlier file.';
        } else {
            $out['message'] = $requiredDocs[$slot] . ' saved.';
        }
        $reply($out);
    }

    // ---- File upload ----
    $docSlug = trim($_POST['doc_slug'] ?? '');

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
$canSubmit     = $allUploaded && !$isSubmitted && !$pastDocuments;
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
            $slotId = (int)($_POST['slot_id'] ?? 0);
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

        if ($iAction === 'checkin' && $myEntry && $myEntry['slot_date'] === date('Y-m-d') && $myEntry['status'] === 'scheduled') {
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
                $stmt->execute([date('Y-m-d'), $myEntry['slot_id']]);
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
        $stmt->execute([date('Y-m-d'), date('Y-m-d'), $nowTime]);
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
        $stmt->execute([date('Y-m-d'), $myEntry['slot_id'], $myEntry['queue_number']]);
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
<div style="display:flex;align-items:center;justify-content:center;min-height:60vh">
    <div style="text-align:center;max-width:480px;padding:var(--space-8)">
        <div style="font-size:48px;margin-bottom:var(--space-4)">
            <?= icon('ic_fluent_dismiss_circle_24_regular', 48) ?>
        </div>
        <h2 style="font-size:var(--text-xl);font-weight:var(--weight-semibold);margin-bottom:var(--space-3);color:var(--text-primary)">
            Document Submission Closed
        </h2>
        <p style="font-size:var(--text-sm);color:var(--text-secondary);line-height:1.6;margin-bottom:var(--space-4)">
            The deadline for submitting documents was <strong><?= e($deadlineFormatted) ?></strong>.
            Unfortunately, we are no longer accepting document submissions for this admissions period.
        </p>
        <p style="font-size:var(--text-xs);color:var(--text-tertiary);line-height:1.5">
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
?>

<?php foreach ($errors as $err): ?>
    <div class="alert alert-error" style="margin-bottom:var(--space-4)">
        <?= icon('ic_fluent_warning_24_regular', 16) ?>
        <?= e($err) ?>
    </div>
<?php endforeach; ?>

<?php foreach ($success as $msg): ?>
    <div class="alert alert-success" style="margin-bottom:var(--space-4)">
        <?= icon('ic_fluent_checkmark_circle_24_regular', 16) ?>
        <?= e($msg) ?>
    </div>
<?php endforeach; ?>






<!-- Client notice -->
<div class="alert alert-info" style="margin-bottom:var(--space-4);display:block;line-height:1.6">
    <p style="margin:0 0 var(--space-2)">When uploading the REQUIRED DOCUMENTS, please ensure that the scanned copies or screenshots are CLEAR, LEGIBLE, and FREE FROM ANY ALTERATIONS or DIGITAL MANIPULATION.</p>
    <p style="margin:0 0 var(--space-2)">Applicants are reminded that ONLY THOSE with COMPLETE REQUIREMENTS will be entertained and scheduled for VALIDATION.</p>
    <p style="margin:0">Qualifying applicants shall take the admission exam. The schedule of examination will be posted on the PLP Official Facebook Page.</p>
</div>

<?php if ($manualPage): ?>
<!-- Manual upload page: same step, one document at a time -->
<div style="margin-bottom:var(--space-4)">
    <a href="<?= url('/student/documents') ?>" style="font-size:var(--text-sm);color:var(--accent);text-decoration:underline">&larr; Back to Upload many files</a>
    <h2 style="font-size:var(--text-xl);font-weight:var(--weight-semibold);margin:var(--space-2) 0 var(--space-1);color:var(--text-primary)">Upload documents manually</h2>
</div>
<?php endif; ?>

<?php if (!$manualPage && !$isSubmitted && $applicant['overall_status'] === 'documents'): ?>
<!-- Applicant type selector -->
<div class="card" style="padding:var(--space-4) var(--space-5);margin-bottom:var(--space-4);display:flex;align-items:center;justify-content:space-between;flex-wrap:wrap;gap:var(--space-3)">
    <div>
        <span style="font-size:var(--text-xs);text-transform:uppercase;letter-spacing:.06em;color:var(--text-tertiary)">Applicant Type</span>
        <div style="font-weight:var(--weight-semibold);font-size:var(--text-sm);text-transform:capitalize"><?= e($applicant['applicant_type']) ?></div>
    </div>
    <form method="POST" style="display:flex;align-items:center;gap:var(--space-2)">
        <?= csrf_field() ?>
        <input type="hidden" name="action" value="change_type">
        <select name="applicant_type" class="form-select" style="width:auto;min-height:36px;font-size:var(--text-sm)">
            <option value="freshman" <?= $applicant['applicant_type'] === 'freshman' ? 'selected' : '' ?>>Freshman</option>
            <option value="transferee" <?= $applicant['applicant_type'] === 'transferee' ? 'selected' : '' ?>>Transferee</option>
            <option value="foreign" <?= $applicant['applicant_type'] === 'foreign' ? 'selected' : '' ?>>Foreign</option>
        </select>
        <button type="submit" class="btn btn-ghost" style="min-height:36px;font-size:var(--text-sm)">Change</button>
    </form>
</div>
<?php endif; ?>

<?php if (!$manualPage && $canEditFlags && !$isSubmitted): ?>
<!-- Conditional ticks: change until submit; jQuery refreshes the slot list below -->
<div class="card" style="padding:var(--space-4) var(--space-5);margin-bottom:var(--space-4)">
    <div style="font-weight:var(--weight-semibold);font-size:var(--text-sm);margin-bottom:var(--space-2)">Which of these apply to you?</div>
    <form id="flags-form">
        <?= csrf_field() ?>
        <input type="hidden" name="action" value="update_flags">
        <?php $_ticks = doc_flags_decode($applicant['doc_flags'] ?? null); ?>
        <div style="display:flex;flex-direction:column;gap:var(--space-2)">
            <label class="form-check">
                <input type="checkbox" name="flags[married]" value="1" <?= $_ticks['married'] ? 'checked' : '' ?>>
                <span>I am married</span>
            </label>
            <label class="form-check">
                <input type="checkbox" name="flags[guardian]" value="1" <?= $_ticks['guardian'] ? 'checked' : '' ?>>
                <span>I am not living with my parents</span>
            </label>
            <?php if ($applicant['applicant_type'] === 'freshman'): ?>
            <label class="form-check">
                <input type="checkbox" name="flags[grade12]" value="1" <?= $_ticks['grade12'] ? 'checked' : '' ?>>
                <span>I am currently in Grade 12</span>
            </label>
            <label class="form-check">
                <input type="checkbox" name="flags[shs_grad]" value="1" <?= $_ticks['shs_grad'] ? 'checked' : '' ?>>
                <span>I am a Senior High School graduate</span>
            </label>
            <?php endif; ?>
        </div>
    </form>
    <div id="flags-msg" style="font-size:var(--text-xs);margin-top:var(--space-2);display:none"></div>
</div>
<?php endif; ?>

<?php if (!$manualPage && $hasBatch): ?>
<style>
.batch-drop { border:2px dashed var(--border); border-radius:var(--radius-lg); padding:var(--space-6) var(--space-4); text-align:center; cursor:pointer; transition:border-color var(--transition-fast), background var(--transition-fast); }
.batch-drop:hover, .batch-drop:focus, .batch-drop.drag-over { border-color:var(--accent); background:var(--bg-subtle); outline:none; }
.batch-row { border-top:1px solid var(--border); padding:var(--space-3) 0; }
.batch-head { display:flex; align-items:center; justify-content:space-between; gap:var(--space-3); }
.batch-name { font-weight:var(--weight-medium); font-size:var(--text-sm); min-width:0; overflow:hidden; text-overflow:ellipsis; white-space:nowrap; }
.batch-msg { font-size:var(--text-sm); color:var(--text-secondary); margin-top:var(--space-1); }
.batch-actions { display:flex; flex-wrap:wrap; align-items:center; gap:var(--space-2); margin-top:var(--space-2); }
.batch-actions:empty { display:none; }
.batch-actions .batch-select { flex:1 1 100%; width:100%; max-width:100%; min-width:0; text-overflow:ellipsis; }
.batch-link { color:var(--accent); text-decoration:underline; }
.batch-bar { height:4px; border-radius:2px; background:var(--bg-subtle); margin-top:var(--space-2); overflow:hidden; }
.batch-bar > span { display:block; height:100%; width:0; background:var(--accent); transition:width .15s; }
.batch-tip { font-size:var(--text-xs); color:var(--warning); margin-top:var(--space-2); }
</style>
<?php endif; ?>

<!-- Documents: one card. Drop box (AI sorting) on top, the required documents below it. -->
<div class="card" id="docs-card" style="padding:var(--space-4) var(--space-5);margin-bottom:var(--space-4)">
<?php if (!$manualPage && $hasBatch): ?>
    <div id="batch-box">
    <div class="batch-drop" id="batch-drop" role="button" tabindex="0" aria-label="Choose files to upload">
        <p style="font-weight:var(--weight-medium);margin:0">Drop your files here</p>
        <p style="font-size:var(--text-sm);color:var(--text-tertiary);margin:var(--space-1) 0 var(--space-3)">or click to choose files · PDF, JPG, PNG or WEBP · max 4 MB each</p>
        <button type="button" class="btn btn-secondary btn-sm" id="batch-camera-btn">Take a photo</button>
    </div>
    <input type="file" id="batch-input" accept=".pdf,.jpg,.jpeg,.png,.webp" multiple style="display:none">
    <input type="file" id="batch-camera" accept="image/*" capture="environment" style="display:none">
    <input type="file" id="batch-replace" accept=".pdf,.jpg,.jpeg,.png,.webp" style="display:none">
    <div style="font-size:var(--text-sm);color:var(--text-tertiary);margin-top:var(--space-3)">Prefer to do it one by one? <a class="batch-link" href="<?= e(url('/student/documents/manual')) ?>">Upload your documents manually</a></div>
    <div id="batch-rows" style="display:flex;flex-direction:column;margin-top:var(--space-3)"></div>
    </div>
<?php endif; ?>

<!-- Document list -->
<style>#doc-list > div:first-child { border-top:0; padding-top:0; }</style>
<div id="doc-list" style="display:flex;flex-direction:column;<?= (!$manualPage && $hasBatch) ? 'margin-top:var(--space-4)' : '' ?>">
<?php foreach ($requiredDocs as $slug => $label):
    $doc    = $docRows[$slug] ?? null;
    $status = $doc['status'] ?? 'pending';
    $statusMap = [
        'pending'               => ['label' => 'Pending',              'class' => 'badge-pending'],
        'uploaded'              => ['label' => 'Uploaded',             'class' => 'badge-info'],
        'under_review'          => ['label' => 'Under Review',         'class' => 'badge-warning'],
        'approved'              => ['label' => 'Approved',             'class' => 'badge-success'],
        'rejected'              => ['label' => 'Declined',             'class' => 'badge-error'],
        'resubmission_required' => ['label' => 'Resubmission Required','class' => 'badge-error'],
    ];
    $badge      = $statusMap[$status] ?? $statusMap['pending'];
    $canUpload  = $isSubmitted
        ? in_array($status, ['rejected', 'resubmission_required'], true)
        : in_array($status, ['pending', 'rejected', 'resubmission_required', 'uploaded'], true);
    $uploadLabel = $status === 'pending' ? 'Upload' : 'Resubmit';
    $isApproved = $status === 'approved';
    $showUpload = $fullList && $canUpload;   // the list on the main page is read-only
?>
    <div style="padding:var(--space-3) 0;border-top:1px solid var(--border)">
        <div style="display:flex;align-items:center;gap:var(--space-4)">

            <!-- Icon -->
            <div style="width:40px;height:40px;border-radius:var(--radius-md);background:var(--bg-subtle);display:flex;align-items:center;justify-content:center;flex-shrink:0;<?= $isApproved ? 'background:var(--success-bg)' : '' ?>">
                <?php if ($isApproved): ?>
                    <?= icon('ic_fluent_checkmark_circle_24_regular', 18, 'color:var(--success)') ?>
                <?php else: ?>
                    <?= icon('ic_fluent_document_24_regular', 18, 'color:var(--text-tertiary)') ?>
                <?php endif; ?>
            </div>

            <!-- Info -->
            <div style="flex:1;min-width:0">
                <div style="font-weight:var(--weight-medium);color:var(--text-primary)"><?= e($label) ?></div>
                <?php if ($doc && $doc['staff_remarks']): ?>
                    <div style="font-size:var(--text-sm);color:var(--error);margin-top:2px">
                        Staff note: <?= e($doc['staff_remarks']) ?>
                    </div>
                <?php endif; ?>
            </div>

            <!-- Status badge — hide when Replace is shown (redundant) -->
            <?php if (!($showUpload && $status !== 'pending')): ?>
                <span class="badge <?= $badge['class'] ?>"><?= $badge['label'] ?></span>
            <?php endif; ?>

            <!-- View — always show when a file exists -->
            <?php if ($doc && $doc['file_path']): ?>
                <?php
                $fileIndex = -1;
                foreach ($viewableFiles as $fi => $vf) {
                    if ($vf['file_path'] === $doc['file_path']) { $fileIndex = $fi; break; }
                }
                ?>
                <button class="btn btn-ghost btn-sm"
                        onclick="openFileViewer(<?= $fileIndex ?>, <?= htmlspecialchars(json_encode($viewableFiles), ENT_QUOTES) ?>)"
                        type="button">View</button>
            <?php endif; ?>

            <!-- Upload / Replace button -->
            <?php if ($showUpload): ?>
                <button class="btn btn-secondary btn-sm"
                        onclick="openUploadModal('<?= $slug ?>', <?= htmlspecialchars(json_encode($label), ENT_QUOTES) ?>)">
                    <?= $uploadLabel ?>
                </button>
            <?php endif; ?>

        </div>
    </div>
<?php endforeach; ?>
</div>
</div>

<!-- Upload modal -->
<div id="upload-modal" class="modal-backdrop" style="display:none" aria-hidden="true">
    <div class="modal" style="max-width:420px">
        <div class="modal-header">
            <div class="modal-title" id="modal-doc-name">Upload Document</div>
            <button class="btn-icon" onclick="closeUploadModal()" aria-label="Close">
                <?= icon('ic_fluent_dismiss_24_regular', 18) ?>
            </button>
        </div>
        <form id="upload-form" enctype="multipart/form-data">
            <?= csrf_field() ?>
            <input type="hidden" name="doc_slug" id="modal-slug">
            <div class="modal-body">
                <div class="file-drop-zone" id="drop-zone"
                     data-no-auto-click style="cursor:pointer">
                    <input type="file" name="doc_file" id="file-input" class="file-input"
                           accept=".pdf,.jpg,.jpeg,.png,.webp" style="display:none">
                    <div class="file-drop-content" id="drop-content">
                        <svg width="32" height="32" fill="none" viewBox="0 0 24 24" style="color:var(--text-tertiary);margin-bottom:var(--space-3)"><path stroke="currentColor" stroke-width="1.5" d="M4 16l4-4 4 4 4-8 4 4"/><path stroke="currentColor" stroke-width="1.5" stroke-linecap="round" d="M4 20h16"/></svg>
                        <p style="font-weight:var(--weight-medium)">Drop your file here</p>
                        <p style="font-size:var(--text-sm);color:var(--text-tertiary)">or <span style="color:var(--accent)">browse</span></p>
                        <p style="font-size:var(--text-xs);color:var(--text-tertiary);margin-top:var(--space-2)">PDF, JPG, PNG or WEBP · max 4 MB</p>
                    </div>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-ghost" onclick="closeUploadModal()">Cancel</button>
                <button type="button" id="upload-submit-btn" class="btn btn-primary" onclick="submitUpload()">Upload</button>
            </div>
        </form>
    </div>
</div>

<!-- Hidden form for submit / withdraw — ensures CSRF token is always sent correctly -->
<form id="action-form" style="display:none">
    <?= csrf_field() ?>
    <input type="hidden" name="action" id="action-name" value="">
</form>

<!-- Result popup (success / error) -->
<div id="result-modal" class="modal-backdrop" style="display:none" aria-hidden="true">
    <div class="modal" style="max-width:380px;text-align:center">
        <div class="modal-body" style="padding:var(--space-8) var(--space-6)">
            <div id="result-icon" style="margin:0 auto var(--space-4);width:56px;height:56px;border-radius:50%;display:flex;align-items:center;justify-content:center"></div>
            <div id="result-title" style="font-size:var(--text-lg);font-weight:var(--weight-semibold);color:var(--text-primary);margin-bottom:var(--space-2)"></div>
            <div id="result-msg" style="font-size:var(--text-sm);color:var(--text-secondary)"></div>
        </div>
        <div class="modal-footer" style="justify-content:center">
            <button class="btn btn-primary" onclick="closeResultModal()">Done</button>
        </div>
    </div>
</div>

<script>
const UPLOAD_URL = '<?= url('/student/documents') ?>';
const CSRF_TOKEN = document.querySelector('#upload-form [name="_token"]')?.value ?? '';

function openUploadModal(slug, label) {
    document.getElementById('modal-slug').value = slug;
    document.getElementById('modal-doc-name').textContent = 'Upload: ' + label;
    // Reset drop zone
    document.getElementById('file-input').value = '';
    document.getElementById('drop-content').innerHTML =
        '<svg width="32" height="32" fill="none" viewBox="0 0 24 24" style="color:var(--text-tertiary);margin-bottom:var(--space-3)"><path stroke="currentColor" stroke-width="1.5" d="M4 16l4-4 4 4 4-8 4 4"/><path stroke="currentColor" stroke-width="1.5" stroke-linecap="round" d="M4 20h16"/></svg>' +
        '<p style="font-weight:var(--weight-medium)">Drop your file here</p>' +
        '<p style="font-size:var(--text-sm);color:var(--text-tertiary)">or <span style="color:var(--accent)">browse</span></p>' +
        '<p style="font-size:var(--text-xs);color:var(--text-tertiary);margin-top:var(--space-2)">PDF, JPG, PNG or WEBP · max 4 MB</p>';
    const modal = document.getElementById('upload-modal');
    modal.style.display = 'flex';
    modal.setAttribute('aria-hidden', 'false');
}

function closeUploadModal() {
    const modal = document.getElementById('upload-modal');
    modal.style.display = 'none';
    modal.setAttribute('aria-hidden', 'true');
}

function showResultModal(ok, title, msg) {
    const iconEl  = document.getElementById('result-icon');
    const titleEl = document.getElementById('result-title');
    const msgEl   = document.getElementById('result-msg');

    if (ok) {
        iconEl.style.background = 'var(--success-bg, #d1fae5)';
        iconEl.innerHTML = '<svg width="28" height="28" fill="none" viewBox="0 0 24 24" style="color:var(--success,#059669)"><path stroke="currentColor" stroke-width="2.5" stroke-linecap="round" d="M5 13l4 4L19 7"/></svg>';
    } else {
        iconEl.style.background = 'var(--error-bg, #fee2e2)';
        iconEl.innerHTML = '<svg width="28" height="28" fill="none" viewBox="0 0 24 24" style="color:var(--error,#dc2626)"><path stroke="currentColor" stroke-width="2.5" stroke-linecap="round" d="M6 18L18 6M6 6l12 12"/></svg>';
    }

    titleEl.textContent = title;
    msgEl.textContent   = msg;

    const modal = document.getElementById('result-modal');
    modal.style.display = 'flex';
    modal.setAttribute('aria-hidden', 'false');
}

function closeResultModal() {
    document.getElementById('result-modal').style.display = 'none';
    document.getElementById('result-modal').setAttribute('aria-hidden', 'true');
    // Reload page to reflect updated doc statuses
    window.location.reload();
}

async function submitUpload() {
    const fileInput = document.getElementById('file-input');
    if (!fileInput.files.length) {
        showResultModal(false, 'No file selected', 'Please choose a file before uploading.');
        closeUploadModal();
        return;
    }

    const btn = document.getElementById('upload-submit-btn');
    btn.disabled = true;
    btn.textContent = 'Uploading…';

    const formData = new FormData(document.getElementById('upload-form'));

    try {
        const res  = await fetch(UPLOAD_URL, {
            method: 'POST',
            headers: { 'X-Requested-With': 'XMLHttpRequest' },
            body: formData,
        });
        const data = await res.json();
        closeUploadModal();
        if (data.ok) {
            window.location.reload();
        } else {
            showResultModal(false, 'Upload failed', data.message);
        }
    } catch (err) {
        closeUploadModal();
        showResultModal(false, 'Network error', 'Something went wrong. Please try again.');
    } finally {
        btn.disabled = false;
        btn.textContent = 'Upload';
    }
}

async function postAction(action) {
    document.getElementById('action-name').value = action;
    const fd = new FormData(document.getElementById('action-form'));
    const res = await fetch(UPLOAD_URL, {
        method: 'POST',
        headers: { 'X-Requested-With': 'XMLHttpRequest' },
        body: fd,
    });
    const text = await res.text();
    try { return JSON.parse(text); } catch (e) {
        console.error('Non-JSON response:', text);
        throw new Error('Invalid server response');
    }
}

async function submitApplication() {
    if (!confirm('Submit your application for staff review?\n\nYou can withdraw the submission if you need to make changes.')) return;
    try {
        const data = await postAction('submit_application');
        if (data.ok) {
            showResultModal(true, 'Application submitted!', 'Your documents are now under staff review.');
        } else {
            showResultModal(false, 'Could not submit', data.message);
        }
    } catch (err) {
        showResultModal(false, 'Error', err.message);
    }
}

async function withdrawSubmission() {
    if (!confirm('Withdraw your submission?\n\nYou can make changes and re-submit whenever you\'re ready.')) return;
    try {
        const data = await postAction('withdraw_submission');
        if (data.ok) {
            showResultModal(true, 'Submission withdrawn', 'You can make changes and re-submit when ready.');
        } else {
            showResultModal(false, 'Could not withdraw', data.message);
        }
    } catch (err) {
        showResultModal(false, 'Error', err.message);
    }
}

// Close upload modal on backdrop click
document.getElementById('upload-modal').addEventListener('click', function(e) {
    if (e.target === this) closeUploadModal();
});

// Drag-and-drop support
const dropZone = document.getElementById('drop-zone');
dropZone.addEventListener('dragover', e => { e.preventDefault(); dropZone.style.borderColor = 'var(--accent)'; });
dropZone.addEventListener('dragleave', () => { dropZone.style.borderColor = ''; });
dropZone.addEventListener('drop', e => {
    e.preventDefault();
    dropZone.style.borderColor = '';
    const files = e.dataTransfer?.files;
    if (files?.length) {
        document.getElementById('file-input').files = files;
        updateDropLabel(files[0].name);
    }
});

// Single click handler — open file picker once.
// We handle it here so app.js FileDropZone doesn't fire a second input.click().
dropZone.addEventListener('click', (e) => {
    if (e.target !== document.getElementById('file-input')) {
        e.stopPropagation();
        document.getElementById('file-input').click();
    }
});

// Show chosen filename
document.getElementById('file-input').addEventListener('change', function() {
    if (this.files[0]) updateDropLabel(this.files[0].name);
});

function updateDropLabel(name) {
    document.getElementById('drop-content').innerHTML =
        '<svg width="28" height="28" fill="none" viewBox="0 0 24 24" style="color:var(--accent);margin-bottom:var(--space-2)"><path stroke="currentColor" stroke-width="1.5" stroke-linecap="round" d="M9 12h6m-6 4h6m2 5H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414A1 1 0 0121 9.414V19a2 2 0 01-2 2z"/></svg>' +
        '<p style="font-weight:var(--weight-medium);color:var(--accent)">' + name + '</p>' +
        '<p style="font-size:var(--text-sm);color:var(--text-tertiary)">Ready to upload</p>';
}
</script>

<!-- FILE VIEWER MODAL -->
<div id="file-viewer-modal" style="
    display:none;
    position:fixed;inset:0;z-index:9999;
    background:rgba(0,0,0,0.82);
    backdrop-filter:blur(4px);
    align-items:center;justify-content:center;
" aria-modal="true" role="dialog" aria-label="Document Viewer">

    <div style="
        position:relative;
        width:min(94vw,1100px);
        height:min(90vh,860px);
        background:var(--bg-elevated);
        border-radius:var(--radius-lg);
        box-shadow:var(--shadow-lg);
        display:flex;flex-direction:column;
        overflow:hidden;
    ">
        <!-- Header -->
        <div style="
            display:flex;align-items:center;gap:var(--space-3);
            padding:var(--space-3) var(--space-5);
            border-bottom:1px solid var(--border);
            flex-shrink:0;
        ">
            <button id="fv-prev" onclick="fvNavigate(-1)" type="button" style="
                display:flex;align-items:center;justify-content:center;
                width:32px;height:32px;border-radius:var(--radius-sm);
                border:1px solid var(--border);background:var(--bg);
                color:var(--text-secondary);cursor:pointer;flex-shrink:0;
                transition:background var(--transition-fast),color var(--transition-fast);
            " title="Previous (←)">
                <?= icon('ic_fluent_chevron_left_24_regular', 15) ?>
            </button>
            <div style="flex:1;min-width:0">
                <div id="fv-label" style="font-weight:var(--weight-semibold);font-size:var(--text-sm);white-space:nowrap;overflow:hidden;text-overflow:ellipsis"></div>
                <div id="fv-counter" style="font-size:var(--text-xs);color:var(--text-tertiary);margin-top:1px"></div>
            </div>
            <button id="fv-next" onclick="fvNavigate(1)" type="button" style="
                display:flex;align-items:center;justify-content:center;
                width:32px;height:32px;border-radius:var(--radius-sm);
                border:1px solid var(--border);background:var(--bg);
                color:var(--text-secondary);cursor:pointer;flex-shrink:0;
                transition:background var(--transition-fast),color var(--transition-fast);
            " title="Next (→)">
                <?= icon('ic_fluent_chevron_right_24_regular', 15) ?>
            </button>
            <div style="width:1px;height:24px;background:var(--border);flex-shrink:0"></div>
            <button onclick="fvZoom(-0.25)" type="button" class="fv-ctrl-btn" title="Zoom out (−)">
                <?= icon('ic_fluent_subtract_24_regular', 14) ?>
            </button>
            <span id="fv-zoom-label" style="font-size:var(--text-xs);color:var(--text-secondary);min-width:38px;text-align:center;font-variant-numeric:tabular-nums">100%</span>
            <button onclick="fvZoom(0.25)" type="button" class="fv-ctrl-btn" title="Zoom in (+)">
                <?= icon('ic_fluent_add_24_regular', 14) ?>
            </button>
            <button onclick="fvResetZoom()" type="button" class="fv-ctrl-btn" title="Reset zoom (0)">
                <svg width="14" height="14" fill="none" viewBox="0 0 24 24"><path stroke="currentColor" stroke-width="2" stroke-linecap="round" d="M21 21l-4.35-4.35m0 0A7 7 0 105.65 5.65a7 7 0 0011 11.35z"/></svg>
            </button>
            <div style="width:1px;height:24px;background:var(--border);flex-shrink:0"></div>
            <button onclick="closeFileViewer()" type="button" class="fv-ctrl-btn" title="Close (Esc)" aria-label="Close">
                <?= icon('ic_fluent_dismiss_24_regular', 15) ?>
            </button>
        </div>
        <!-- Viewport -->
        <div id="fv-viewport" style="
            flex:1;overflow:hidden;position:relative;
            background:var(--bg-subtle);min-height:300px;
            cursor:default;user-select:none;
        ">
            <div id="fv-transform-wrap" style="
                position:absolute;top:0;left:0;
                width:100%;height:100%;
                display:flex;align-items:center;justify-content:center;
                will-change:transform;
                transform-origin:center center;
            ">
                <!-- content injected by _render() -->
            </div>
            <div id="fv-hint" style="
                position:absolute;bottom:12px;left:50%;transform:translateX(-50%);
                background:rgba(0,0,0,0.55);color:#fff;
                font-size:var(--text-xs);padding:5px 14px;border-radius:var(--radius-full);
                pointer-events:none;opacity:0;transition:opacity .4s ease;white-space:nowrap;
            ">Scroll to zoom · Drag to pan when zoomed in</div>
        </div>
        <div id="fv-dots" style="
            display:flex;align-items:center;justify-content:center;gap:6px;
            padding:var(--space-3);border-top:1px solid var(--border);flex-shrink:0;flex-wrap:wrap;
        "></div>
    </div>
</div>

<style>
.fv-ctrl-btn {
    display:flex;align-items:center;justify-content:center;
    width:30px;height:30px;border-radius:var(--radius-sm);
    border:1px solid var(--border);background:var(--bg);
    color:var(--text-secondary);cursor:pointer;
    transition:background var(--transition-fast),color var(--transition-fast);
}
.fv-ctrl-btn:hover { background:var(--bg-overlay); color:var(--text-primary); }
#fv-prev:hover, #fv-next:hover { background:var(--bg-overlay); color:var(--text-primary); }
#fv-viewport[data-zoomed="true"]   { cursor:grab; }
#fv-viewport[data-dragging="true"] { cursor:grabbing !important; }
</style>

<script>
(function(){
    var _files=[], _idx=0, _scale=1, _tx=0, _ty=0;
    var _drag=false, _ds={x:0,y:0}, _touch=null;

    window.openFileViewer = function(idx, files) {
        _files=files; _idx=idx; _scale=1; _tx=0; _ty=0;
        _render();
        document.getElementById('file-viewer-modal').style.display='flex';
        document.body.style.overflow='hidden';
        var h=document.getElementById('fv-hint');
        if(h){ h.style.opacity='1'; setTimeout(function(){ h.style.opacity='0'; },2800); }
    };

    window.closeFileViewer = function() {
        document.getElementById('file-viewer-modal').style.display='none';
        document.body.style.overflow='';
        document.getElementById('fv-transform-wrap').innerHTML='';
    };

    window.fvNavigate = function(d) {
        var n=_idx+d;
        if(n<0||n>=_files.length) return;
        _idx=n; _scale=1; _tx=0; _ty=0; _render();
    };

    window.fvZoom = function(delta) {
        _scale=Math.min(5,Math.max(0.5,_scale+delta));
        if(_scale<=1){ _tx=0; _ty=0; }
        _apply();
    };

    window.fvResetZoom = function() { _scale=1; _tx=0; _ty=0; _apply(); };

    function _render() {
        var f=_files[_idx];
        if(!f) return;
        var wrap=document.getElementById('fv-transform-wrap');
        var isPdf=f.url.toLowerCase().split('?')[0].endsWith('.pdf');
        if(isPdf){
            wrap.innerHTML='<iframe src="'+f.url+'" style="width:100%;height:78vh;border:none;border-radius:var(--radius-sm);background:#fff;"></iframe>';
        } else {
            wrap.innerHTML='<img src="'+f.url+'" alt="Document preview" style="max-width:100%;max-height:78vh;border-radius:var(--radius-sm);box-shadow:var(--shadow-md);display:block;pointer-events:none;user-select:none;-webkit-user-drag:none;">';
        }
        document.getElementById('fv-label').textContent=f.label;
        document.getElementById('fv-counter').textContent=(_idx+1)+' of '+_files.length;
        var p=document.getElementById('fv-prev');
        var n=document.getElementById('fv-next');
        p.disabled=(_idx===0); p.style.opacity=(_idx===0)?'0.35':'1';
        n.disabled=(_idx===_files.length-1); n.style.opacity=(_idx===_files.length-1)?'0.35':'1';
        _apply();
        _dots();
        var h=document.getElementById('fv-hint');
        if(h){ h.style.opacity='1'; setTimeout(function(){ h.style.opacity='0'; },2800); }
    }

    function _apply() {
        var w=document.getElementById('fv-transform-wrap');
        w.style.transform='translate('+_tx+'px,'+_ty+'px) scale('+_scale+')';
        document.getElementById('fv-zoom-label').textContent=Math.round(_scale*100)+'%';
        var vp=document.getElementById('fv-viewport');
        vp.dataset.zoomed=(_scale>1)?'true':'false';
    }

    function _dots() {
        var c=document.getElementById('fv-dots');
        c.innerHTML='';
        _files.forEach(function(f,i){
            var b=document.createElement('button');
            b.type='button';
            b.style.cssText='width:8px;height:8px;border-radius:50%;border:none;padding:0;cursor:pointer;flex-shrink:0;transition:background .15s,transform .15s;';
            b.style.background=(i===_idx)?'var(--accent)':'var(--border-strong)';
            b.style.transform=(i===_idx)?'scale(1.35)':'scale(1)';
            b.title=f.label;
            b.onclick=function(){ _idx=i; _scale=1; _tx=0; _ty=0; _render(); };
            c.appendChild(b);
        });
    }

    document.addEventListener('DOMContentLoaded',function(){
        var vp=document.getElementById('fv-viewport');
        vp.addEventListener('mousedown',function(e){
            if(_scale<=1) return;
            _drag=true; _ds={x:e.clientX-_tx,y:e.clientY-_ty};
            vp.dataset.dragging='true'; e.preventDefault();
        });
        window.addEventListener('mousemove',function(e){
            if(!_drag) return;
            _tx=e.clientX-_ds.x; _ty=e.clientY-_ds.y; _apply();
        });
        window.addEventListener('mouseup',function(){
            if(_drag){ _drag=false; document.getElementById('fv-viewport').dataset.dragging='false'; }
        });
        vp.addEventListener('touchstart',function(e){
            if(_scale<=1) return;
            var t=e.touches[0]; _touch={x:t.clientX-_tx,y:t.clientY-_ty}; e.preventDefault();
        },{passive:false});
        vp.addEventListener('touchmove',function(e){
            if(!_touch) return;
            var t=e.touches[0]; _tx=t.clientX-_touch.x; _ty=t.clientY-_touch.y; _apply(); e.preventDefault();
        },{passive:false});
        vp.addEventListener('touchend',function(){ _touch=null; });
        vp.addEventListener('wheel',function(e){
            e.preventDefault();
            var d=e.deltaY>0?-0.15:0.15;
            _scale=Math.min(5,Math.max(0.5,_scale+d));
            if(_scale<=1){ _tx=0; _ty=0; }
            _apply();
        },{passive:false});
        document.getElementById('file-viewer-modal').addEventListener('click',function(e){
            if(e.target===this) closeFileViewer();
        });
    });

    document.addEventListener('keydown',function(e){
        if(document.getElementById('file-viewer-modal').style.display!=='flex') return;
        if(e.key==='Escape') closeFileViewer();
        if(e.key==='ArrowLeft') fvNavigate(-1);
        if(e.key==='ArrowRight') fvNavigate(1);
        if(e.key==='+'||e.key==='=') fvZoom(0.25);
        if(e.key==='-') fvZoom(-0.25);
        if(e.key==='0') fvResetZoom();
    });
})();
</script>

<!-- Submit / Withdraw panel -->
<div id="doc-submit">
<?php if ($canSubmit): ?>
<div class="card" style="margin-top:var(--space-4);padding:var(--space-5);display:flex;align-items:center;gap:var(--space-4)">
    <div style="flex:1">
        <div style="font-weight:var(--weight-medium);color:var(--text-primary)">Ready to submit</div>
    </div>
    <button class="btn btn-primary" type="button" onclick="submitApplication()">Submit Application</button>
</div>
<?php elseif ($canWithdraw): ?>
<div class="card" style="margin-top:var(--space-4);padding:var(--space-5);display:flex;align-items:center;gap:var(--space-4)">
    <div style="flex:1">
        <div style="font-weight:var(--weight-medium);color:var(--text-primary)">Application submitted</div>
        <div style="font-size:var(--text-sm);color:var(--text-secondary);margin-top:2px">Your documents are awaiting staff review.</div>
    </div>
    <div style="text-align:right;flex-shrink:0">
        <button class="btn btn-ghost btn-sm" type="button" onclick="withdrawSubmission()">Withdraw Submission</button>
    </div>
</div>
<?php elseif (!$allUploaded && !$isSubmitted): ?>
<div class="card" style="margin-top:var(--space-4);padding:var(--space-5);display:flex;align-items:center;gap:var(--space-4);opacity:.6">
    <div style="flex:1">
        <div style="font-size:var(--text-sm);color:var(--text-secondary)">Upload all required documents to enable submission.</div>
    </div>
    <button class="btn btn-primary" disabled style="cursor:not-allowed">Submit Application</button>
</div>
<?php endif; ?>
</div>

<script>
// 1D: save ticks with $.ajax, then refresh the slot list and submit panel without a reload.
// jQuery loads after page content, so wait for DOMContentLoaded.
document.addEventListener('DOMContentLoaded', function () {
    var $ = window.jQuery;
    if (!$) return;

    // Reusable: re-fetch this page and swap in the fresh slot list + submit panel.
    // Used by the ticks (1.18) and by the batch upload box (5.11).
    window.refreshSlotList = function () {
        return $.get(window.location.href).done(function (html) {
            var $page = $('<div>').append($.parseHTML(html));
            $('#doc-list').html($page.find('#doc-list').html());
            $('#doc-submit').html($page.find('#doc-submit').html());
        });
    };

    if (!$('#flags-form').length) return;

    function say(ok, msg) {
        $('#flags-msg').text(msg).css('color', ok ? 'var(--success)' : 'var(--error)').show();
    }

    $('#flags-form').on('change', 'input[type=checkbox]', function () {
        var $box = $(this);
        $.ajax({
            url: $('#flags-form').attr('action') || window.location.href,
            method: 'POST',
            data: $('#flags-form').serialize(),
            dataType: 'json'
        }).done(function (res) {
            if (res.ok) {
                say(true, res.message);
                window.refreshSlotList();
            } else {
                $box.prop('checked', !$box.prop('checked')); // put it back
                say(false, res.message);
            }
        }).fail(function () {
            $box.prop('checked', !$box.prop('checked'));
            say(false, 'Could not save. Please try again.');
        });
    });
});
</script>

<script>
// Phase 5: Upload many files. One file per request (action=classify), sequential queue.
// ponytail: queue lives in memory only (a page reload drops unfinished rows); upgrade path is none needed, saved files stay in the slot list.
document.addEventListener('DOMContentLoaded', function () {
    var $ = window.jQuery;
    if (!$ || !$('#batch-box').length) return;

    var URL_  = <?= json_encode(url('/student/documents')) ?>;
    var MANUAL_URL = <?= json_encode(url('/student/documents/manual')) ?>;
    var CSRF  = $('#flags-form [name=_csrf], #upload-form [name=_csrf]').first().val() || <?= json_encode(csrf_token()) ?>;
    var MAX   = 4 * 1024 * 1024;
    var BODY  = 4.3 * 1024 * 1024;   // Vercel rejects request bodies over 4.5 MB; the PDF and its preview travel together
    var AI_PX = 1024;                // longest side of the picture the AI reads (keep in step with llama-server --image-max-tokens)
    var PDFJS = <?= json_encode(asset('js/pdf.min.js')) ?>, PDFJS_WORKER = <?= json_encode(asset('js/pdf.worker.min.js')) ?>;
    var OK    = ['image/jpeg', 'image/png', 'image/webp', 'application/pdf'];
    var rows  = {}, queue = [], busy = false, seq = 0, lastCats = null, replaceId = null;

    function badge(cls, text) { return $('<span class="badge">').addClass(cls).text(text); }
    function bytesOk(b) { return b.size <= MAX; }

    // 5.3: shrink to AI_PX JPEG. Falls back to the original file if the browser cannot decode it.
    function shrink(file) {
        var d = $.Deferred();
        if (file.type === 'application/pdf') return d.resolve(file).promise();
        var img = new Image(), src = URL.createObjectURL(file);
        img.onload = function () {
            var k = Math.min(1, AI_PX / Math.max(img.width, img.height));
            var c = document.createElement('canvas');
            c.width = Math.round(img.width * k); c.height = Math.round(img.height * k);
            var x = c.getContext('2d');
            x.fillStyle = '#fff'; x.fillRect(0, 0, c.width, c.height);
            x.drawImage(img, 0, 0, c.width, c.height);
            URL.revokeObjectURL(src);
            c.toBlob(function (b) { d.resolve(b || file); }, 'image/jpeg', 0.85);
        };
        img.onerror = function () { URL.revokeObjectURL(src); d.resolve(file); };
        img.src = src;
        return d.promise();
    }

    // PDF first page -> JPEG for the AI. pdf.js is loaded only when a PDF is picked.
    // Any failure (script blocked, locked or broken PDF) just means no preview: the applicant picks the type.
    var pdfLib = null;
    function loadPdfJs() {
        if (pdfLib) return pdfLib;
        pdfLib = new Promise(function (resolve, reject) {
            if (window.pdfjsLib) return resolve(window.pdfjsLib);
            var s = document.createElement('script');
            s.src = PDFJS;
            s.onload = function () {
                if (!window.pdfjsLib) return reject(new Error('pdf.js missing'));
                window.pdfjsLib.GlobalWorkerOptions.workerSrc = PDFJS_WORKER;
                resolve(window.pdfjsLib);
            };
            s.onerror = function () { reject(new Error('pdf.js failed to load')); };
            document.head.appendChild(s);
        });
        return pdfLib;
    }

    function renderPdf(file) {
        return loadPdfJs().then(function (lib) {
            return file.arrayBuffer().then(function (buf) {
                return lib.getDocument({ data: buf, isEvalSupported: false }).promise;
            });
        }).then(function (pdf) {
            return pdf.getPage(1);
        }).then(function (page) {
            var v1 = page.getViewport({ scale: 1 });
            var vp = page.getViewport({ scale: AI_PX / Math.max(v1.width, v1.height) });
            var c = document.createElement('canvas');
            c.width = Math.round(vp.width); c.height = Math.round(vp.height);
            var x = c.getContext('2d');
            x.fillStyle = '#fff'; x.fillRect(0, 0, c.width, c.height);
            return page.render({ canvasContext: x, viewport: vp }).promise.then(function () {
                return new Promise(function (resolve, reject) {
                    c.toBlob(function (b) { b ? resolve(b) : reject(new Error('no preview')); }, 'image/jpeg', 0.85);
                });
            });
        });
    }

    // Sets r.preview (a JPEG blob, or null). Runs once per file.
    function previewFor(r) {
        var d = $.Deferred();
        if (!r.blob || r.blob.type !== 'application/pdf' || r.preview !== undefined) return d.resolve().promise();
        busyState(r, 'Reading PDF…');
        renderPdf(r.blob).then(function (b) {
            r.preview = (r.blob.size + b.size <= BODY) ? b : null;
            d.resolve();
        }, function () { r.preview = null; d.resolve(); });
        return d.promise();
    }

    function newRow(file) {
        var id = ++seq;
        var $el = $('<div class="batch-row">').attr('data-id', id).append(
            $('<div class="batch-head">').append($('<span class="batch-name">').text(file.name), $('<span class="batch-state">')),
            $('<div class="batch-msg">'), $('<div class="batch-bar" style="display:none"><span></span></div>'), $('<div class="batch-actions">')
        );
        $('#batch-rows').append($el);
        rows[id] = { id: id, $el: $el, file: file, blob: null, preview: undefined, tries: 0, guess: '' };
        return rows[id];
    }

    function show(r, cls, label, msg) {
        r.$el.find('.batch-state').empty().append(badge(cls, label));
        r.$el.find('.batch-msg').text(msg || '');
        r.$el.find('.batch-actions').empty();
        r.$el.find('.batch-tip').remove();
    }

    function busyState(r, label) {
        show(r, 'badge-info', label, '');
        r.$el.find('.batch-bar').show().find('span').css('width', '0');
    }

    function tip(r) {
        // 5.10: after 2 failed tries, point to one by one upload.
        if (r.tries >= 2) r.$el.append($('<div class="batch-tip">').text('Still not working? ').append($('<a class="batch-link">').attr('href', MANUAL_URL).text('Upload your documents manually.')));
    }

    function actionBtn(text, fn, primary) {
        return $('<button type="button" class="btn btn-sm">').addClass(primary ? 'btn-primary' : 'btn-ghost').text(text).on('click', fn);
    }

    function dropdown(r, cats, pre) {
        var $sel = $('<select class="form-select batch-select" style="min-height:32px;font-size:var(--text-sm)">').append($('<option value="">').text('Choose document type…'));
        $.each(cats, function (_, c) {
            var $o = $('<option>').val(c.category).text(c.label + (c.note ? ' — ' + c.note : ''));
            if (!c.available) $o.prop('disabled', true);
            $sel.append($o);
        });
        var $go = actionBtn('Use this type', function () { pick(r, $sel.val()); }, true).prop('disabled', true);
        $sel.on('change', function () { $go.prop('disabled', !$sel.val()); });
        // The AI's guess is already picked, so confirming it is one tap.
        var g = $.grep(cats, function (c) { return c.category === pre && c.available; })[0];
        if (g) { $sel.val(g.category); $go.prop('disabled', false); }
        return [$sel, $go];
    }

    function offer(r, kind, msg, cats) {
        // kind: 'uncertain' (Not sure) | 'failed' (Unreadable) | 'error'
        cats = cats && cats.length ? cats : lastCats;
        var cls = kind === 'uncertain' ? 'badge-warning' : 'badge-error';
        var label = kind === 'uncertain' ? 'Not sure' : (kind === 'failed' ? 'Unreadable' : 'Error');
        show(r, cls, label, msg);
        var $a = r.$el.find('.batch-actions');
        if (kind === 'uncertain') $a.append(actionBtn('Replace file', function () { replaceId = r.id; $('#batch-replace').removeAttr('capture').val('').trigger('click'); }));
        else if (kind === 'failed') $a.append(actionBtn('Retake', function () { replaceId = r.id; $('#batch-replace').attr('capture', 'environment').val('').trigger('click'); }));
        else $a.append(actionBtn('Try again', function () { r.tries = 0; enqueue(r); }));
        if (cats && cats.length) $a.prepend.apply($a, dropdown(r, cats, kind === 'uncertain' ? r.guessCat : ''));
        $a.append(actionBtn('Remove', function () { r.$el.remove(); delete rows[r.id]; }));
        tip(r);
    }

    function done(r, label, cls, msg) {
        show(r, cls, label, msg);
        r.blob = null; r.file = null;
        r.$el.find('.batch-actions').append(actionBtn('Dismiss', function () { r.$el.remove(); delete rows[r.id]; }));
    }

    // A sorted file now shows as Uploaded in the document list, so its result row goes away once the list has refreshed.
    // If the refresh fails, keep the row so the applicant still sees what happened.
    function sorted(r, msg) {
        r.blob = null; r.file = null;
        window.refreshSlotList().done(function () { r.$el.remove(); delete rows[r.id]; })
            .fail(function () { done(r, 'Sorted', 'badge-success', msg); });
    }

    // jQuery calls fail() for a timeout, a 5xx and a connection drop alike; tell them apart for the applicant.
    function failMessage(xhr, status) {
        if (status === 'timeout' || (xhr && [502, 503, 504].indexOf(xhr.status) >= 0)) return 'This took too long. Please try again.';
        if (xhr && xhr.status > 0) return 'Something went wrong on the server. Please try again.';
        return 'Could not reach the server. Check your connection and try again.';
    }

    function send(r, data) {
        return $.ajax({
            url: URL_, method: 'POST', data: data, processData: false, contentType: false,
            dataType: 'json', timeout: 70000, headers: { 'X-Requested-With': 'XMLHttpRequest' },
            xhr: function () {
                var x = $.ajaxSettings.xhr();
                if (x.upload) x.upload.onprogress = function (e) {
                    if (!e.lengthComputable) return;
                    var p = Math.round(e.loaded / e.total * 100);
                    r.$el.find('.batch-bar span').css('width', p + '%');
                    if (p >= 100) r.$el.find('.batch-state .badge').text('Checking…');
                };
                return x;
            }
        });
    }

    function classify(r) {
        var d = $.Deferred();
        busyState(r, 'Uploading…');
        var fd = new FormData();
        fd.append('_csrf', CSRF); fd.append('action', 'classify');
        fd.append('doc_file', r.blob, r.blob.name || r.file.name);
        if (r.preview) fd.append('ai_preview', r.preview, 'preview.jpg');
        send(r, fd).done(function (res) {
            r.$el.find('.batch-bar').hide();
            if (!res || res.ok === false) { r.tries++; offer(r, 'error', (res && res.message) || 'Something went wrong. Please try again.'); }
            else if (res.categories && res.categories.length) lastCats = res.categories;
            if (res && res.ok !== false) {
                r.guess = res.guess || '';
                r.guessCat = res.guess_category || '';
                if (res.status === 'passed') sorted(r, res.message || 'Saved.');
                else if (res.status === 'blocked') { done(r, 'Blocked', 'badge-error', res.message); window.refreshSlotList && window.refreshSlotList(); }
                else if (res.status === 'failed') { r.tries++; offer(r, 'failed', res.message, res.categories); }
                else { r.tries++; offer(r, 'uncertain', res.message, res.categories); }
            }
        }).fail(function (xhr, status) {
            r.$el.find('.batch-bar').hide(); r.tries++;
            offer(r, 'error', failMessage(xhr, status));
        }).always(function () { d.resolve(); });
        return d.promise();
    }

    // 5.9: send the kept file to the existing upload action with the chosen category.
    function pick(r, cat) {
        if (!cat || !r.blob) return;
        busyState(r, 'Saving…');
        var fd = new FormData();
        fd.append('_csrf', CSRF); fd.append('doc_slug', cat); fd.append('picked', '1'); fd.append('ai_guess', r.guess || '');
        fd.append('doc_file', r.blob, r.blob.name || r.file.name);
        send(r, fd).done(function (res) {
            r.$el.find('.batch-bar').hide();
            if (res && res.ok) sorted(r, res.message || 'Saved.');
            else offer(r, 'error', (res && res.message) || 'Could not save this file.', lastCats);
        }).fail(function (xhr, status) {
            r.$el.find('.batch-bar').hide();
            offer(r, 'error', failMessage(xhr, status), lastCats);
        });
    }

    function enqueue(r) {
        show(r, 'badge-pending', 'Waiting', '');
        queue.push(r); run();
    }

    function run() {
        if (busy || !queue.length) return;
        busy = true;
        var r = queue.shift();
        if (!rows[r.id]) { busy = false; return run(); }
        (r.blob ? $.Deferred().resolve(r.blob).promise() : shrink(r.file)).then(function (b) {
            if (!(b instanceof Blob)) b = r.file;
            if (!b.name) b.name = r.file.name.replace(/\.[^.]+$/, '') + (b.type === 'image/jpeg' ? '.jpg' : '');
            if (!bytesOk(b)) { offer(r, 'error', 'This file is over the 4 MB limit.'); return; }
            r.blob = b;
            return previewFor(r).then(function () { return classify(r); });
        }).always(function () { busy = false; run(); });
    }

    function addFiles(list) {
        $.each(list, function (_, f) {
            var r = newRow(f);
            if (OK.indexOf(f.type) < 0) { show(r, 'badge-error', 'Error', 'Only PDF, JPG, PNG, and WEBP files are accepted.'); r.$el.find('.batch-actions').append(actionBtn('Dismiss', function () { r.$el.remove(); delete rows[r.id]; })); return; }
            enqueue(r);
        });
    }

    // Replace file / Retake: swap the kept file and classify the same row again.
    $('#batch-replace').on('change', function () {
        var f = this.files[0], r = rows[replaceId];
        if (!f || !r) return;
        if (OK.indexOf(f.type) < 0) { offer(r, 'error', 'Only PDF, JPG, PNG, and WEBP files are accepted.'); return; }
        r.file = f; r.blob = null; r.preview = undefined; r.$el.find('.batch-name').text(f.name);
        enqueue(r);
    });

    var $drop = $('#batch-drop');
    $drop.on('click', function () { $('#batch-input').val('').trigger('click'); });
    $drop.on('keydown', function (e) { if (e.key === 'Enter' || e.key === ' ') { e.preventDefault(); $('#batch-input').val('').trigger('click'); } });
    $('#batch-camera-btn').on('click', function (e) { e.stopPropagation(); $('#batch-camera').val('').trigger('click'); });
    $('#batch-input, #batch-camera').on('change', function () { addFiles(this.files); });
    $drop.on('dragover dragenter', function (e) { e.preventDefault(); $drop.addClass('drag-over'); });
    $drop.on('dragleave drop', function (e) { e.preventDefault(); $drop.removeClass('drag-over'); });
    $drop.on('drop', function (e) { addFiles(e.originalEvent.dataTransfer.files); });
});
</script>

<!-- Step navigation -->
<div class="step-nav">
    <span></span>
    <?php if ($allApproved && $_examResult): ?>
        <a href="<?= url('/student/interview') ?>" class="btn btn-primary">Interview →</a>
    <?php elseif ($allApproved && !$_examResult): ?>
        <a href="<?= url('/student/exam') ?>" class="btn btn-primary">Entrance Exam →</a>
    <?php else: ?>
        <span class="btn btn-primary" style="opacity:.4;cursor:not-allowed" title="All documents must be approved first">Entrance Exam →</span>
    <?php endif; ?>
</div>

<?php
$content     = ob_get_clean();
$pageTitle   = $manualPage ? 'Upload Documents Manually' : 'My Documents';
$activeNav   = 'documents';
$showStepper = true;
include VIEWS_PATH . '/layouts/app.php';
