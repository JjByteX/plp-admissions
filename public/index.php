<?php
// ============================================================
// public/index.php
// Single entry point — all requests route through here
// Apache: mod_rewrite → index.php (see .htaccess)
// ============================================================

require_once dirname(__DIR__) . '/core/bootstrap.php';

$router = new Router();

// -- Auth --------------------------------------------------------
$router->get( '/login',           'auth/login');
$router->post('/login',           'auth/login');
$router->get( '/register',        'auth/register');
$router->post('/register',        'auth/register');
$router->get( '/logout',          'auth/logout');
$router->get( '/forgot-password', 'auth/forgot_password');
$router->post('/forgot-password', 'auth/forgot_password');
$router->get( '/reset-password',  'auth/reset_password');
$router->post('/reset-password',  'auth/reset_password');

// -- Student -----------------------------------------------------
$router->get('/student/documents',  'documents/student_upload');
$router->post('/student/documents', 'documents/student_upload');
$router->get('/student/exam',       'exam/take');
$router->post('/student/exam',      'exam/take');
$router->get('/student/interview',  'interview/student_view');
$router->post('/student/interview', 'interview/student_view');
$router->get('/student/result',     'results/student_view');
$router->get('/student/settings',   'settings/student');
$router->post('/student/settings',  'settings/student');

// -- Staff -------------------------------------------------------
$router->get( '/staff/dashboard',           'auth/staff/dashboard');
$router->get( '/staff/applicants',          'documents/staff_review');
$router->get( '/staff/applicants/{id}',     'documents/staff_review');
$router->post('/staff/documents/{id}',      'documents/staff_action');
$router->get( '/staff/interviews',          'interview/staff_manage');
$router->post('/staff/interviews',          'interview/staff_manage');
$router->post('/staff/interviews/{id}',     'interview/staff_action');
$router->get( '/staff/results',             'results/staff_manage');
$router->post('/staff/results/{id}',        'results/staff_action');
$router->get( '/staff/exam',                'exam/staff_manage');
$router->post('/staff/exam',                'exam/staff_manage');
$router->get( '/staff/settings',            'settings/staff');
$router->post('/staff/settings',            'settings/staff');

// -- Admin -------------------------------------------------------
$router->get( '/admin/dashboard',  'auth/admin/dashboard');
$router->get( '/admin/users',      'settings/admin_users');
$router->post('/admin/users',      'settings/admin_users');
$router->get( '/admin/school-year','settings/admin_school_year');
$router->post('/admin/school-year','settings/admin_school_year');
$router->get( '/admin/settings',   'settings/admin');
$router->post('/admin/settings',   'settings/admin');
$router->get( '/admin/results',    'results/admin_export');

// -- Root redirect -----------------------------------------------
$router->get('/', 'auth/login');

$router->dispatch();