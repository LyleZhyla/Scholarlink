<?php
use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception;
use PHPMailer\PHPMailer\SMTP;

// Path sa PHPMailer
$base_dir = __DIR__ . '/../vendor/phpmailer/phpmailer/src/';
require_once $base_dir . 'Exception.php';
require_once $base_dir . 'PHPMailer.php';
require_once $base_dir . 'SMTP.php';

// ==========================================
// 0. SMTP settings, from .env (never written in the code: this file is in git)
//    SMTP_HOST, SMTP_PORT, SMTP_USER, SMTP_PASSWORD, SMTP_FROM, SMTP_ALLOW_SELF_SIGNED
// ==========================================
function mailSettings(): array {
    static $settings = null;
    if ($settings === null) {
        $env = is_file(__DIR__ . '/../.env') ? (@parse_ini_file(__DIR__ . '/../.env') ?: []) : [];
        $get = fn($key, $default = '') => (string) ($env[$key] ?? getenv($key) ?: $default);
        $settings = [
            'host' => $get('SMTP_HOST', 'smtp.gmail.com'),
            'port' => (int) $get('SMTP_PORT', '587'),
            'user' => $get('SMTP_USER'),
            'password' => $get('SMTP_PASSWORD'),
            'from' => $get('SMTP_FROM') ?: $get('SMTP_USER'),
            // One sender name on every email (the inbox shows it instead of the Gmail account's own name)
            'from_name' => $get('SMTP_FROM_NAME', 'TAU ScholarLink'),
            'allow_self_signed' => $get('SMTP_ALLOW_SELF_SIGNED', '0') === '1',
        ];
    }
    return $settings;
}

// Points a PHPMailer at the configured SMTP server and sets the sender
function configureMailer(PHPMailer $mail, string $from_name): void {
    $cfg = mailSettings();
    if ($cfg['user'] === '' || $cfg['password'] === '') {
        throw new Exception('Email is not configured: set SMTP_USER and SMTP_PASSWORD in .env');
    }
    $mail->isSMTP();
    $mail->Host       = $cfg['host'];
    $mail->SMTPAuth   = true;
    $mail->Username   = $cfg['user'];
    $mail->Password   = $cfg['password'];
    $mail->SMTPSecure = PHPMailer::ENCRYPTION_STARTTLS;
    $mail->Port       = $cfg['port'];
    $mail->CharSet    = 'UTF-8';

    // ✨ FIX FOR LOCALHOST: XAMPP on Windows has no CA bundle, so certificate checks are skipped there only
    if ($cfg['allow_self_signed']) {
        $mail->SMTPOptions = ['ssl' => ['verify_peer' => false, 'verify_peer_name' => false, 'allow_self_signed' => true]];
    }

    // $from_name is kept for callers, but every email goes out under the single configured name
    $mail->setFrom($cfg['from'], $cfg['from_name']);
}

// ==========================================
// 1. Scholarship Status Email
// ==========================================
function sendScholarshipEmail($to_email, $first_name, $scholarship_name, $status) {
    $mail = new PHPMailer(true);
    try {
        configureMailer($mail, 'TAU ScholarLink');
        $mail->addAddress($to_email, $first_name);

        $mail->isHTML(true);
        
        if ($status === 'Approved') {
            $mail->Subject = "Congratulations! Scholarship Approved";
            $body = "We are pleased to inform you that your application for <strong>$scholarship_name</strong> has been <strong>APPROVED</strong>.";
        } elseif ($status === 'Under Review') {
            $mail->Subject = "Update: Application Under Review";
            $body = "Your application for <strong>$scholarship_name</strong> is now being <strong>EVALUATED</strong> by our committee.";
        } elseif ($status === 'Shortlisted') {
            $mail->Subject = "Great News! You are Shortlisted";
            $body = "You have been <strong>SHORTLISTED</strong> for <strong>$scholarship_name</strong>. Please stay tuned for interview details.";
        } elseif ($status === 'Rejected') {
            $mail->Subject = "Scholarship Application Update";
            $body = "Thank you for your interest in <strong>$scholarship_name</strong>. We regret to inform you that we cannot move forward with your application at this time.";
        }

        $mail->Body = "
            <div style='font-family: Arial, sans-serif; max-width: 600px; margin: auto; border: 1px solid #e2e8f0; border-radius: 24px; padding: 40px;'>
                <h2 style='color: #2563eb; font-weight: 900;'>Hello, $first_name!</h2>
                <p style='color: #475569; line-height: 1.6;'>$body</p>
                <p style='color: #475569; line-height: 1.6;'>Log in to the portal for more details.</p>
            </div>";

        $mail->send();
        return true;
    } catch (Exception $e) {
        error_log("PHPMailer Error (Status): {$mail->ErrorInfo}");
        return false;
    }
}

