<?php
// ✨ DATA PRIVACY NOTICE (Data Privacy Act of 2012, RA 10173)
// Linked from the landing page footer and the registration form, where students must agree to it.
require_once 'includes/ossd_contact.php';
$updated = 'October 24, 2026';
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Data Privacy Notice - ScholarLink</title>
    <?php require_once __DIR__ . '/includes/theme.php'; themeHead(); ?>
    <script src="https://cdn.tailwindcss.com"></script>
    <link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@400;500;600;700;800&display=swap" rel="stylesheet">
    <style>
        body { font-family: 'Plus Jakarta Sans', sans-serif; }
        .notice h2 { font-size: 1.05rem; font-weight: 800; margin: 2rem 0 .6rem; }
        .notice p, .notice li { font-size: .92rem; line-height: 1.7; }
        .notice ul { list-style: disc; padding-left: 1.25rem; margin: .4rem 0; }
        .notice li { margin: .2rem 0; }
    </style>
</head>
<body class="theme-standalone bg-slate-50 min-h-screen p-4 md:p-10">
    <div class="max-w-3xl mx-auto">
        <a href="index.php" class="inline-flex items-center gap-2 text-sm font-bold text-slate-500 hover:text-slate-900 mb-6">← Back to ScholarLink</a>

        <article class="notice bg-white border border-slate-200 rounded-[2rem] shadow-sm p-6 md:p-12 text-slate-700">
            <div class="flex items-center gap-4 pb-6 mb-2 border-b border-slate-100">
                <img src="assets/img/tau_logo.png" alt="TAU Logo" class="w-14 h-14">
                <div>
                    <p class="text-[11px] font-black uppercase tracking-widest text-green-700">Tarlac Agricultural University · ScholarLink</p>
                    <h1 class="text-2xl md:text-3xl font-black text-slate-900 tracking-tight">Data Privacy Notice</h1>
                    <p class="text-xs font-bold text-slate-400 mt-1">Last updated <?= $updated ?></p>
                </div>
            </div>

            <p class="mt-6">Tarlac Agricultural University (TAU), through the <?= OSSD_UNIT ?> – Financial and Educational Assistance Services, respects your privacy. This notice explains how ScholarLink, TAU's scholarship portal, collects, uses, shares and protects your personal information in accordance with the <b>Data Privacy Act of 2012 (Republic Act No. 10173)</b>, its Implementing Rules and Regulations, and the issuances of the National Privacy Commission (NPC).</p>

            <h2 class="text-slate-900">1. What we collect</h2>
            <ul>
                <li><b>Identity and contact:</b> name, sex, date of birth, citizenship, email address, mobile number and home address.</li>
                <li><b>Student records:</b> student number, degree program, year level, GWA, units enrolled, and your TAU ID (scanned to read your details and photo).</li>
                <li><b>Government numbers:</b> Learner Reference Number (LRN), PhilSys ID number and 4Ps household ID, when you provide them.</li>
                <li><b>Family background:</b> your parents' names.</li>
                <li><b>Sensitive personal information,</b> only if you choose to declare it: disability, indigenous group membership, being a child of a solo parent, and being a first-generation college student. These are used only to match you with scholarships meant for these groups.</li>
                <li><b>Application records:</b> your answers to scholarship application forms, the documents you upload (such as grades, certificates of registration and certificates), evaluation scores and decisions.</li>
                <li><b>System records:</b> sign-in activity, IP address and the actions you take in ScholarLink, kept in an audit trail for security.</li>
            </ul>

            <h2 class="text-slate-900">2. Why we use it</h2>
            <ul>
                <li>To create and secure your account and verify that you are a TAU student.</li>
                <li>To match you with scholarships you are eligible for and to process, evaluate and decide your applications.</li>
                <li>To notify you about your applications, approvals, requirements and deadlines by email and in the portal.</li>
                <li>To manage scholar renewals, award releases and records required by sponsors and government agencies.</li>
                <li>To produce reports for the University, sponsors and government agencies, and statistics that do not identify you.</li>
                <li>To keep the system secure, investigate misuse and comply with the law.</li>
            </ul>
            <p>We process your information based on your <b>consent</b>, which you give when you register, and as needed to provide the scholarship services you apply for and to comply with legal and regulatory requirements.</p>

            <h2 class="text-slate-900">3. Who we share it with</h2>
            <ul>
                <li><b>The OSSD and authorized University personnel</b> who handle scholarships.</li>
                <li><b>Scholarship sponsors and providers</b> (such as government agencies, foundations and companies): only the information and documents of applications made to <i>their</i> scholarships.</li>
                <li><b>Government agencies</b> that fund or regulate scholarships, such as the Commission on Higher Education (CHED) for the Tertiary Education Subsidy and Tulong Dunong Program, as they require.</li>
                <li><b>Service providers that help run ScholarLink:</b> Google Cloud Vision reads the text on your scanned ID, Google Gemini helps check uploaded documents, Google Sign-In lets you register with your Google account, and an email service delivers notifications. These services may process data outside the Philippines under their own security and privacy commitments.</li>
            </ul>
            <p>We do <b>not</b> sell your personal information or use it for advertising.</p>

            <h2 class="text-slate-900">4. How long we keep it</h2>
            <p>We keep your information for as long as you have an account and as long as needed for your scholarships and the records the University, sponsors and government agencies require to keep, following TAU's records retention rules. After that, it is securely deleted or anonymized.</p>

            <h2 class="text-slate-900">5. How we protect it</h2>
            <ul>
                <li>Passwords are stored encrypted (hashed) and are never visible to staff.</li>
                <li>Each person only sees what their role allows. Your uploaded documents can be opened only by you, the OSSD, and the sponsor of a scholarship you applied to.</li>
                <li>Administrators can be required to use two-factor authentication, and inactive sessions are signed out automatically.</li>
                <li>Activity is recorded in an audit trail that only the System Administrator can open, after re-entering their password.</li>
            </ul>

            <h2 class="text-slate-900">6. Your rights</h2>
            <p>Under RA 10173 you have the right to:</p>
            <ul>
                <li><b>be informed</b> about how your personal information is processed;</li>
                <li><b>access</b> your personal information, and <b>correct</b> it if it is inaccurate (you can update most details yourself in your Profile);</li>
                <li><b>object</b> to processing, and <b>withdraw your consent</b>, subject to records the University must keep by law;</li>
                <li>ask for your information to be <b>erased or blocked</b> when it is no longer needed or was processed unlawfully;</li>
                <li><b>data portability</b>, to obtain a copy of your information in a commonly used format;</li>
                <li><b>damages</b> for any harm caused by unlawful processing; and</li>
                <li><b>file a complaint</b> with the National Privacy Commission (privacy.gov.ph).</li>
            </ul>
            <p>Withdrawing consent may mean we can no longer process your scholarship applications.</p>

            <h2 class="text-slate-900">7. Contact us</h2>
            <p>For questions, requests or concerns about your personal information, contact the office below, which will coordinate with the University's Data Protection Officer.</p>
            <div class="mt-3 p-5 rounded-2xl bg-slate-50 border border-slate-200 text-sm leading-relaxed">
                <p class="font-black text-slate-900"><?= OSSD_OFFICE ?></p>
                <p><?= OSSD_UNIT ?></p>
                <p><?= OSSD_ADDRESS ?></p>
                <p>Monday to Friday, 8:00 AM – 5:00 PM</p>
                <p class="mt-2"><a href="<?= OSSD_FACEBOOK ?>" target="_blank" rel="noopener" class="font-bold text-blue-700 underline">Facebook page</a> · <a href="<?= OSSD_MESSENGER ?>" target="_blank" rel="noopener" class="font-bold text-blue-700 underline">Send a message</a></p>
            </div>

            <p class="mt-8 text-xs text-slate-400">We may update this notice when our processing changes. The date above shows the latest version; significant changes will be announced in ScholarLink.</p>
        </article>
    </div>
    <?php themeToggle('corner'); ?>
</body>
</html>
