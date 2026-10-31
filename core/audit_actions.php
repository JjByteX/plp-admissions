<?php
// ============================================================
// core/audit_actions.php
// Action names written to audit_logs.action. Use these instead of
// string literals so a typo is a fatal error, not a silent new action.
// The values are what is stored in the database, so never rename one.
// ============================================================

final class AuditAction
{
    /** Enrollment status callbacks log 'enrollment_status_' . $stage. */
    public const ENROLLMENT_STATUS_PREFIX = 'enrollment_status_';

    public const ADMIN_PASSWORD_CHANGED = 'admin_password_changed';
    public const ADMISSION_CLOSE_ADMISSIONS = 'admission_close_admissions';
    public const ADMISSION_PRINT = 'admission_print';
    public const ADMISSION_RESULT = 'admission_result';
    public const ADMISSION_RESULT_OVERRIDE = 'admission_result_override';
    public const ADMISSION_RESULT_RELEASED = 'admission_result_released';
    public const ADMISSION_RESULT_RELEASED_OVERRIDE = 'admission_result_released_override';
    public const ADMISSIONS_WINDOW_SET = 'admissions_window_set';
    public const APPLICANT_ADVANCED_EXAM = 'applicant_advanced_exam';
    public const APPLICANT_ADVANCED_EXAM_SELFHEAL = 'applicant_advanced_exam_selfheal';
    public const APPLICATION_WITHDRAWN = 'application_withdrawn';
    public const AUTO_CLOSE_SESSION = 'auto_close_session';
    public const AUTO_EXPIRE_ACCEPTED = 'auto_expire_accepted';
    public const AUTO_RELEASE_RESULTS = 'auto_release_results';
    public const AUTO_RESCHEDULE_NOSHOW = 'auto_reschedule_noshow';
    public const BATCH_EXAM_SLOTS = 'batch_exam_slots';
    public const BATCH_INTERVIEW_SESSIONS = 'batch_interview_sessions';
    public const BULK_APPROVE_DOCS = 'bulk_approve_docs';
    public const BULK_APPROVE_SELECTED = 'bulk_approve_selected';
    public const COURSE_ADDED = 'course_added';
    public const COURSE_CAPS_UPDATED = 'course_caps_updated';
    public const COURSE_DELETED = 'course_deleted';
    public const COURSE_UPDATED = 'course_updated';
    public const DOC_AI_PASSED = 'doc_ai_passed';
    public const DOC_CATEGORY_OK = 'doc_category_ok';
    public const DOC_FLAGS_CHANGED = 'doc_flags_changed';
    public const DOC_REMINDERS_SENT = 'doc_reminders_sent';
    public const DOC_TYPE_CHANGED = 'doc_type_changed';
    public const DOCUMENT_APPROVED = 'document_approved';
    public const DOCUMENT_REJECTED = 'document_rejected';
    public const DOCUMENT_RESUBMISSION_REQUESTED = 'document_resubmission_requested';
    public const DOCUMENT_UNAPPROVED = 'document_unapproved';
    public const DOCUMENTS_APPROVED_ALL = 'documents_approved_all';
    public const EMAIL_VERIFIED = 'email_verified';
    public const EMAIL_VERIFIED_IDEMPOTENT = 'email_verified_idempotent';
    public const ENROLLMENT_CONFIRMED = 'enrollment_confirmed';
    public const EXAM_ACTIVATED = 'exam_activated';
    public const EXAM_CONFIG_UPDATED = 'exam_config_updated';
    public const EXAM_CREATED = 'exam_created';
    public const EXAM_DELETED = 'exam_deleted';
    public const EXAM_RESCHEDULE_REQUEST_APPROVED = 'exam_reschedule_request_approved';
    public const EXAM_RESCHEDULE_REQUEST_DENIED = 'exam_reschedule_request_denied';
    public const EXAM_ROOMS_EXPORT_CSV = 'exam_rooms_export_csv';
    public const EXAM_ROOMS_EXPORT_HTML = 'exam_rooms_export_html';
    public const EXAM_SLOT_ADDED = 'exam_slot_added';
    public const EXAM_SLOT_AUTO_ASSIGNED = 'exam_slot_auto_assigned';
    public const EXAM_SLOT_REASSIGNED_ENDED = 'exam_slot_reassigned_ended';
    public const EXAM_SLOT_CODE_AUTO_GENERATED = 'exam_slot_code_auto_generated';
    public const EXAM_SLOT_CODE_EXTENDED = 'exam_slot_code_extended';
    public const EXAM_SLOT_CODE_GENERATED = 'exam_slot_code_generated';
    public const EXAM_SLOT_DELETED = 'exam_slot_deleted';
    public const EXAM_SLOT_EDITED = 'exam_slot_edited';
    public const EXAM_SLOT_ROOM_RENAMED = 'exam_slot_room_renamed';
    public const EXAM_SLOT_UNASSIGNED = 'exam_slot_unassigned';
    public const EXAM_UPDATED = 'exam_updated';
    public const INTERVIEW_COMPLETED = 'interview_completed';
    public const INTERVIEW_COMPLETED_WITH_EVAL = 'interview_completed_with_eval';
    public const INTERVIEW_NO_SHOW = 'interview_no_show';
    public const INTERVIEW_NOTES_SAVED = 'interview_notes_saved';
    public const INTERVIEW_REASSIGNED = 'interview_reassigned';
    public const INTERVIEW_SLOT_CLOSED = 'interview_slot_closed';
    public const INTERVIEW_SLOT_CREATED = 'interview_slot_created';
    public const INTERVIEW_SLOT_DELETED = 'interview_slot_deleted';
    public const INTERVIEW_SLOT_EDITED = 'interview_slot_edited';
    public const INTERVIEW_SLOT_REOPENED = 'interview_slot_reopened';
    public const INTERVIEW_STARTED = 'interview_started';
    public const LEGACY_DATA_IMPORTED = 'legacy_data_imported';
    public const LOGIN = 'login';
    public const LOGIN_FAILED = 'login_failed';
    public const LOGIN_GOOGLE = 'login_google';
    public const LOGOUT = 'logout';
    public const NEW_CYCLE_STARTED = 'new_cycle_started';
    public const PASSING_SCORES_UPDATED = 'passing_scores_updated';
    public const PASSWORD_RESET_DONE = 'password_reset_done';
    public const PASSWORD_RESET_REQUESTED = 'password_reset_requested';
    public const RESCHEDULE_REQUEST_APPROVED = 'reschedule_request_approved';
    public const RESCHEDULE_REQUEST_DENIED = 'reschedule_request_denied';
    public const SCHOOL_YEAR_CHANGED = 'school_year_changed';
    public const SETTINGS_BRANDING_UPDATED = 'settings_branding_updated';
    public const TYPE_CHANGED = 'type_changed';
    public const UNDO_APPROVE_ALL = 'undo_approve_all';
    public const USER_CREATED = 'user_created';
    public const USER_DELETED = 'user_deleted';
    public const USER_DEPARTMENT_ASSIGNED = 'user_department_assigned';
    public const USER_PASSWORD_RESET = 'user_password_reset';
    public const USER_STATUS_CHANGED = 'user_status_changed';
    public const VERIFY_RESENT = 'verify_resent';
}