// ==========================================
// Backup code box for password emails: the code in the link's token, typed on reset_code.php if the link won't open
// ==========================================
function resetCodeEmailBlock(string $link): string {
    parse_str((string) parse_url($link, PHP_URL_QUERY), $q);
    if (empty($q['token']) || !function_exists('resetCodeFor')) return '';
    $code = resetCodeFor($q['token']);
    return "
                <div style='background: #f8fafc; border: 1px dashed #cbd5e1; border-radius: 16px; padding: 18px; margin: 0 0 22px;'>
                    <p style='color: #475569; font-size: 13px; margin: 0 0 8px;'><b>Button not opening?</b> Open ScholarLink, choose <b>Forgot password &rarr; I have a code</b>, and enter:</p>
                    <p style='font-family: Consolas, monospace; font-size: 26px; font-weight: bold; letter-spacing: 4px; color: #0f172a; margin: 0;'>$code</p>
                </div>";
}

// ==========================================
// Welcome email for an account the Super Admin created: username, where to sign in, and a link to choose
// their own password (the temporary password is never emailed)
// ==========================================
function sendWelcomeEmail($to_email, $first_name, $role_label, $username, $login_link, $set_password_link) {
    $mail = new PHPMailer(true);
    try {
        configureMailer($mail, 'TAU ScholarLink');
        $mail->addAddress($to_email, $first_name);
        $mail->isHTML(true);
        $mail->Subject = 'Your ScholarLink account is ready';
        $first_name = htmlspecialchars($first_name);
        $role_label = htmlspecialchars($role_label);
        $username = htmlspecialchars($username);
        $mail->Body = "
            <div style='font-family: Arial, sans-serif; max-width: 600px; margin: auto; border: 1px solid #e2e8f0; border-radius: 24px; padding: 40px;'>
                <h2 style='color: #16a34a; font-weight: 900;'>Welcome to ScholarLink, $first_name!</h2>
                <p style='color: #475569; line-height: 1.6;'>A <b>$role_label</b> account was created for you on ScholarLink, the Tarlac Agricultural University scholarship portal.</p>
                <table style='margin: 18px 0; color: #334155; font-size: 14px;'>
                    <tr><td style='padding: 4px 16px 4px 0; color: #94a3b8;'>Username</td><td style='font-weight: bold;'>$username</td></tr>
                    <tr><td style='padding: 4px 16px 4px 0; color: #94a3b8;'>Email</td><td style='font-weight: bold;'>" . htmlspecialchars($to_email) . "</td></tr>
                    <tr><td style='padding: 4px 16px 4px 0; color: #94a3b8;'>Sign in at</td><td><a href='$login_link' style='color: #2563eb;'>$login_link</a></td></tr>
                </table>
                <p style='color: #475569; line-height: 1.6;'>Choose your own password to get started:</p>
                <a href='$set_password_link' style='display: inline-block; background-color: #16a34a; color: #ffffff; text-decoration: none; padding: 14px 28px; border-radius: 12px; font-weight: bold; margin-top: 10px; margin-bottom: 25px;'>Set My Password</a>
                " . resetCodeEmailBlock($set_password_link) . "
                " . ($role_label === 'Student' ? "<p style='color: #475569; line-height: 1.6;'><b>Have your Report of Grades (PDF) ready.</b> The first time you sign in, you'll upload it so ScholarLink can record your GWA and match you with scholarships.</p>" : '') . "
                <p style='color: #94a3b8; line-height: 1.6; font-size: 12px;'>This link and code work for 24 hours. After that, use \"Forgot password\" on the sign-in page. If you weren't expecting this account, contact the OSSD.</p>
            </div>";
        $mail->send();
        return true;
    } catch (Exception $e) {
        error_log("PHPMailer Error (Welcome): {$mail->ErrorInfo}");
        return false;
    }
}

