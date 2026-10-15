<?php
// ============================================================
// modules/legal/privacy.php
// Public privacy policy (RA 10173, Data Privacy Act of 2012).
// No login needed. Uses the auth layout.
// DRAFT: the school and its DPO must review this. Not legal advice.
// Anything still unconfirmed is a visible TO CONFIRM placeholder.
// ============================================================

require_once CORE_PATH . '/bootstrap.php';

$schoolName = school_setting('school_name', 'Pamantasan ng Lungsod ng Pasig');
$todo = fn(string $what): string => '<span class="privacy-todo">[TO CONFIRM: ' . e($what) . ']</span>';

ob_start();
?>
<div class="auth-card auth-card-read animate-fade-in">

    <button class="auth-theme-toggle" onclick="Theme.toggle()" aria-label="Toggle theme">
        <?= icon('ic_fluent_weather_sunny_24_regular', 16, '', 'data-theme-icon="dark" class="hidden"') ?>
        <?= icon('ic_fluent_weather_moon_24_regular', 16, '', 'data-theme-icon="light"') ?>
    </button>

    <div class="auth-header">
        <h1 class="auth-title">Privacy Policy</h1>
        <p class="auth-subtitle">How PLP Admissions handles your personal data.</p>
    </div>

    <div class="privacy-body">
        <p class="privacy-meta">Effective date: <?= $todo('effective date, set when the school approves this text') ?></p>

        <h2>1. Who we are</h2>
        <p>PLP Admissions is the online admissions system of <?= e($schoolName) ?>. We process your personal data under the Data Privacy Act of 2012 (Republic Act No. 10173).</p>

        <h2>2. Contact</h2>
        <p>Questions, requests and complaints about your data go to Jj Bassig at <a href="mailto:jjbassig30@gmail.com">jjbassig30@gmail.com</a>.</p>

        <h2>3. What we collect</h2>
        <ul>
            <li>Your registration profile: name, birthdate, sex, phone number, address, email, course, applicant type and senior high school strand.</li>
            <li>The documents you upload, such as your birth certificate, valid ID, grade records and photo.</li>
            <li>Your entrance exam answers and scores, and your interview schedule and result.</li>
            <li>If you use Google sign in: your Google name and email address. We do not receive your Google password.</li>
            <li>Sign in records, such as login attempts and audit entries for actions on your account.</li>
        </ul>

        <h2>4. Why we collect it</h2>
        <p>Only to process your admission: to verify who you are, check your requirements, schedule your exam and interview, tell you your result, and keep your account secure. We do not sell your data and we do not use it for advertising.</p>

        <h2>5. AI document sorting</h2>
        <p>When you upload a document, an image of it is sent to an AI model that PLP runs itself. It is not sent to a third party AI service. The model only helps sort the file into the right requirement slot and flags possible problems for staff to look at. It never approves or rejects a document or an application. People make those decisions.</p>

        <h2>6. Where your data is stored</h2>
        <p>Your data is stored with Supabase (database and file storage) and the app runs on Vercel. Supabase region: <?= $todo('Supabase project region') ?>.</p>

        <h2>7. Who can see it</h2>
        <p>Only authorized PLP personnel who need it for admissions can see your data. Who exactly can open uploaded documents: <?= $todo('roles that can view documents, for example admissions staff, SSO, dean') ?>. Our service providers (Supabase, Vercel, Cloudflare and, if you use it, Google) handle data only to run the service. We share your data with others only when the law requires it.</p>

        <h2>8. How long we keep it</h2>
        <p>Retention period: <?= $todo('how long applicant records and documents are kept, and what happens to unsuccessful applicants') ?>. After that period your data is deleted or anonymized.</p>

        <h2>9. Cookies</h2>
        <ul>
            <li><strong>Session cookie.</strong> Keeps you signed in while you use the site. Required.</li>
            <li><strong>Remember me cookie.</strong> Set only if you tick Remember me. Keeps you signed in on this device for up to 30 days. Signing out removes it.</li>
            <li><strong>Cloudflare Turnstile.</strong> A security check on sign in and registration that tells people from bots. Cloudflare may set its own cookie or token for this.</li>
            <li><strong>Google sign in.</strong> If you choose Continue with Google, Google sets its own cookies on its own pages. We set none for it beyond our session cookie.</li>
        </ul>

        <h2>10. Your rights</h2>
        <p>Under the Data Privacy Act you have the right to:</p>
        <ul>
            <li>be informed about how your data is processed and ask for a copy of it (access);</li>
            <li>correct data that is wrong (rectification);</li>
            <li>ask us to erase or block your data (erasure);</li>
            <li>object to processing, or withdraw your consent;</li>
            <li>claim compensation for damage caused by unlawful use of your data.</li>
        </ul>
        <p>To use any of these, email the contact in section 2. If you are not satisfied, you may file a complaint with the National Privacy Commission (<a href="https://privacy.gov.ph" target="_blank" rel="noopener noreferrer">privacy.gov.ph</a>).</p>

        <h2>11. Changes to this policy</h2>
        <p>If we change this policy we will post the new version on this page and update the effective date.</p>
    </div>

    <div class="auth-footer">
        <a href="<?= url('/login') ?>"><?= icon('ic_fluent_arrow_left_24_regular', 14, 'display:inline-block;vertical-align:-2px') ?> Back to sign in</a>
    </div>

</div>
<?php
$content        = ob_get_clean();
$pageTitle      = 'Privacy Policy';
$hidePrivacyLink = true;
include VIEWS_PATH . '/layouts/auth.php';
