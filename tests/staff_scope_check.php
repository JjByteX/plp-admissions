<?php
// Run: php tests/staff_scope_check.php
// Checks StaffScope: a Dean only sees courses of their own college, and a Dean
// with no courses sees nothing. No database needed.
// ponytail: plain asserts, no framework.
require_once __DIR__ . '/../config/app.php';
require_once __DIR__ . '/../core/helpers.php';
require_once __DIR__ . '/../core/staff_scope.php';

function check(bool $ok, string $msg): void
{
    if (!$ok) { fwrite(STDERR, "FAIL: $msg\n"); exit(1); }
}

$open  = StaffScope::make(false);
$dean  = StaffScope::make(true, ['BS Nursing', 'BS Midwifery'], 'College of Nursing');
$none  = StaffScope::make(true, [], '');

// Unrestricted roles
check(!$open->restricted(), 'unrestricted scope is not restricted');
check($open->canAccessCourse('BS Anything'), 'unrestricted can access any course');
check($open->courseSql('a.course_applied') === ['', []], 'unrestricted adds no SQL');
check($open->filterCourses(['A', 'B']) === ['A', 'B'], 'unrestricted keeps every course');

// Dean with a college
check($dean->restricted(), 'dean is restricted');
check($dean->department() === 'College of Nursing', 'dean department is kept');
check($dean->canAccessCourse('BS Nursing'), 'dean can access own course');
check(!$dean->canAccessCourse('BS Accountancy'), 'dean cannot access another college course');
check($dean->filterCourses(['BS Nursing', 'BS Accountancy']) === ['BS Nursing'], 'dean list is cut to own college');

[$sql, $params] = $dean->courseSql('a.course_applied');
check($sql === 'a.course_applied IN (?,?)' && $params === ['BS Nursing', 'BS Midwifery'], 'dean positional SQL uses placeholders only');

[$sql, $params] = $dean->courseSql('a.course_applied', 'dc');
check($sql === 'a.course_applied IN (:dc0,:dc1)' && $params === [':dc0' => 'BS Nursing', ':dc1' => 'BS Midwifery'], 'dean named SQL uses :dcN placeholders');
check(!str_contains($sql, 'BS Nursing'), 'course names never appear in the SQL text');

$where = ['a.status = ?']; $p = ['active'];
$dean->applyToWhere($where, $p);
check(count($where) === 2 && $p === ['active', 'BS Nursing', 'BS Midwifery'], 'applyToWhere appends the clause and params');

$where = ['a.status = ?']; $p = ['active'];
$open->applyToWhere($where, $p);
check($where === ['a.status = ?'] && $p === ['active'], 'applyToWhere leaves unrestricted queries alone');

// Dean with no department or no courses sees nothing
check($none->courseSql('a.course_applied') === ['1 = 0', []], 'dean with no courses gets 1 = 0');
check(!$none->canAccessCourse('BS Nursing'), 'dean with no courses cannot access any course');
check($none->filterCourses(['BS Nursing']) === [], 'dean with no courses keeps nothing');
$where = []; $p = [];
$none->applyToWhere($where, $p);
check($where === ['1 = 0'] && $p === [], 'applyToWhere adds 1 = 0 for a dean with no courses');

echo "staff_scope_check: all passed\n";