// ==========================================
// 2. Forgot Password Email Function
// ==========================================
function sendPasswordResetEmail($to_email, $first_name, $reset_link) {
    $mail = new PHPMailer(true);
    try {
        configureMailer($mail, 'TAU ScholarLink Security');
        $mail->addAddress($to_email, $first_name);

        $mail->isHTML(true);
        $mail->Subject = 'ScholarLink Password Reset Request';
        
        $mail->Body = "
            <div style='font-family: Arial, sans-serif; max-width: 600px; margin: auto; border: 1px solid #e2e8f0; border-radius: 24px; padding: 40px;'>
                <h2 style='color: #16a34a; font-weight: 900;'>Hello, $first_name!</h2>
                <p style='color: #475569; line-height: 1.6;'>We received a request to reset your password for your ScholarLink account.</p>
                <p style='color: #475569; line-height: 1.6;'>Click the button below to securely set up a new password:</p>
                <a href='$reset_link' style='display: inline-block; background-color: #16a34a; color: #ffffff; text-decoration: none; padding: 14px 28px; border-radius: 12px; font-weight: bold; margin-top: 15px; margin-bottom: 25px;'>Reset My Password</a>
                " . resetCodeEmailBlock($reset_link) . "
                <p style='color: #94a3b8; line-height: 1.6; font-size: 12px;'>If you did not request this, please ignore this email. This link and code expire in 1 hour, and only the newest one works.</p>
            </div>";

        $mail->send();
        return true;
    } catch (Exception $e) {
        error_log("PHPMailer Error (Reset): {$mail->ErrorInfo}");
        return false;
    }
}

// ==========================================
// 3. AI Missing Requirements Email Function
// ==========================================
function sendMissingRequirementsEmail($to_email, $first_name, $missing_list_html) {
    $mail = new PHPMailer(true);
    try {
        configureMailer($mail, 'TAU ScholarLink AI');
        $mail->addAddress($to_email, $first_name);

        $mail->isHTML(true);
        $mail->Subject = 'URGENT: Missing Requirements for ScholarLink [' . date('h:i:s A') . ']';
        
        $mail->Body = "
            <div style='font-family: Arial, sans-serif; max-width: 600px; margin: auto; border: 1px solid #e2e8f0; border-radius: 24px; padding: 40px;'>
                <h2 style='color: #ef4444; font-weight: 900;'>Hello, $first_name!</h2>
                <p style='color: #475569; line-height: 1.6;'>Your ScholarLink AI Assistant noticed that your application is missing some crucial documents.</p>
                
                <div style='background-color: #f8fafc; padding: 15px; border-radius: 12px; margin-bottom: 20px; border: 1px solid #e2e8f0;'>
                    $missing_list_html
                </div>
                
                <p style='color: #475569; line-height: 1.6;'>Please log in to your ScholarLink Vault and upload these as soon as possible to avoid missing the deadline!</p>
                <br>
                <p style='color: #475569; line-height: 1.6;'>Best regards,<br><b>ScholarLink AI Mentor</b></p>
            </div>";

        $mail->send();
        return true;
    } catch (Exception $e) {
        error_log("PHPMailer Error (AI): {$mail->ErrorInfo}");
        return false;
    }
}

