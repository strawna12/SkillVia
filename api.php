<?php
/**
 * SkillVia AI — api.php
 * Single-file API for all SkillVia pages.
 * Place in the same directory as your HTML files.
 *
 * Supports two calling conventions:
 *   REST paths:  POST /api/supervisor/login       (used by supervisor.html, staff.html, signup.html)
 *   Query param: GET/POST api.php?action=xxx       (used by education_portal.html)
 */

// ── CONFIG — reads from Railway environment variables ─────────────────────────
define('DB_HOST',            getenv('MYSQLHOST')     ?: getenv('DB_HOST')     ?: 'localhost');
define('DB_NAME',            getenv('MYSQLDATABASE') ?: getenv('DB_NAME')     ?: 'skillvia');
define('DB_USER',            getenv('MYSQLUSER')     ?: getenv('DB_USER')     ?: 'skillvia_user');
define('DB_PASS',            getenv('MYSQLPASSWORD') ?: getenv('DB_PASS')     ?: '');
define('DB_PORT',            getenv('MYSQLPORT')     ?: getenv('DB_PORT')     ?: '3306');
define('JWT_SECRET',         getenv('JWT_SECRET')    ?: 'CHANGE_ME_JWT_SECRET_MIN_32_CHARS!!');
define('ADMIN_PIN_FALLBACK', getenv('ADMIN_PIN')     ?: '1234');

// ── CORS / HEADERS ────────────────────────────────────────────────────────────
header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, X-Session-Token');
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') { http_response_code(200); exit; }

// ── DB ────────────────────────────────────────────────────────────────────────
try {
    $pdo = new PDO(
        'mysql:host='.DB_HOST.';port='.DB_PORT.';dbname='.DB_NAME.';charset=utf8mb4',
        DB_USER, DB_PASS,
        [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
         PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]
    );
} catch (PDOException $e) {
    fail('Database connection failed: '.$e->getMessage(), 500);
}

init_tables($pdo);

// ── ROUTE RESOLVER ────────────────────────────────────────────────────────────
// Maps REST-style paths (used by supervisor.html, staff.html, signup.html)
// to the same action names used by education_portal.html (?action=xxx)
$method = $_SERVER['REQUEST_METHOD'];
$uri    = parse_url($_SERVER['REQUEST_URI'] ?? '', PHP_URL_PATH);

$path_to_action = [
    '/api/supervisor/login'   => 'supervisor_login',
    '/api/staff/check-phone'  => 'staff_check_phone',
    '/api/staff/login'        => 'staff_login',
    '/api/staff/create-pin'   => 'staff_create_pin',
    '/api/facility/signup'    => 'facility_signup',
];

// Derive action - path takes priority over ?action= param
$action = $path_to_action[$uri] ?? ($_GET['action'] ?? $_POST['action'] ?? '');

// Parse POST body
$body = [];
if ($method === 'POST') {
    $raw  = file_get_contents('php://input');
    $body = json_decode($raw, true) ?? [];
}
$p = array_merge($_GET, $body);   // merged params

