<?php
// ============================================================
// core/slot_cancel.php
//
// Shared "bulk cancel a slot and move everyone booked into it
// to a replacement slot" service, used by:
//   modules/interview/staff_cancel_slot.php
//   modules/exam/staff_cancel_slot.php
//
// Both modules share the same flow (validate -> lock both slots
// -> capacity check -> move applicants -> close/update counters
// -> log history -> commit -> notify -> audit -> flash), differing
// only in table/column names and a couple of kind-specific checks
// (interview's queue numbering + today/now cutoff, exam's
// same-exam guard). Those differences are passed in as callables
// in $cfg rather than forced into a one-size-fits-all query, so
// each module keeps its own SQL for the parts that actually
// differ.
//
// $cfg comes from code constants / closures defined by the calling
// module, never from request data:
//   noun          'interview' | 'exam'               (for messages/audit)
//   redirect      page to redirect back to
//   loadSlot      fn(PDO $db, int $id): ?array        - slot + booked count, FOR UPDATE-safe to re-select after lock
//   lockTable     table name used for the FOR UPDATE id lock (slot table)
//   capacityOf    fn(array $slot): int                - total seats
//   bookedOf      fn(array $slot): int                 - seats currently used
//   validateTarget fn(array $oldSlot, array $newSlot, string $today): ?string
//                  - kind-specific checks beyond open/capacity (e.g. same-exam
//                    guard, today/now cutoff); returns an error message or null
//   moveApplicants fn(PDO $db, int $sourceId, int $targetId, int $staffId, string $reason): array
//                  - does the actual UPDATE(s) + per-row history insert,
//                    returns the list of moved applicant_ids
//   describeSlot  fn(array $slot): string             - "May 14, 2026, 9:00 AM at Room 101" for the notification
//   auditAction   e.g. 'interview_slot_cancelled_bulk'
// ============================================================

function cancel_slot_and_move(array $cfg): never
{
    $db      = db();
    $staffId = Auth::id();

    $sourceId = post_int('source_slot_id');
    $targetId = post_int('target_slot_id');
    $reason   = post_str('reason');

    if ($sourceId <= 0 || $targetId <= 0) {
        fail('Pick both a slot to cancel and a replacement slot.', $cfg['redirect']);
    }
    if ($sourceId === $targetId) {
        fail('The replacement slot must be different from the slot you\'re cancelling.', $cfg['redirect']);
    }
    if ($reason === '') {
        fail('Please enter a reason - it\'s shown to the affected students.', $cfg['redirect']);
    }

    $today    = date('Y-m-d');
    $movedIds = [];
    $newSlot  = null;
    $errMsg   = null;

    try {
        $db->beginTransaction();

        // Lock both slots up front (lowest id first to avoid deadlocks).
        $lockIds = [$sourceId, $targetId];
        sort($lockIds);
        $lockStmt = $db->prepare(
            "SELECT id FROM {$cfg['lockTable']} WHERE id IN (?, ?) FOR UPDATE"
        );
        $lockStmt->execute($lockIds);
        $lockStmt->fetchAll();

        $oldSlot = ($cfg['loadSlot'])($db, $sourceId);
        if (!$oldSlot) {
            $errMsg = 'The slot you wanted to cancel no longer exists.';
            throw new \RuntimeException($errMsg);
        }

        $newSlot = ($cfg['loadSlot'])($db, $targetId);
        if (!$newSlot) {
            $errMsg = 'The replacement slot you picked no longer exists.';
            throw new \RuntimeException($errMsg);
        }

        $kindErr = ($cfg['validateTarget'])($oldSlot, $newSlot, $today);
        if ($kindErr !== null) {
            $errMsg = $kindErr;
            throw new \RuntimeException($errMsg);
        }

        $needed    = ($cfg['bookedOf'])($oldSlot);
        $spotsLeft = ($cfg['capacityOf'])($newSlot) - ($cfg['bookedOf'])($newSlot);
        if ($needed > $spotsLeft) {
            $errMsg = "Replacement slot only has {$spotsLeft} open seat(s) but {$needed} student(s) need to move. "
                    . 'Pick a slot with more capacity, or create one first.';
            throw new \RuntimeException($errMsg);
        }

        $movedIds = ($cfg['moveApplicants'])($db, $sourceId, $targetId, $staffId, $reason);

        $db->commit();
    } catch (\Throwable $e) {
        if ($db->inTransaction()) $db->rollBack();
        if (!$errMsg) {
            error_log("bulk cancel_slot ({$cfg['noun']}) failed: " . $e->getMessage());
            $errMsg = 'Could not cancel that slot - please try again.';
        }
        fail($errMsg, $cfg['redirect']);
    }

    // Notify every affected student outside the transaction.
    if (!empty($movedIds) && $newSlot) {
        $when  = ($cfg['describeSlot'])($newSlot);
        $extra = "Your new {$cfg['noun']} slot is {$when}"
               . ($reason !== '' ? '. Reason: ' . $reason : '.');
        foreach ($movedIds as $aid) {
            notify_reschedule_decision($aid, $cfg['noun'], 'approved', $extra);
        }
    }

    audit_log(
        $cfg['auditAction'],
        "Cancelled {$cfg['noun']} slot #{$sourceId} and moved " . count($movedIds)
        . " applicant(s) to slot #{$targetId} - {$reason}",
        "{$cfg['noun']}_slot",
        $sourceId
    );

    ok(
        'Cancelled slot and moved ' . count($movedIds) . ' student(s) to the replacement slot. They have been notified.',
        $cfg['redirect']
    );
}