// ==========================================
// 4. OTP Email Function for Registration
// ==========================================
function sendRegistrationOTP($to_email, $first_name, $otp_code) {
    $mail = new PHPMailer(true);
    try {
        configureMailer($mail, 'ScholarLink Security');
        $mail->addAddress($to_email, $first_name);

        $mail->isHTML(true);
        $mail->Subject = 'Your ScholarLink Verification Code: ' . $otp_code;
        
        $mail->Body = "
            <div style='font-family: Arial, sans-serif; max-width: 600px; margin: auto; border: 1px solid #e2e8f0; border-radius: 24px; padding: 40px; text-align: center;'>
                <h2 style='color: #16a34a; font-weight: 900;'>Verify Your Account</h2>
                <p style='color: #475569; line-height: 1.6;'>Hello $first_name, please use the 6-digit code below to verify your email address.</p>
                <div style='background-color: #f0fdf4; padding: 20px; border-radius: 16px; margin: 30px 0; border: 2px dashed #86efac;'>
                    <span style='font-size: 32px; font-weight: 900; letter-spacing: 8px; color: #16a34a;'>$otp_code</span>
                </div>
            </div>
        ";
        $mail->send();
        return true;
    } catch (Exception $e) {
        error_log("PHPMailer Error (OTP): {$mail->ErrorInfo}");
        return false;
    }
}

// ==========================================
// 6. Approval Notice (sent when an application is approved, notice PDF attached)
// ==========================================
function sendAwardNoticeEmail($to_email, $first_name, $scholarship_name, array $notice, $pdf_bytes, $pdf_name) {
    $mail = new PHPMailer(true);
    try {
        configureMailer($mail, 'ScholarLink Admin');
        $mail->addAddress($to_email, $first_name);
        $mail->addStringAttachment($pdf_bytes, $pdf_name, PHPMailer::ENCODING_BASE64, 'application/pdf');

        $e = fn($v) => htmlspecialchars((string) $v, ENT_QUOTES, 'UTF-8');
        $items = '';
        foreach ($notice['Requirements'] as $r) {
            $subs = '';
            foreach ($r['sub'] ?? [] as $s) $subs .= "<li style='list-style: none;'>&#10003; " . $e($s) . "</li>";
            $items .= "<li style='margin: 4px 0;'>" . $e($r['text']) . ($subs ? "<ul style='padding-left: 14px;'>$subs</ul>" : '') . "</li>";
        }
        $deadline = !empty($notice['SubmissionDeadline'])
            ? "<p style='color: #475569;'>Last day of submission: <b>" . date('l, F j, Y', strtotime($notice['SubmissionDeadline'])) . "</b></p>" : '';
        $requirements = $items
            ? "<div style='text-align: left; background: #f8fafc; border-radius: 16px; padding: 20px 24px; margin: 24px 0;'>
                   <p style='font-weight: bold; color: #0f172a; margin-top: 0;'>" . $e($notice['Intro']) . "</p><ol style='color: #334155; padding-left: 20px;'>$items</ol>$deadline</div>"
            : $deadline;

        $mail->isHTML(true);
        $mail->Subject = 'Approved: ' . $scholarship_name . ' – Your Approval Notice';
        $mail->Body = "
            <div style='font-family: Arial, sans-serif; max-width: 600px; margin: auto; border: 1px solid #e2e8f0; border-radius: 24px; padding: 40px; text-align: center;'>
                <h2 style='color: #16a34a; font-weight: 900;'>Congratulations, " . $e($first_name) . "! 🏆</h2>
                <p style='color: #475569; line-height: 1.6;'>Your application for the <b>" . $e($scholarship_name) . "</b> has been officially <b>approved</b>. Your Approval Notice is attached to this email.</p>
                $requirements
                <p style='color: #94a3b8; font-size: 12px;'>You can also download your Approval Notice anytime from My Applications in ScholarLink.</p>
            </div>
        ";
        $mail->send();
        return true;
    } catch (Exception $e) {
        error_log("PHPMailer Error (Approval notice): {$mail->ErrorInfo}");
        return false;
    }
}

