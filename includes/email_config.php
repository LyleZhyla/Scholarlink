<?php
use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception;
use PHPMailer\PHPMailer\SMTP;

// Path sa PHPMailer
$base_dir = __DIR__ . '/../vendor/phpmailer/phpmailer/src/';
require $base_dir . 'Exception.php';
require $base_dir . 'PHPMailer.php';
require $base_dir . 'SMTP.php';

function configureScholarLinkMailer(PHPMailer $mail, string $fromName): void {
    static $smtp = null;
    if ($smtp === null) {
        $envPath = __DIR__ . '/../.env';
        $smtp = file_exists($envPath) ? (parse_ini_file($envPath) ?: []) : [];
    }

    $username = trim($smtp['SMTP_USERNAME'] ?? '');
    $password = trim($smtp['SMTP_PASSWORD'] ?? '');
    if ($username === '' || $password === '') {
        throw new RuntimeException('SMTP credentials are not configured.');
    }

    $mail->isSMTP();
    $mail->Host = $smtp['SMTP_HOST'] ?? 'smtp.gmail.com';
    $mail->SMTPAuth = true;
    $mail->Username = $username;
    $mail->Password = $password;
    $mail->SMTPSecure = PHPMailer::ENCRYPTION_STARTTLS;
    $mail->Port = (int)($smtp['SMTP_PORT'] ?? 587);
    $mail->setFrom($smtp['SMTP_FROM_EMAIL'] ?? $username, $fromName);

    if (($smtp['SMTP_ALLOW_SELF_SIGNED'] ?? '0') === '1') {
        $mail->SMTPOptions = [
            'ssl' => [
                'verify_peer' => false,
                'verify_peer_name' => false,
                'allow_self_signed' => true,
            ],
        ];
    }
}

// ==========================================
// 1. Scholarship Status Email
// ==========================================
function sendScholarshipEmail($to_email, $first_name, $scholarship_name, $status) {
    $mail = new PHPMailer(true);
    try {
        configureScholarLinkMailer($mail, 'TAU ScholarLink');
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
// 2. Forgot Password Email Function
// ==========================================
function sendPasswordResetEmail($to_email, $first_name, $reset_link) {
    $mail = new PHPMailer(true);
    try {
        configureScholarLinkMailer($mail, 'TAU ScholarLink Security');
        $mail->addAddress($to_email, $first_name);

        $mail->isHTML(true);
        $mail->Subject = 'ScholarLink Password Reset Request';
        
        $mail->Body = "
            <div style='font-family: Arial, sans-serif; max-width: 600px; margin: auto; border: 1px solid #e2e8f0; border-radius: 24px; padding: 40px;'>
                <h2 style='color: #16a34a; font-weight: 900;'>Hello, $first_name!</h2>
                <p style='color: #475569; line-height: 1.6;'>We received a request to reset your password for your ScholarLink account.</p>
                <p style='color: #475569; line-height: 1.6;'>Click the button below to securely set up a new password:</p>
                <a href='$reset_link' style='display: inline-block; background-color: #16a34a; color: #ffffff; text-decoration: none; padding: 14px 28px; border-radius: 12px; font-weight: bold; margin-top: 15px; margin-bottom: 25px;'>Reset My Password</a>
                <p style='color: #94a3b8; line-height: 1.6; font-size: 12px;'>If you did not request this, please ignore this email. This link will safely expire in 1 hour.</p>
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
        configureScholarLinkMailer($mail, 'TAU ScholarLink AI');
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
        configureScholarLinkMailer($mail, 'ScholarLink Security');
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
?>