// ── DISPATCH ──────────────────────────────────────────────────────────────────
try {
    switch ($action) {

        // ════════════════════════════════════════════════════════
        //  AUTH - supervisor.html
        // ════════════════════════════════════════════════════════

        case 'supervisor_login':
        case 'facility_login':   // alias used by portal's own verify_pin flow
            need_post($method);
            $email    = trim($p['email'] ?? '');
            $password = $p['password'] ?? '';
            if (!$email || !$password) fail('Email and password required.');

            $sup = row($pdo,
                'SELECT s.*, f.status AS fac_status
                 FROM supervisors s
                 JOIN facilities f ON f.id = s.facility_id
                 WHERE s.email = ?', [$email]);

            if (!$sup || !password_verify($password, $sup['password_hash']))
                fail('Invalid email or password.');

            if ($sup['fac_status'] !== 'approved')
                fail('Your facility is pending approval. Please contact aysha@skillviaai.com.');

            $token = make_jwt([
                'supervisor_id' => (int)$sup['id'],
                'facility_id'   => (int)$sup['facility_id'],
                'role'          => 'supervisor',
            ]);

            ok([
                'success'     => true,
                'token'       => $token,
                'userName'    => trim($sup['first_name'].' '.$sup['last_name']),
                'facilityId'  => (int)$sup['facility_id'],
                'redirectUrl' => 'education_portal.html',
            ]);

        // ════════════════════════════════════════════════════════
        //  AUTH - staff.html  (3-step: check -> login or create-pin)
        // ════════════════════════════════════════════════════════

        case 'staff_check_phone':
            need_post($method);
            $phone  = clean_phone($p['phone'] ?? '');
            $fac_id = intval($p['facility_id'] ?? 0);
            if (!$phone) fail('Phone number required.');

            $sql  = $fac_id
                ? 'SELECT id, pin_hash FROM staff WHERE phone = ? AND facility_id = ?'
                : 'SELECT id, pin_hash FROM staff WHERE phone = ?';
            $args = $fac_id ? [$phone, $fac_id] : [$phone];
            $staff = row($pdo, $sql, $args);

            ok([
                'success' => true,
                'exists'  => (bool)$staff,
                'hasPin'  => $staff && !empty($staff['pin_hash']),
            ]);

        case 'staff_login':
            need_post($method);
            $phone = clean_phone($p['phone'] ?? '');
            $pin   = $p['pin'] ?? '';
            if (!$phone || !$pin) fail('Phone and PIN required.');

            $staff = row($pdo,
                'SELECT s.* FROM staff s
                 JOIN facilities f ON f.id = s.facility_id
                 WHERE s.phone = ?', [$phone]);

            if (!$staff)                   fail('Staff member not found.');
            if (empty($staff['pin_hash'])) fail('No PIN set. Please create your PIN first.');
            if (!password_verify($pin, $staff['pin_hash'])) fail('Incorrect PIN.');

            $token = make_jwt([
                'staff_id'    => (int)$staff['id'],
                'facility_id' => (int)$staff['facility_id'],
                'role'        => 'staff',
            ]);

            ok([
                'success'   => true,
                'token'     => $token,
                'staffName' => $staff['full_name'],
                'staffId'   => (int)$staff['id'],
            ]);

        case 'staff_create_pin':
            need_post($method);
            $phone = clean_phone($p['phone'] ?? '');
            $pin   = $p['pin'] ?? '';
            if (!$phone || !$pin) fail('Phone and PIN required.');
            if (strlen($pin) < 4 || strlen($pin) > 6) fail('PIN must be 4-6 digits.');

            $staff = row($pdo, 'SELECT * FROM staff WHERE phone = ?', [$phone]);
            if (!$staff) fail('Phone number not found. Ask your supervisor to add you first.');

            $pdo->prepare('UPDATE staff SET pin_hash = ?, has_pin = 1 WHERE id = ?')
                ->execute([password_hash($pin, PASSWORD_DEFAULT), $staff['id']]);

            $token = make_jwt([
                'staff_id'    => (int)$staff['id'],
                'facility_id' => (int)$staff['facility_id'],
                'role'        => 'staff',
            ]);

            ok([
                'success'   => true,
                'token'     => $token,
                'staffName' => $staff['full_name'],
                'staffId'   => (int)$staff['id'],
            ]);

        // ════════════════════════════════════════════════════════
        //  FACILITY SIGNUP - signup.html
        // ════════════════════════════════════════════════════════

        case 'facility_signup':
            need_post($method);

            $fac    = $p['facility']   ?? [];
            $acc    = $p['account']    ?? [];
            $lic    = $p['licensing']  ?? [];
            $lead   = $p['leadership'] ?? [];
            $roster = $p['staff']      ?? [];

            $admin = $lead['administrator'] ?? [];
            $don   = $lead['don']           ?? [];

            $name       = trim($fac['name']     ?? '');
            $acct_email = trim($acc['email']    ?? '');
            $acct_pass  = $acc['password']      ?? '';

            if (!$name)       fail('Facility name is required.');
            if (!$acct_email) fail('Account email is required.');
            if (!$acct_pass)  fail('Account password is required.');
            if (!filter_var($acct_email, FILTER_VALIDATE_EMAIL)) fail('Invalid email address.');
            if (strlen($acct_pass) < 8) fail('Password must be at least 8 characters.');

            $dup = row($pdo, 'SELECT id FROM supervisors WHERE email = ?', [$acct_email]);
            if ($dup) fail('An account with this email already exists.');

            $pdo->beginTransaction();
            try {
                $pdo->prepare(
                    'INSERT INTO facilities
                     (name, address, city, state, zip, phone, bed_count,
                      license_num, cms_provider_num, facility_type, status)
                     VALUES (?,?,?,?,?,?,?,?,?,?,?)'
                )->execute([
                    $name,
                    trim($fac['address']       ?? ''),
                    trim($fac['city']          ?? ''),
                    trim($fac['state']         ?? 'FL'),
                    trim($fac['zip']           ?? ''),
                    trim($fac['phone']         ?? ''),
                    intval($fac['bedCount']    ?? 0),
                    trim($lic['licenseNumber'] ?? ''),
                    trim($lic['cmsNumber']     ?? ''),
                    trim($lic['facilityType']  ?? 'SNF'),
                    'pending',
                ]);
                $fac_id = $pdo->lastInsertId();

                $pdo->prepare(
                    'INSERT INTO supervisors
                     (facility_id, first_name, last_name, email, password_hash, role)
                     VALUES (?,?,?,?,?,?)'
                )->execute([
                    $fac_id,
                    trim($admin['firstName'] ?? '') ?: 'Administrator',
                    trim($admin['lastName']  ?? ''),
                    $acct_email,
                    password_hash($acct_pass, PASSWORD_DEFAULT),
                    'administrator',
                ]);

                $don_email = trim($don['email'] ?? '');
                if ($don_email && $don_email !== $acct_email) {
                    $pdo->prepare(
                        'INSERT INTO supervisors
                         (facility_id, first_name, last_name, email, password_hash, role)
                         VALUES (?,?,?,?,?,?)'
                    )->execute([
                        $fac_id,
                        trim($don['firstName'] ?? 'Director'),
                        trim($don['lastName']  ?? 'of Nursing'),
                        $don_email,
                        password_hash(bin2hex(random_bytes(16)), PASSWORD_DEFAULT),
                        'don',
                    ]);
                }

                if (is_array($roster)) {
                    $stmt = $pdo->prepare(
                        'INSERT IGNORE INTO staff (facility_id, full_name, phone, role) VALUES (?,?,?,?)'
                    );
                    foreach ($roster as $s) {
                        $sname = trim($s['name'] ?? '');
                        if ($sname) {
                            $stmt->execute([
                                $fac_id,
                                $sname,
                                clean_phone($s['phone'] ?? ''),
                                trim($s['role'] ?? 'CNA'),
                            ]);
                        }
                    }
                }

                $pdo->commit();
                ok(['success' => true, 'facility_id' => (int)$fac_id]);

            } catch (Exception $e) {
                $pdo->rollBack();
                fail('Registration failed: '.$e->getMessage(), 500);
            }

        // ════════════════════════════════════════════════════════
        //  EDUCATION PORTAL - setup & facility
        // ════════════════════════════════════════════════════════

        case 'setup':
            need_post($method);
            $fac_id   = get_fac_from_token() ?? 1;
            $name     = trim($p['name']      ?? 'Skilled Nursing Facility');
            $don_name = trim($p['don_name']  ?? 'Director of Nursing');
            $pin      = trim($p['admin_pin'] ?? ADMIN_PIN_FALLBACK);

            $exists = row($pdo, 'SELECT id FROM facilities WHERE id = ?', [$fac_id]);
            if (!$exists) {
                $pdo->prepare(
                    'INSERT INTO facilities (id, name, don_name, admin_pin_hash, status)
                     VALUES (?,?,?,?,?)'
                )->execute([$fac_id, $name, $don_name,
                             password_hash($pin, PASSWORD_DEFAULT), 'approved']);
            }
            ok(['ok' => true]);

        case 'get_facility':
            $fac_id = intval($p['id'] ?? get_fac_from_token() ?? 1);
            $fac = row($pdo,
                'SELECT id, name, don_name, sms_enabled, twilio_sid, twilio_from
                 FROM facilities WHERE id = ?', [$fac_id]);
            if (!$fac) fail('Facility not found.', 404);
            ok($fac);

        case 'update_facility':
            need_post($method);
            $fac_id = intval($p['id'] ?? 1);
            $pdo->prepare(
                'UPDATE facilities
                 SET name=?, don_name=?, twilio_sid=?, twilio_token=?, twilio_from=?, sms_enabled=?
                 WHERE id=?'
            )->execute([
                trim($p['name']          ?? ''),
                trim($p['don_name']      ?? ''),
                trim($p['twilio_sid']    ?? ''),
                trim($p['twilio_token']  ?? ''),
                trim($p['twilio_from']   ?? ''),
                !empty($p['sms_enabled']) ? 1 : 0,
                $fac_id,
            ]);
            ok(['ok' => true]);

        // ════════════════════════════════════════════════════════
        //  STAFF MANAGEMENT
        // ════════════════════════════════════════════════════════

        case 'get_staff':
            $fac_id = intval($p['facility_id'] ?? 1);
            $result = rows($pdo,
                'SELECT id, full_name AS name, role, phone, has_pin, sms_opt_in
                 FROM staff WHERE facility_id = ? ORDER BY full_name', [$fac_id]);

            foreach ($result as &$row) {
                $row['smsOptIn']    = (bool)$row['sms_opt_in'];
                $row['completions'] = rows($pdo,
                    'SELECT topic_id, quiz_score, completed_at, supervisor_note, approved_at
                     FROM completions WHERE staff_id = ? ORDER BY completed_at DESC',
                    [$row['id']]);
                $row['signatures']  = rows($pdo,
                    'SELECT inservice_id, signed_at FROM inservice_signatures WHERE staff_id = ?',
                    [$row['id']]);
            }
            unset($row);
            ok($result);

        case 'add_staff':
            need_post($method);
            $fac_id  = intval($p['facility_id'] ?? 1);
            $name    = trim($p['full_name']     ?? '');
            $role    = trim($p['role']          ?? 'CNA');
            $phone   = clean_phone($p['phone']  ?? '');
            $sms_opt = !empty($p['sms_opt_in']) ? 1 : 0;
            if (!$name) fail('Staff name required.');

            if ($phone) {
                $dup = row($pdo,
                    'SELECT id FROM staff WHERE phone = ? AND facility_id = ?',
                    [$phone, $fac_id]);
                if ($dup) fail('A staff member with this phone number already exists.');
            }
            $pdo->prepare(
                'INSERT INTO staff (facility_id, full_name, role, phone, sms_opt_in) VALUES (?,?,?,?,?)'
            )->execute([$fac_id, $name, $role, $phone, $sms_opt]);

            ok([
                'id'          => (int)$pdo->lastInsertId(),
                'name'        => $name,
                'role'        => $role,
                'phone'       => $phone,
                'smsOptIn'    => (bool)$sms_opt,
                'completions' => [],
                'signatures'  => [],
            ]);

        case 'remove_staff':
            need_post($method);
            $staff_id = intval($p['staff_id'] ?? 0);
            if (!$staff_id) fail('Staff ID required.');
            $pdo->prepare('DELETE FROM staff WHERE id = ?')->execute([$staff_id]);
            ok(['ok' => true]);

        case 'update_sms':
            need_post($method);
            $pdo->prepare('UPDATE staff SET sms_opt_in = ? WHERE id = ?')
                ->execute([!empty($p['sms_opt_in']) ? 1 : 0, intval($p['staff_id'] ?? 0)]);
            ok(['ok' => true]);

        // ════════════════════════════════════════════════════════
        //  SUPERVISOR PIN
        // ════════════════════════════════════════════════════════

        case 'verify_pin':
            need_post($method);
            $fac_id = intval($p['facility_id'] ?? 1);
            $pin    = $p['pin'] ?? '';
            $fac    = row($pdo, 'SELECT admin_pin_hash FROM facilities WHERE id = ?', [$fac_id]);
            if (!$fac) fail('Facility not found.', 404);

            $valid = $fac['admin_pin_hash']
                ? password_verify($pin, $fac['admin_pin_hash'])
                : ($pin === ADMIN_PIN_FALLBACK);

            if (!$valid) fail('Incorrect PIN.');
            ok(['ok' => true]);

        // ════════════════════════════════════════════════════════
        //  STAFF PORTAL AUTH (inside education portal)
        // ════════════════════════════════════════════════════════

        case 'staff_phone_lookup':
            need_post($method);
            $phone  = clean_phone($p['phone']       ?? '');
            $fac_id = intval($p['facility_id'] ?? 1);
            $staff  = row($pdo,
                'SELECT id, full_name AS name, role, has_pin
                 FROM staff WHERE phone = ? AND facility_id = ?',
                [$phone, $fac_id]);
            if (!$staff) fail('Phone number not found. Ask your supervisor to add you.');
            ok(['found' => true, 'staff' => $staff]);

        case 'staff_set_pin':
            need_post($method);
            $staff_id = intval($p['staff_id'] ?? 0);
            $pin      = $p['pin'] ?? '';
            if (!$staff_id || !$pin) fail('Staff ID and PIN required.');
            if (strlen($pin) < 4 || strlen($pin) > 6) fail('PIN must be 4-6 digits.');
            $pdo->prepare('UPDATE staff SET pin_hash = ?, has_pin = 1 WHERE id = ?')
                ->execute([password_hash($pin, PASSWORD_DEFAULT), $staff_id]);
            ok(['ok' => true]);

        case 'staff_verify_pin':
            need_post($method);
            $staff_id = intval($p['staff_id']       ?? 0);
            $pin      = $p['pin']                   ?? '';
            $fac_id   = intval($p['facility_id'] ?? 1);

            $staff = row($pdo,
                'SELECT id, full_name AS name, pin_hash
                 FROM staff WHERE id = ? AND facility_id = ?',
                [$staff_id, $fac_id]);
            if (!$staff) fail('Staff not found.', 404);
            if (!password_verify($pin, $staff['pin_hash'] ?? '')) fail('Incorrect PIN.');

            $token = make_jwt([
                'staff_id'    => (int)$staff['id'],
                'facility_id' => $fac_id,
                'role'        => 'staff',
            ]);
            ok(['ok' => true, 'token' => $token, 'name' => $staff['name']]);

        case 'staff_load_data':
            $payload  = require_auth(['staff']);
            $staff_id = intval($payload['staff_id']    ?? 0);
            $fac_id   = intval($payload['facility_id'] ?? 1);

            $staff = row($pdo,
                'SELECT id, full_name AS name, role, phone
                 FROM staff WHERE id = ? AND facility_id = ?',
                [$staff_id, $fac_id]);
            if (!$staff) fail('Staff record not found.', 404);

            ok([
                'staff'             => $staff,
                'completions'       => rows($pdo,
                    'SELECT topic_id, quiz_score, questions_correct, questions_total,
                            completed_at, supervisor_note, approved_at
                     FROM completions WHERE staff_id = ?', [$staff_id]),
                'signatures'        => rows($pdo,
                    'SELECT inservice_id, signed_at
                     FROM inservice_signatures WHERE staff_id = ?', [$staff_id]),
                'manual_inservices' => rows($pdo,
                    'SELECT i.*, s.signed_at
                     FROM manual_inservices i
                     LEFT JOIN inservice_signatures s
                       ON s.inservice_id = i.id AND s.staff_id = ?
                     WHERE i.facility_id = ?
                     ORDER BY i.created_at DESC',
                    [$staff_id, $fac_id]),
            ]);

        // ════════════════════════════════════════════════════════
        //  COMPLETIONS
        // ════════════════════════════════════════════════════════

        case 'save_completion':
            need_post($method);
            $staff_id  = intval($p['staff_id']         ?? 0);
            $fac_id    = intval($p['facility_id']   ?? 1);
            $topic_id  = trim($p['topic_id']           ?? '');
            $score     = intval($p['quiz_score']        ?? 0);
            $q_correct = intval($p['questions_correct'] ?? 0);
            $q_total   = intval($p['questions_total']   ?? 0);

            $existing = row($pdo,
                'SELECT id FROM completions WHERE staff_id = ? AND topic_id = ?',
                [$staff_id, $topic_id]);

            if ($existing) {
                $pdo->prepare(
                    'UPDATE completions
                     SET quiz_score=?, questions_correct=?, questions_total=?, completed_at=NOW()
                     WHERE id=?'
                )->execute([$score, $q_correct, $q_total, $existing['id']]);
                $comp_id = $existing['id'];
            } else {
                $pdo->prepare(
                    'INSERT INTO completions
                     (staff_id, facility_id, topic_id, quiz_score,
                      questions_correct, questions_total, completed_at)
                     VALUES (?,?,?,?,?,?,NOW())'
                )->execute([$staff_id, $fac_id, $topic_id, $score, $q_correct, $q_total]);
                $comp_id = $pdo->lastInsertId();
            }
            ok(['ok' => true, 'completion_id' => (int)$comp_id]);

        case 'approve_signoff':
            need_post($method);
            $staff_id = intval($p['staff_id'] ?? 0);
            $topic_id = trim($p['topic_id']   ?? '');
            $note     = trim($p['note']       ?? 'Supervisor reviewed and approved.');
            $pdo->prepare(
                'UPDATE completions SET supervisor_note=?, approved_at=NOW()
                 WHERE staff_id=? AND topic_id=?'
            )->execute([$note, $staff_id, $topic_id]);
            ok(['ok' => true]);

        // ════════════════════════════════════════════════════════
        //  POLICIES
        // ════════════════════════════════════════════════════════

        case 'get_policies':
            $fac_id  = intval($p['facility_id'] ?? 1);
            $db_rows = rows($pdo,
                'SELECT topic_id, title, effective_date, review_date, approved_by,
                        purpose, scope, procedure_text, references_text
                 FROM policies WHERE facility_id = ?', [$fac_id]);

            $result = [];
            foreach ($db_rows as $r) {
                $result[$r['topic_id']] = [
                    'title'         => $r['title'],
                    'effectiveDate' => $r['effective_date'],
                    'reviewDate'    => $r['review_date'],
                    'approvedBy'    => $r['approved_by'],
                    'sections'      => [
                        'purpose'    => $r['purpose'],
                        'scope'      => $r['scope'],
                        'procedure'  => $r['procedure_text'],
                        'references' => $r['references_text'],
                    ],
                ];
            }
            ok($result);

        case 'save_policy':
            need_post($method);
            $fac_id   = intval($p['facility_id'] ?? 1);
            $topic_id = trim($p['topic_id']      ?? '');
            $sections = $p['sections']           ?? [];

            $vals = [
                trim($p['title']          ?? ''),
                trim($p['effective_date'] ?? ''),
                trim($p['review_date']    ?? ''),
                trim($p['approved_by']    ?? ''),
                trim($sections['purpose']    ?? ''),
                trim($sections['scope']      ?? ''),
                trim($sections['procedure']  ?? ''),
                trim($sections['references'] ?? ''),
            ];

            $existing = row($pdo,
                'SELECT id FROM policies WHERE facility_id = ? AND topic_id = ?',
                [$fac_id, $topic_id]);

            if ($existing) {
                $pdo->prepare(
                    'UPDATE policies
                     SET title=?, effective_date=?, review_date=?, approved_by=?,
                         purpose=?, scope=?, procedure_text=?, references_text=?, updated_at=NOW()
                     WHERE id=?'
                )->execute(array_merge($vals, [$existing['id']]));
            } else {
                $pdo->prepare(
                    'INSERT INTO policies
                     (facility_id, topic_id, title, effective_date, review_date, approved_by,
                      purpose, scope, procedure_text, references_text)
                     VALUES (?,?,?,?,?,?,?,?,?,?)'
                )->execute(array_merge([$fac_id, $topic_id], $vals));
            }
            ok(['ok' => true]);

        // ════════════════════════════════════════════════════════
        //  MANUAL INSERVICES
        // ════════════════════════════════════════════════════════

        case 'get_manual_inservices':
            $fac_id = intval($p['facility_id'] ?? 1);
            $result = rows($pdo,
                'SELECT * FROM manual_inservices WHERE facility_id = ? ORDER BY created_at DESC',
                [$fac_id]);

            foreach ($result as &$ins) {
                $ins['signatures'] = rows($pdo,
                    'SELECT s.staff_id, st.full_name, s.signed_at
                     FROM inservice_signatures s
                     JOIN staff st ON st.id = s.staff_id
                     WHERE s.inservice_id = ?', [$ins['id']]);
            }
            unset($ins);
            ok($result);

        case 'create_manual_inservice':
            need_post($method);
            $fac_id      = intval($p['facility_id'] ?? 1);
            $title       = trim($p['title']         ?? '');
            $content     = trim($p['content']       ?? '');
            $due_date    = $p['due_date']            ?? null;
            $assigned_to = trim($p['assigned_to']   ?? 'all');
            if (!$title) fail('Inservice title required.');

            $pdo->prepare(
                'INSERT INTO manual_inservices
                 (facility_id, title, content, due_date, assigned_to, created_at)
                 VALUES (?,?,?,?,?,NOW())'
            )->execute([$fac_id, $title, $content, ($due_date ?: null), $assigned_to]);
            ok(['ok' => true, 'id' => (int)$pdo->lastInsertId()]);

        case 'sign_inservice':
            need_post($method);
            $ins_id   = intval($p['inservice_id'] ?? 0);
            $staff_id = intval($p['staff_id']     ?? 0);
            if (!$ins_id || !$staff_id) fail('Inservice ID and Staff ID required.');

            $dup = row($pdo,
                'SELECT id FROM inservice_signatures WHERE inservice_id=? AND staff_id=?',
                [$ins_id, $staff_id]);
            if (!$dup) {
                $pdo->prepare(
                    'INSERT INTO inservice_signatures (inservice_id, staff_id, signed_at)
                     VALUES (?,?,NOW())'
                )->execute([$ins_id, $staff_id]);
            }
            ok(['ok' => true]);

        // ════════════════════════════════════════════════════════
        //  NOTIFICATION LOG
        // ════════════════════════════════════════════════════════

        case 'get_notifications':
            $fac_id = intval($p['facility_id'] ?? 1);
            ok(rows($pdo,
                'SELECT * FROM notification_log WHERE facility_id = ?
                 ORDER BY sent_at DESC LIMIT 100', [$fac_id]));

        case 'log_notification':
            $fac_id = intval($p['facility_id'] ?? 1);
            $pdo->prepare(
                'INSERT INTO notification_log
                 (facility_id, notification_type, recipient_filter, custom_message, sent_at)
                 VALUES (?,?,?,?,NOW())'
            )->execute([
                $fac_id,
                trim($p['notification_type'] ?? ''),
                trim($p['recipient_filter']  ?? 'all'),
                trim($p['custom_message']    ?? ''),
            ]);
            ok(['ok' => true]);

        default:
            fail("Unknown action: {$action}", 400);
    }

} catch (Exception $e) {
    fail($e->getMessage(), 500);
}


// ════════════════════════════════════════════════════════════════════════════
//  HELPERS
// ════════════════════════════════════════════════════════════════════════════

function ok(array $data): void {
    echo json_encode(array_merge(['ok' => true], $data));
    exit;
}

function fail(string $msg, int $code = 400): void {
    http_response_code($code);
    echo json_encode([
        'ok'      => false,
        'success' => false,
        'error'   => $msg,
        'message' => $msg,
    ]);
    exit;
}

function need_post(string $method): void {
    if ($method !== 'POST') fail('POST required.', 405);
}

function row(PDO $pdo, string $sql, array $params = []): ?array {
    $s = $pdo->prepare($sql);
    $s->execute($params);
    return $s->fetch() ?: null;
}

function rows(PDO $pdo, string $sql, array $params = []): array {
    $s = $pdo->prepare($sql);
    $s->execute($params);
    return $s->fetchAll();
}

function clean_phone(string $phone): string {
    $d = preg_replace('/\D/', '', $phone);
    if (strlen($d) === 11 && $d[0] === '1') $d = substr($d, 1);
    return $d;
}

function make_jwt(array $payload): string {
    $payload['iat'] = time();
    $payload['exp'] = time() + 43200;
    $payload['jti'] = bin2hex(random_bytes(16));
    $h   = b64u(json_encode(['alg' => 'HS256', 'typ' => 'JWT']));
    $b   = b64u(json_encode($payload));
    $sig = b64u(hash_hmac('sha256', "$h.$b", JWT_SECRET, true));
    return "$h.$b.$sig";
}

function verify_jwt(string $token): ?array {
    $parts = explode('.', $token);
    if (count($parts) !== 3) return null;
    [$h, $b, $sig] = $parts;
    if (!hash_equals(b64u(hash_hmac('sha256', "$h.$b", JWT_SECRET, true)), $sig)) return null;
    $payload = json_decode(b64d($b), true);
    if (!$payload || ($payload['exp'] ?? 0) < time()) return null;
    return $payload;
}

function b64u(string $data): string {
    return rtrim(strtr(base64_encode($data), '+/', '-_'), '=');
}

function b64d(string $data): string {
    return base64_decode(
        strtr($data, '-_', '+/') . str_repeat('=', (4 - strlen($data) % 4) % 4)
    );
}

function get_fac_from_token(): ?int {
    $token = $_SERVER['HTTP_X_SESSION_TOKEN'] ?? '';
    if (!$token) return null;
    $p = verify_jwt($token);
    return $p ? intval($p['facility_id']) : null;
}

function require_auth(array $roles = []): array {
    $token = $_SERVER['HTTP_X_SESSION_TOKEN'] ?? '';
    if (!$token) fail('Authentication required.', 401);
    $payload = verify_jwt($token);
    if (!$payload) fail('Session expired. Please log in again.', 401);
    if ($roles && !in_array($payload['role'] ?? '', $roles, true)) {
        fail('Access denied.', 403);
    }
    return $payload;
}

function init_tables(PDO $pdo): void {
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS facilities (
            id               INT AUTO_INCREMENT PRIMARY KEY,
            name             VARCHAR(255) NOT NULL DEFAULT 'Skilled Nursing Facility',
            don_name         VARCHAR(255) DEFAULT 'Director of Nursing',
            address          VARCHAR(255) DEFAULT '',
            city             VARCHAR(100) DEFAULT '',
            state            CHAR(2)      DEFAULT 'FL',
            zip              VARCHAR(10)  DEFAULT '',
            phone            VARCHAR(20)  DEFAULT '',
            bed_count        SMALLINT     DEFAULT 0,
            license_num      VARCHAR(100) DEFAULT '',
            cms_provider_num VARCHAR(10)  DEFAULT '',
            facility_type    VARCHAR(50)  DEFAULT 'SNF',
            admin_pin_hash   VARCHAR(255) DEFAULT NULL,
            twilio_sid       VARCHAR(50)  DEFAULT '',
            twilio_token     VARCHAR(255) DEFAULT '',
            twilio_from      VARCHAR(20)  DEFAULT '',
            sms_enabled      TINYINT(1)   DEFAULT 0,
            status           ENUM('pending','approved','denied') DEFAULT 'pending',
            created_at       TIMESTAMP    DEFAULT CURRENT_TIMESTAMP
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

        CREATE TABLE IF NOT EXISTS supervisors (
            id            INT AUTO_INCREMENT PRIMARY KEY,
            facility_id   INT          NOT NULL,
            first_name    VARCHAR(100) NOT NULL,
            last_name     VARCHAR(100) DEFAULT '',
            email         VARCHAR(255) NOT NULL UNIQUE,
            password_hash VARCHAR(255) NOT NULL,
            role          VARCHAR(50)  DEFAULT 'supervisor',
            created_at    TIMESTAMP    DEFAULT CURRENT_TIMESTAMP,
            FOREIGN KEY (facility_id) REFERENCES facilities(id) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

        CREATE TABLE IF NOT EXISTS staff (
            id          INT AUTO_INCREMENT PRIMARY KEY,
            facility_id INT          NOT NULL,
            full_name   VARCHAR(255) NOT NULL,
            role        VARCHAR(50)  DEFAULT 'CNA',
            phone       VARCHAR(20)  DEFAULT '',
            pin_hash    VARCHAR(255) DEFAULT NULL,
            has_pin     TINYINT(1)   DEFAULT 0,
            sms_opt_in  TINYINT(1)   DEFAULT 0,
            created_at  TIMESTAMP    DEFAULT CURRENT_TIMESTAMP,
            FOREIGN KEY (facility_id) REFERENCES facilities(id) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

        CREATE TABLE IF NOT EXISTS completions (
            id                INT AUTO_INCREMENT PRIMARY KEY,
            staff_id          INT             NOT NULL,
            facility_id       INT             NOT NULL,
            topic_id          VARCHAR(50)     NOT NULL,
            quiz_score        TINYINT UNSIGNED DEFAULT 0,
            questions_correct TINYINT UNSIGNED DEFAULT 0,
            questions_total   TINYINT UNSIGNED DEFAULT 0,
            completed_at      TIMESTAMP        DEFAULT CURRENT_TIMESTAMP,
            supervisor_note   TEXT             DEFAULT NULL,
            approved_at       TIMESTAMP        NULL DEFAULT NULL,
            UNIQUE KEY uq_staff_topic (staff_id, topic_id),
            FOREIGN KEY (staff_id) REFERENCES staff(id) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

        CREATE TABLE IF NOT EXISTS policies (
            id              INT AUTO_INCREMENT PRIMARY KEY,
            facility_id     INT          NOT NULL,
            topic_id        VARCHAR(50)  NOT NULL,
            title           VARCHAR(255) DEFAULT '',
            effective_date  DATE         DEFAULT NULL,
            review_date     DATE         DEFAULT NULL,
            approved_by     VARCHAR(255) DEFAULT '',
            purpose         TEXT,
            scope           TEXT,
            procedure_text  LONGTEXT,
            references_text TEXT,
            updated_at      TIMESTAMP    DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            UNIQUE KEY uq_fac_topic (facility_id, topic_id),
            FOREIGN KEY (facility_id) REFERENCES facilities(id) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

        CREATE TABLE IF NOT EXISTS manual_inservices (
            id          INT AUTO_INCREMENT PRIMARY KEY,
            facility_id INT          NOT NULL,
            title       VARCHAR(255) NOT NULL,
            content     LONGTEXT,
            due_date    DATE         DEFAULT NULL,
            assigned_to VARCHAR(50)  DEFAULT 'all',
            created_at  TIMESTAMP    DEFAULT CURRENT_TIMESTAMP,
            FOREIGN KEY (facility_id) REFERENCES facilities(id) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

        CREATE TABLE IF NOT EXISTS inservice_signatures (
            id           INT AUTO_INCREMENT PRIMARY KEY,
            inservice_id INT       NOT NULL,
            staff_id     INT       NOT NULL,
            signed_at    TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            UNIQUE KEY uq_sig (inservice_id, staff_id),
            FOREIGN KEY (inservice_id) REFERENCES manual_inservices(id) ON DELETE CASCADE,
            FOREIGN KEY (staff_id)     REFERENCES staff(id)             ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

        CREATE TABLE IF NOT EXISTS notification_log (
            id                INT AUTO_INCREMENT PRIMARY KEY,
            facility_id       INT         NOT NULL,
            notification_type VARCHAR(50) DEFAULT '',
            recipient_filter  VARCHAR(50) DEFAULT 'all',
            custom_message    TEXT,
            sent_at           TIMESTAMP   DEFAULT CURRENT_TIMESTAMP,
            FOREIGN KEY (facility_id) REFERENCES facilities(id) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
    ");
}
