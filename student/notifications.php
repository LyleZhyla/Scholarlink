<?php 
session_start();

include '../includes/session_manager.php'; 
header("Cache-Control: no-store, no-cache, must-revalidate, max-age=0");
header("Cache-Control: post-check=0, pre-check=0", false);
header("Pragma: no-cache");

if (!isset($_SESSION['logged_in']) || $_SESSION['role'] !== 'Student') { 
    header("Location: ../student_login.php"); 
    exit(); 
}

include '../includes/db_connect.php'; 
include '../includes/header.php'; 
include '../includes/student_sidebar.php'; 

$user_id = $_SESSION['user_id'];

try {
    // 1. Fetch all notifications for this student
    $stmt = $pdo->prepare("SELECT * FROM notifications WHERE UserID = :uid ORDER BY CreatedAt DESC LIMIT 30");
    $stmt->execute(['uid' => $user_id]);
    $notifications = $stmt->fetchAll();

    // 2. Automatically mark them as 'Read' now that the student has seen this page
    $update_stmt = $pdo->prepare("UPDATE notifications SET IsRead = 1 WHERE UserID = :uid AND IsRead = 0");
    $update_stmt->execute(['uid' => $user_id]);

} catch (PDOException $e) {
    $notifications = [];
}

// Helper function to pick colors and icons based on the notification type
function getNotificationStyle($type) {
    return match($type) {
        'success' => ['bg' => 'bg-green-50', 'text' => 'text-green-600', 'border' => 'hover:border-green-600', 'icon' => '🎉'],
        'warning' => ['bg' => 'bg-amber-50', 'text' => 'text-amber-600', 'border' => 'hover:border-amber-600', 'icon' => '⚠️'],
        'danger'  => ['bg' => 'bg-red-50', 'text' => 'text-red-600', 'border' => 'hover:border-red-600', 'icon' => '🛑'],
        default   => ['bg' => 'bg-blue-50', 'text' => 'text-blue-600', 'border' => 'hover:border-blue-600', 'icon' => '🔔'], // Info
    };
}

// Helper function to make dates look like "2 hours ago" or "Yesterday"
function time_elapsed_string($datetime, $full = false) {
    $now = new DateTime;
    $ago = new DateTime($datetime);
    $diff = $now->diff($ago);
    $diff->w = floor($diff->d / 7);
    $diff->d -= $diff->w * 7;
    $string = ['y' => 'year','m' => 'month','w' => 'week','d' => 'day','h' => 'hour','i' => 'minute','s' => 'second'];
    foreach ($string as $k => &$v) {
        if ($diff->$k) { $v = $diff->$k . ' ' . $v . ($diff->$k > 1 ? 's' : ''); } 
        else { unset($string[$k]); }
    }
    if (!$full) $string = array_slice($string, 0, 1);
    return $string ? implode(', ', $string) . ' ago' : 'Just now';
}
?>

<main class="flex-1 p-5 lg:p-10 lg:ml-72 bg-slate-50/50 min-h-screen transition-all duration-300">
    <header class="mb-8 lg:mb-10">
        <h2 class="text-3xl lg:text-4xl font-black text-slate-900 tracking-tight mb-2">Notifications</h2>
        <p class="text-slate-500 text-sm lg:text-base font-medium">View live updates and alerts regarding your scholarships.</p>
    </header>

    <div class="space-y-4 lg:space-y-6 max-w-4xl">
        <?php foreach($notifications as $notif): 
            $style = getNotificationStyle($notif['Type']);
            $is_unread = ($notif['IsRead'] == 0);
        ?>
            <div class="bg-white p-5 lg:p-6 rounded-[1.5rem] lg:rounded-2xl shadow-sm border <?= $is_unread ? 'border-blue-400 bg-blue-50/10' : 'border-slate-200' ?> flex flex-col sm:flex-row items-start sm:items-center gap-4 <?= $style['border'] ?> transition-colors cursor-pointer group relative">
                
                <?php if($is_unread): ?>
                    <div class="absolute -top-1 -right-1 w-3 h-3 bg-blue-500 rounded-full animate-ping"></div>
                    <div class="absolute -top-1 -right-1 w-3 h-3 bg-blue-500 rounded-full"></div>
                <?php endif; ?>

                <div class="w-10 h-10 lg:w-12 lg:h-12 <?= $style['bg'] ?> <?= $style['text'] ?> rounded-full flex items-center justify-center text-lg lg:text-xl shrink-0">
                    <?= $style['icon'] ?>
                </div>
                <div class="flex-1">
                    <div class="flex flex-col sm:flex-row sm:items-center justify-between gap-1 mb-1">
                        <h4 class="font-black text-slate-900 text-sm lg:text-base group-hover:<?= $style['text'] ?> transition-colors"><?= htmlspecialchars($notif['Title']) ?></h4>
                        <span class="text-[9px] lg:text-[10px] font-bold text-slate-400 uppercase tracking-widest whitespace-nowrap">
                            <?= time_elapsed_string($notif['CreatedAt']) ?>
                        </span>
                    </div>
                    <p class="text-xs lg:text-sm text-slate-500 font-medium leading-relaxed">
                        <?= htmlspecialchars($notif['Message']) ?>
                    </p>
                </div>
            </div>
        <?php endforeach; ?>

        <?php if(empty($notifications)): ?>
            <div class="text-center p-12 bg-white rounded-[2rem] border border-slate-200">
                <div class="text-4xl mb-4 opacity-50 grayscale">📭</div>
                <h3 class="text-lg font-black text-slate-900 mb-1">No Notifications Yet</h3>
                <p class="text-slate-500 font-medium text-sm">We'll alert you here when there are updates to your applications.</p>
            </div>
        <?php endif; ?>
    </div>
</main>
<?php include '../includes/footer.php'; ?>