// ==========================================
// 5. Admin Login Code (Two-Factor Authentication)
// ==========================================
function sendLoginCodeEmail($to_email, $first_name, $code) {
    $mail = new PHPMailer(true);
    try {
        configureMailer($mail, 'ScholarLink Security');
        $mail->addAddress($to_email, $first_name);

        $mail->isHTML(true);
        $mail->Subject = 'Your ScholarLink Login Code: ' . $code;
        $safe_name = htmlspecialchars((string) $first_name);
        $mail->Body = "
            <div style='font-family: Arial, sans-serif; max-width: 600px; margin: auto; border: 1px solid #e2e8f0; border-radius: 24px; padding: 40px; text-align: center;'>
                <h2 style='color: #0f172a; font-weight: 900;'>Admin Login Verification</h2>
                <p style='color: #475569; line-height: 1.6;'>Hello $safe_name, someone (hopefully you) signed in to the ScholarLink Admin Portal. Enter this code to finish signing in. It expires in 5 minutes.</p>
                <div style='background-color: #eff6ff; padding: 20px; border-radius: 16px; margin: 30px 0; border: 2px dashed #93c5fd;'>
                    <span style='font-size: 32px; font-weight: 900; letter-spacing: 8px; color: #1d4ed8;'>$code</span>
                </div>
                <p style='color: #94a3b8; font-size: 12px;'>If this wasn't you, change your password right away and tell the Super Admin.</p>
            </div>
        ";
        $mail->send();
        return true;
    } catch (Exception $e) {
        error_log("PHPMailer Error (Login code): {$mail->ErrorInfo}");
        return false;
    }
}
// ==========================================
// 7. Deadline reminder (tools/send_deadline_reminders.php): the application is still missing documents
// ==========================================
function sendDeadlineReminderEmail($to_email, $first_name, $scholarship_name, $deadline, $days_left, array $missing, $portal_url) {
    $mail = new PHPMailer(true);
    try {
        configureMailer($mail, 'TAU ScholarLink');
        $mail->addAddress($to_email, $first_name);
        $e = fn($v) => htmlspecialchars((string) $v, ENT_QUOTES, 'UTF-8');
        $when = $days_left <= 0 ? 'today' : ($days_left === 1 ? 'tomorrow' : "in $days_left days");
        $items = implode('', array_map(fn($m) => '<li style="margin: 4px 0;">' . $e($m) . '</li>', $missing));

        $mail->isHTML(true);
        $mail->Subject = "Reminder: {$scholarship_name} closes $when";
        $mail->Body = "
            <div style='font-family: Arial, sans-serif; max-width: 600px; margin: auto; border: 1px solid #e2e8f0; border-radius: 24px; padding: 40px;'>
                <h2 style='color: #b45309; font-weight: 900; margin-top: 0;'>⏰ Your application is not complete yet</h2>
                <p style='color: #475569; line-height: 1.6;'>Hello " . $e($first_name) . ", the <b>" . $e($scholarship_name) . "</b> closes <b>$when</b> (" . $e(date('l, F j, Y', strtotime($deadline))) . "). Your application still needs:</p>
                <ul style='color: #334155; background: #fffbeb; border-radius: 12px; padding: 16px 16px 16px 36px;'>$items</ul>
                <p style='text-align: center; margin: 28px 0 10px;'>
                    <a href='" . $e($portal_url) . "' style='display: inline-block; background-color: #16a34a; color: #ffffff; text-decoration: none; padding: 14px 28px; border-radius: 12px; font-weight: bold;'>Complete my application</a>
                </p>
                <p style='color: #94a3b8; font-size: 12px; text-align: center;'>Applications that are incomplete after the deadline may not be evaluated.</p>
            </div>
        ";
        $mail->send();
        return true;
    } catch (Exception $e) {
        error_log("PHPMailer Error (Deadline reminder): {$mail->ErrorInfo}");
        return false;
    }
}
