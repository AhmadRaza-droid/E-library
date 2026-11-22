<?php
include 'session_timeout.php';
include 'config.php';
require_once __DIR__ . '/mailer.php';

require_admin($conn);
require_once __DIR__ . '/audit.php';
require_once __DIR__ . '/student_resources.php';
$pendingMaterials = resources_pending($conn);
$me = (int)$_SESSION['admin_id'];

$notice = null;   // ['message', 'redirect']
function done($msg, $page){
    global $conn;
    $actions = [
        'delete_book'=>'Delete book','delete_user'=>'Delete user','add_book'=>'Add book',
        'add_category'=>'Add category','delete_category'=>'Delete category',
        'make_admin'=>'Grant admin role','remove_admin'=>'Remove admin role',
        'delete_message'=>'Delete message','reply_submit'=>'Reply to message',
        'send_notification'=>'Send notification'
    ];
    foreach ($actions as $key => $label) {
        if (!isset($_POST[$key])) continue;
        $target = is_scalar($_POST[$key]) ? (string)$_POST[$key] : '';
        if ($key === 'add_book') $target = trim((string)($_POST['title'] ?? ''));
        if ($key === 'add_category') $target = trim((string)($_POST['category'] ?? ''));
        if ($key === 'send_notification') $target = trim((string)($_POST['title'] ?? ''));
        $detail = $target !== '' ? 'Target: ' . $target . ' | Result: ' . trim(strip_tags((string)$msg)) : 'Result: ' . trim(strip_tags((string)$msg));
        audit_log($conn, $label, $detail);
        break;
    }
    js_alert_redirect($msg, 'admin_dashboard.php?page=' . $page);
}

// ========== ALL STATE-CHANGING ACTIONS ARE POST + CSRF (checked globally in config.php) ==========
if ($_SERVER['REQUEST_METHOD'] === 'POST') {

    // ---- DELETE BOOK ----
    if (isset($_POST['delete_book'])) {
        $id = (int)$_POST['delete_book'];
        done(admin_delete_record($conn, 'book', $id) ? 'Book deleted successfully!' : '❌ Book not found or could not be deleted.', 'all_books');
    }

    // ---- DELETE USER (not yourself) ----
    if (isset($_POST['delete_user'])) {
        $id = (int)$_POST['delete_user'];
        if ($id === $me) { done('❌ You cannot delete your own account.', 'all_users'); }
        done(admin_delete_record($conn, 'user', $id) ? 'User deleted successfully!' : '❌ User not found or could not be deleted.', 'all_users');
    }

    // ---- ADD BOOK ----
    if (isset($_POST['add_book'])) {
        $title       = trim($_POST['title'] ?? '');
        $author      = trim($_POST['author'] ?? '');
        $description = trim($_POST['description'] ?? '');
        $category    = trim($_POST['category'] ?? '');
        $cover       = safe_url($_POST['cover_image_url'] ?? '');
        $read_link   = trim($_POST['read_link'] ?? '');
        $dl_link     = trim($_POST['download_epub_link'] ?? '');

        $read_ok = ctype_digit($read_link) || safe_url($read_link) !== '';
        $dl_ok   = $dl_link === '' || ctype_digit($dl_link) || safe_url($dl_link) !== '';
        if ($title === '' || $author === '' || !$read_ok || !$dl_ok) {
            done('❌ Title, author and valid http(s) links are required.', 'add_book');
        }

        // Each book can have its own validated cover upload; an uploaded cover takes priority over its URL.
        $coverFile = '';
        if (isset($_FILES['cover_file']) && ($_FILES['cover_file']['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_NO_FILE) {
            [$coverOk, $coverResult] = save_upload($_FILES['cover_file'], 'uploads/covers',
                ['image/jpeg' => 'jpg', 'image/png' => 'png', 'image/webp' => 'webp'],
                3 * 1024 * 1024, 'cover');
            if (!$coverOk) done('❌ Cover upload: ' . $coverResult, 'add_book');
            $coverFile = $coverResult;
            $cover = $coverFile;
        }

        $ok = db_exec($conn,
            "INSERT INTO books (title, author, description, category, cover_image_url, cover_file, read_link, download_epub_link)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?)", 'ssssssss', [$title, $author, $description, $category, $cover, $coverFile, $read_link, $dl_link]);
        if ($ok === false && $coverFile !== '') @unlink(__DIR__ . '/' . $coverFile);
        done($ok === false ? '❌ Error adding book.' : '✅ Book added successfully!', 'all_books');
    }

    // ---- CATEGORIES ----
    if (isset($_POST['add_category'])) {
        $category = trim($_POST['category'] ?? '');
        if ($category === '' || mb_strlen($category) > 100) {
            done('❌ Enter a category name of at most 100 characters.', 'categories');
        }
        $ok = db_exec($conn, "INSERT INTO categories (name) VALUES (?)", 's', [$category]);
        done($ok === 1 ? '✅ Category added!' : '❌ Could not add the category.', 'categories');
    }
    if (isset($_POST['delete_category'])) {
        $ok = db_exec($conn, "DELETE FROM categories WHERE id=?", 'i', [(int)$_POST['delete_category']]);
        done($ok === 1 ? 'Category deleted!' : '❌ Category not found or could not be deleted.', 'categories');
    }

    // ---- ADMIN RIGHTS ----
    if (isset($_POST['make_admin'])) {
        $ok = db_exec($conn, "UPDATE users SET is_admin=1 WHERE id=?", 'i', [(int)$_POST['make_admin']]);
        done($ok === 1 ? 'User is now Admin!' : '❌ User not found or already an admin.', 'all_users');
    }
    if (isset($_POST['remove_admin'])) {
        $id = (int)$_POST['remove_admin'];
        $admins = (int)db_value($conn, "SELECT COUNT(*) FROM users WHERE is_admin=1", '', [], 0);
        if ($id === $me || $admins <= 1) { done('❌ You cannot remove the last / your own admin rights.', 'all_users'); }
        $ok = db_exec($conn, "UPDATE users SET is_admin=0 WHERE id=?", 'i', [$id]);
        done($ok === 1 ? 'Admin rights removed!' : '❌ Admin not found or rights could not be removed.', 'all_users');
    }

    // ---- MESSAGES ----
    if (isset($_POST['delete_message'])) {
        $ok = db_exec($conn, "DELETE FROM messages WHERE id=?", 'i', [(int)$_POST['delete_message']]);
        done($ok === 1 ? 'Message deleted successfully!' : '❌ Message not found or could not be deleted.', 'messages');
    }
    if (isset($_POST['reply_submit'])) {
        $message_id = (int)($_POST['message_id'] ?? 0);
        $reply      = trim($_POST['reply'] ?? '');
        if ($message_id <= 0 || $reply === '') { done('❌ Enter a reply before sending.', 'messages'); }
        if (db_exec($conn, "UPDATE messages SET reply=? WHERE id=?", 'si', [$reply, $message_id]) === false) {
            done('❌ Could not save the reply.', 'messages');
        }

        $msg = db_row($conn, "SELECT * FROM messages WHERE id=?", 'i', [$message_id]);
        if (!$msg) { done('❌ Message not found.', 'messages'); }
        if (validate_email($msg['email'])) {
            $html = "<div style='font-family: Arial; padding: 15px;'>
                        <h3>Reply from Admin</h3>
                        <p><strong>Your message:</strong> " . nl2br(e($msg['message'])) . "</p>
                        <p><strong>Admin Reply:</strong> " . nl2br(e($reply)) . "</p>
                        <hr><small>Book's Library Team</small></div>";
            if (send_mail($msg['email'], $msg['name'], 'Reply to your message', $html)) {
                done('✅ Reply saved and email sent.', 'messages');
            }
        }
        done('⚠️ Reply saved, but the email could not be sent.', 'messages');
    }

    // ---- NOTIFICATIONS (saved + e-mailed over ONE smtp connection) ----
    if (isset($_POST['send_notification'])) {
        $title        = trim($_POST['title'] ?? '');
        $message_body = trim($_POST['message'] ?? '');
        if ($title === '' || $message_body === '') { done('❌ Title and message are required.', 'notifications'); }
        if (mb_strlen($title) > 150 || mb_strlen($message_body) > 5000) {
            done('❌ Title must be 150 characters or less and message 5,000 characters or less.', 'notifications');
        }
        if (($_POST['confirm_broadcast'] ?? '') !== 'yes') {
            done('❌ Please confirm that you want to email this notification to all verified users.', 'notifications');
        }

        if (db_exec($conn, "INSERT INTO notifications (title, message, created_at) VALUES (?, ?, NOW())", 'ss', [$title, $message_body]) === false) {
            done('❌ Could not save the notification.', 'notifications');
        }

        $recipients  = db_all($conn, "SELECT email, fullname FROM users WHERE email != '' AND is_verified=1");
        $total_users = count($recipients);
        $sent_count  = 0;
        @set_time_limit(300);

        try {
            $mail = make_mailer(true);
            $mail->isHTML(true);
            $mail->Subject = $title;
            foreach ($recipients as $u) {
                try {
                    $mail->clearAddresses();
                    $mail->addAddress($u['email'], $u['fullname']);
                    $mail->Body = "<div style='font-family: Arial; padding: 20px;'>
                        <h2 style='color: #0b1f3a;'>📢 " . e($title) . "</h2>
                        <p>" . nl2br(e($message_body)) . "</p><br><hr>
                        <small style='color: #666;'>📚 Book's Library Team</small></div>";
                    $mail->AltBody = $title . "\n\n" . $message_body;
                    $mail->send();
                    $sent_count++;
                } catch (\Throwable $ex) { error_log('Notification mail failed: ' . $ex->getMessage()); }
            }
            $mail->smtpClose();
        } catch (\Throwable $ex) { error_log('Notification mailer failed: ' . $ex->getMessage()); }

        done("✅ Notification sent to $sent_count out of $total_users users via email!\nSaved to database also.", 'notifications');
    }
}

// Get current page (whitelist)
$valid_pages = ['dashboard', 'add_book', 'categories', 'website', 'all_books', 'all_users', 'messages', 'notifications', 'auto_import', 'book_requests', 'reviews'];
$page = (isset($_GET['page']) && in_array($_GET['page'], $valid_pages, true)) ? $_GET['page'] : 'dashboard';

// Book management: paginate the catalogue so large imports (2,000+ books) stay fast.
$bookSearch = trim((string)($_GET['book_search'] ?? ''));
$bookPage = max(1, (int)($_GET['book_page'] ?? 1));
$bookPerPage = 25;
$bookWhereSql = '';
if ($bookSearch !== '') {
    $bookTerm = mysqli_real_escape_string($conn, $bookSearch);
    $bookWhereSql = " WHERE title LIKE '%{$bookTerm}%' OR author LIKE '%{$bookTerm}%' OR category LIKE '%{$bookTerm}%'";
}
$bookTotal = (int)db_value($conn, "SELECT COUNT(*) FROM books" . $bookWhereSql, '', [], 0);
$bookPages = max(1, (int)ceil($bookTotal / $bookPerPage));
$bookPage = min($bookPage, $bookPages);
$bookOffset = ($bookPage - 1) * $bookPerPage;
$books = mysqli_query($conn, "SELECT id, title, author, category, download_count, cover_image_url, cover_file, image, pdf_file FROM books" . $bookWhereSql . " ORDER BY id DESC LIMIT {$bookPerPage} OFFSET {$bookOffset}");
// Messages inbox: searchable, status-filtered and paginated.
$messageSearch = trim((string)($_GET['message_search'] ?? ''));
$messageStatus = (string)($_GET['message_status'] ?? 'all');
$messagePage = max(1, (int)($_GET['message_page'] ?? 1));
$messagePerPage = 20;
$messageWhere = [];
if ($messageSearch !== '') {
    $messageTerm = mysqli_real_escape_string($conn, $messageSearch);
    $messageWhere[] = "(name LIKE '%{$messageTerm}%' OR email LIKE '%{$messageTerm}%' OR message LIKE '%{$messageTerm}%')";
}
if ($messageStatus === 'pending') $messageWhere[] = "(reply IS NULL OR TRIM(reply)='')";
elseif ($messageStatus === 'replied') $messageWhere[] = "(reply IS NOT NULL AND TRIM(reply)<>'')";
else $messageStatus = 'all';
$messageWhereSql = $messageWhere ? ' WHERE ' . implode(' AND ', $messageWhere) : '';
$messageTotal = (int)db_value($conn, "SELECT COUNT(*) FROM messages" . $messageWhereSql, '', [], 0);
$messagePages = max(1, (int)ceil($messageTotal / $messagePerPage));
$messagePage = min($messagePage, $messagePages);
$messageOffset = ($messagePage - 1) * $messagePerPage;
$messages = mysqli_query($conn, "SELECT * FROM messages" . $messageWhereSql . " ORDER BY id DESC LIMIT {$messagePerPage} OFFSET {$messageOffset}");
$messagePending = (int)db_value($conn, "SELECT COUNT(*) FROM messages WHERE reply IS NULL OR TRIM(reply)=''", '', [], 0);
$messageReplied = (int)db_value($conn, "SELECT COUNT(*) FROM messages WHERE reply IS NOT NULL AND TRIM(reply)<>''", '', [], 0);
// User management: searchable, filterable and paginated (25 users per page).
$userSearch = trim((string)($_GET['user_search'] ?? ''));
$userRole = (string)($_GET['user_role'] ?? 'all');
$userVerified = (string)($_GET['user_verified'] ?? 'all');
$userPage = max(1, (int)($_GET['user_page'] ?? 1));
$userPerPage = 25;
$userWhere = [];
if ($userSearch !== '') {
    $escapedSearch = mysqli_real_escape_string($conn, $userSearch);
    $userWhere[] = "(fullname LIKE '%{$escapedSearch}%' OR email LIKE '%{$escapedSearch}%' OR CAST(id AS CHAR) LIKE '%{$escapedSearch}%')";
}
if ($userRole === 'admin') $userWhere[] = "is_admin=1";
elseif ($userRole === 'user') $userWhere[] = "COALESCE(is_admin,0)=0";
else $userRole = 'all';
if ($userVerified === 'verified') $userWhere[] = "is_verified=1";
elseif ($userVerified === 'unverified') $userWhere[] = "COALESCE(is_verified,0)=0";
else $userVerified = 'all';
$userWhereSql = $userWhere ? ' WHERE ' . implode(' AND ', $userWhere) : '';
$userTotal = (int)db_value($conn, "SELECT COUNT(*) FROM users" . $userWhereSql, '', [], 0);
$userPages = max(1, (int)ceil($userTotal / $userPerPage));
$userPage = min($userPage, $userPages);
$userOffset = ($userPage - 1) * $userPerPage;
$all_users = mysqli_query($conn, "SELECT id, fullname, email, profile_image, is_admin, is_verified FROM users" . $userWhereSql . " ORDER BY id DESC LIMIT {$userPerPage} OFFSET {$userOffset}");
// Notification history: searchable and paginated to keep the admin page responsive.
$notificationSearch = trim((string)($_GET['notification_search'] ?? ''));
$notificationPage = max(1, (int)($_GET['notification_page'] ?? 1));
$notificationPerPage = 15;
$notificationWhereSql = '';
if ($notificationSearch !== '') {
    $notificationTerm = mysqli_real_escape_string($conn, $notificationSearch);
    $notificationWhereSql = " WHERE title LIKE '%{$notificationTerm}%' OR message LIKE '%{$notificationTerm}%'";
}
$notificationTotal = (int)db_value($conn, "SELECT COUNT(*) FROM notifications" . $notificationWhereSql, '', [], 0);
$notificationPages = max(1, (int)ceil($notificationTotal / $notificationPerPage));
$notificationPage = min($notificationPage, $notificationPages);
$notificationOffset = ($notificationPage - 1) * $notificationPerPage;
$notifications = mysqli_query($conn, "SELECT * FROM notifications" . $notificationWhereSql . " ORDER BY id DESC LIMIT {$notificationPerPage} OFFSET {$notificationOffset}");
$verifiedRecipientCount = (int)db_value($conn, "SELECT COUNT(*) FROM users WHERE email <> '' AND is_verified=1", '', [], 0);
$categories    = mysqli_query($conn, "SELECT * FROM categories ORDER BY id DESC");

$total_users      = (int)db_value($conn, "SELECT COUNT(*) FROM users", '', [], 0);
$total_books      = (int)db_value($conn, "SELECT COUNT(*) FROM books", '', [], 0);
$total_reviews    = (int)db_value($conn, "SELECT COUNT(*) FROM reviews", '', [], 0);
$total_categories = (int)db_value($conn, "SELECT COUNT(*) FROM categories", '', [], 0);
$total_downloads  = (int)db_value($conn, "SELECT COALESCE(SUM(download_count),0) FROM books", '', [], 0);
$verified_users   = (int)db_value($conn, "SELECT COUNT(*) FROM users WHERE is_verified=1", '', [], 0);
$pending_requests = (int)db_value($conn, "SELECT COUNT(*) FROM book_requests WHERE status='Pending'", '', [], 0);

$most_downloaded = mysqli_query($conn, "SELECT title, download_count FROM books ORDER BY download_count DESC LIMIT 5");
$most_favorites  = mysqli_query($conn,
"SELECT books.title, COUNT(favorites.id) AS total
 FROM favorites
 JOIN books ON favorites.book_id = books.id
 GROUP BY books.id, books.title
 ORDER BY total DESC
 LIMIT 5");

// Book requests: search, status filters and pagination.
$requestSearch = trim((string)($_GET['request_search'] ?? ''));
$requestStatus = (string)($_GET['request_status'] ?? 'all');
$requestPage = max(1, (int)($_GET['request_page'] ?? 1));
$requestPerPage = 20;
$requestWhere = [];
if ($requestSearch !== '') {
    $requestTerm = mysqli_real_escape_string($conn, $requestSearch);
    $requestWhere[] = "(users.fullname LIKE '%{$requestTerm}%' OR users.email LIKE '%{$requestTerm}%' OR book_requests.book_name LIKE '%{$requestTerm}%' OR book_requests.category LIKE '%{$requestTerm}%')";
}
$allowedRequestStatuses = ['Pending', 'Approved', 'Added', 'Rejected'];
if (in_array($requestStatus, $allowedRequestStatuses, true)) {
    $requestWhere[] = "book_requests.status='" . mysqli_real_escape_string($conn, $requestStatus) . "'";
} else $requestStatus = 'all';
$requestWhereSql = $requestWhere ? ' WHERE ' . implode(' AND ', $requestWhere) : '';
$requestBaseSql = " FROM book_requests JOIN users ON book_requests.user_id = users.id";
$requestTotal = (int)db_value($conn, "SELECT COUNT(*)" . $requestBaseSql . $requestWhereSql, '', [], 0);
$requestPages = max(1, (int)ceil($requestTotal / $requestPerPage));
$requestPage = min($requestPage, $requestPages);
$requestOffset = ($requestPage - 1) * $requestPerPage;
$book_requests = mysqli_query($conn, "SELECT book_requests.*, users.fullname, users.email" . $requestBaseSql . $requestWhereSql . " ORDER BY book_requests.id DESC LIMIT {$requestPerPage} OFFSET {$requestOffset}");
$requestPending = (int)db_value($conn, "SELECT COUNT(*) FROM book_requests WHERE status='Pending'", '', [], 0);
$requestApproved = (int)db_value($conn, "SELECT COUNT(*) FROM book_requests WHERE status='Approved'", '', [], 0);
$requestAdded = (int)db_value($conn, "SELECT COUNT(*) FROM book_requests WHERE status='Added'", '', [], 0);

// Reviews: search, rating filter and pagination.
$reviewSearch = trim((string)($_GET['review_search'] ?? ''));
$reviewRating = (string)($_GET['review_rating'] ?? 'all');
$reviewPage = max(1, (int)($_GET['review_page'] ?? 1));
$reviewPerPage = 20;
$reviewWhere = [];
if ($reviewSearch !== '') {
    $reviewTerm = mysqli_real_escape_string($conn, $reviewSearch);
    $reviewWhere[] = "(users.fullname LIKE '%{$reviewTerm}%' OR books.title LIKE '%{$reviewTerm}%' OR reviews.review LIKE '%{$reviewTerm}%')";
}
if (in_array($reviewRating, ['1','2','3','4','5'], true)) $reviewWhere[] = "reviews.rating=" . (int)$reviewRating;
else $reviewRating = 'all';
$reviewWhereSql = $reviewWhere ? ' WHERE ' . implode(' AND ', $reviewWhere) : '';
$reviewBaseSql = " FROM reviews JOIN users ON reviews.user_id = users.id JOIN books ON reviews.book_id = books.id";
$reviewTotal = (int)db_value($conn, "SELECT COUNT(*)" . $reviewBaseSql . $reviewWhereSql, '', [], 0);
$reviewPages = max(1, (int)ceil($reviewTotal / $reviewPerPage));
$reviewPage = min($reviewPage, $reviewPages);
$reviewOffset = ($reviewPage - 1) * $reviewPerPage;
$reviews = mysqli_query($conn, "SELECT reviews.*, users.fullname, books.title" . $reviewBaseSql . $reviewWhereSql . " ORDER BY reviews.id DESC LIMIT {$reviewPerPage} OFFSET {$reviewOffset}");
?>
<!DOCTYPE html>
<html>
<head>
    <title>Admin Dashboard</title>
    <link rel="stylesheet" href="style.css?v=8000">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <style>
        * { margin: 0; padding: 0; box-sizing: border-box; }
        body { font-family: 'Segoe UI', Arial, sans-serif; background: #f4f6f9; }
        .side-menu {
            position: fixed; left: 0; top: 0; width: 260px; height: 100%;
            background: linear-gradient(180deg, #0b1f3a 0%, #1a3a5c 100%);
            color: white; z-index: 100; overflow-y: auto;
            box-shadow: 2px 0 10px rgba(0,0,0,0.1);
        }
        .side-menu .logo { padding: 25px 20px; font-size: 22px; font-weight: bold; border-bottom: 1px solid rgba(255,255,255,0.1); text-align: center; }
        .side-menu a { display: flex; align-items: center; gap: 12px; padding: 14px 20px; color: white; text-decoration: none; border-left: 3px solid transparent; }
        .side-menu a:hover { background: rgba(255,255,255,0.1); border-left-color: #ffc72c; }
        .side-menu a.active { background: rgba(255,255,255,0.15); border-left-color: #ffc72c; }
        .main-content { margin-left: 260px; padding: 20px; }
        .top-bar { background: white; padding: 15px 25px; border-radius: 10px; margin-bottom: 20px; display: flex; justify-content: space-between; align-items: center; box-shadow: 0 2px 5px rgba(0,0,0,0.05); }
        .top-bar h2 { color: #0b1f3a; }
        .dark-btn { background: #0b1f3a; color: white; padding: 8px 15px; border: none; border-radius: 5px; cursor: pointer; }
        .stats-container { display: flex; gap: 20px; flex-wrap: wrap; margin-bottom: 30px; }
        .stat-card { flex: 1 1 190px; min-width: 0; }
        .stat-card h1 { overflow-wrap: anywhere; }
        .quick-action-grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(170px, 1fr)); gap: 12px; }
        .quick-action-grid a { display: flex; align-items: center; min-height: 48px; padding: 12px 14px; border-radius: 9px; background: #f1f5f9; color: #0b1f3a; text-decoration: none; font-weight: 600; border: 1px solid #e2e8f0; }
        .quick-action-grid a:hover, .quick-action-grid a:focus { background: #e3edf7; border-color: #8aa9c8; outline: 2px solid transparent; }
        body.dark-mode .quick-action-grid a { background: #202d46; color: #f5f7fb; border-color: #34435e; }
        body.dark-mode .quick-action-grid a:hover, body.dark-mode .quick-action-grid a:focus { background: #2b3b59; }

        .stat-card { background: white; padding: 20px; border-radius: 10px; width: 220px; box-shadow: 0 2px 5px rgba(0,0,0,0.05); }
        .stat-card h2 { color: #666; font-size: 14px; margin-bottom: 10px; }
        .stat-card h1 { color: #0b1f3a; font-size: 32px; }
        .table-section { background: white; padding: 20px; border-radius: 10px; margin-bottom: 20px; overflow-x: auto; box-shadow: 0 2px 5px rgba(0,0,0,0.05); }
        .table-section h2 { margin-bottom: 15px; color: #0b1f3a; }
        table { width: 100%; border-collapse: collapse; }
        th, td { border: 1px solid #ddd; padding: 10px; text-align: left; }
        th { background: #0b1f3a; color: white; }
        button, .btn { background: #0b1f3a; color: white; padding: 8px 15px; border: none; border-radius: 5px; cursor: pointer; }
        button.red, .btn-red { background: #dc3545; }
        button.green, .btn-green { background: #28a745; }
        input, textarea, select { padding: 10px; margin: 5px; border: 1px solid #ddd; border-radius: 5px; }
        .action-buttons { display: flex; gap: 5px; flex-wrap: wrap; }
        .dark-mode { background: #1a1a2e; color: white; }
        .dark-mode .stat-card, .dark-mode .table-section, .dark-mode .top-bar { background: #16213e; color: white; }
        .dark-mode .stat-card h2, .dark-mode .stat-card h1 { color: white; }
        .hamburger-mobile { display: none; }
        @media (max-width: 768px) {
            .side-menu { left: -260px; transition: 0.3s; }
            .side-menu.open { left: 0; }
            .main-content { margin-left: 0; }
            .hamburger-mobile { display: block; position: fixed; top: 15px; left: 15px; z-index: 101; background: #0b1f3a; padding: 10px; border-radius: 5px; cursor: pointer; color: white; font-size: 24px; }
        }
        .badge-admin { background: #ffc72c; color: #0b1f3a; padding: 2px 10px; border-radius: 20px; font-size: 11px; font-weight: bold; }

        /* Refined admin control centre */
        body { background:#eef2f7; color:#172338; }
        .side-menu { width:270px; background:linear-gradient(160deg,#10243d 0%,#173e5c 55%,#12645d 100%); padding-bottom:18px; }
        .side-menu .logo { text-align:left; padding:24px 22px; font-size:20px; }
        .side-menu a { margin:3px 12px; padding:11px 14px; border:0; border-radius:9px; font-size:14px; transition:background .18s,transform .18s; }
        .side-menu a:hover { background:rgba(255,255,255,.13); border:0; transform:translateX(2px); }
        .side-menu a.active { background:rgba(255,255,255,.19); border:0; box-shadow:inset 3px 0 #f5c84c; }
        .main-content { margin-left:270px; padding:26px clamp(14px,2.5vw,36px); max-width:1800px; }
        .top-bar { position:sticky; top:10px; z-index:50; padding:17px 22px; border:1px solid #e4eaf1; border-radius:14px; box-shadow:0 8px 24px rgba(20,40,70,.07); }
        .top-bar h2 { font-size:22px; text-transform:capitalize; }
        .dark-btn { border-radius:9px; padding:10px 16px; }
        .stats-container { display:grid; grid-template-columns:repeat(auto-fit,minmax(175px,1fr)); gap:15px; margin:18px 0 24px; }
        .stat-card { width:auto; min-height:126px; border:1px solid #e5ebf2; border-radius:14px; box-shadow:0 6px 20px rgba(20,40,70,.055); position:relative; overflow:hidden; }
        .stat-card:after { content:''; position:absolute; left:0; top:0; bottom:0; width:4px; background:#1b8175; }
        .stat-card h2 { font-size:12px; letter-spacing:.07em; text-transform:uppercase; }
        .stat-card h1 { font-size:30px; }
        .table-section { border:1px solid #e5ebf2; border-radius:14px; box-shadow:0 6px 22px rgba(20,40,70,.055); padding:22px; margin-bottom:20px; }
        .table-section h2 { font-size:19px; margin-bottom:16px; }
        table { min-width:650px; }
        th { background:#173b56; font-size:12px; text-transform:uppercase; letter-spacing:.04em; }
        td { font-size:14px; vertical-align:middle; }
        tr:nth-child(even) td { background:#f8fafc; }
        tr:hover td { background:#edf6f5; }
        button,.btn { border-radius:8px; font-weight:600; transition:filter .15s,transform .15s; }
        button:hover,.btn:hover { filter:brightness(1.08); transform:translateY(-1px); }
        input,textarea,select { max-width:100%; border-radius:8px; }
        .admin-table-search { width:min(100%,420px); margin:0 0 14px; padding:12px 14px; border:1px solid #d6e0e9; background:#fff; }
        .admin-date { color:#728096; font-size:13px; font-weight:500; }
        body.dark-mode { background:#0c1422; color:#e7edf5; }
        body.dark-mode .top-bar,body.dark-mode .stat-card,body.dark-mode .table-section { background:#152235; border-color:#263a53; color:#e7edf5; }
        body.dark-mode .top-bar h2,body.dark-mode .table-section h2,body.dark-mode .stat-card h1 { color:#f4f7fb; }
        body.dark-mode .stat-card h2 { color:#aebbd0; }
        body.dark-mode td { color:#e1e8f1; border-color:#31445c; }
        body.dark-mode tr:nth-child(even) td { background:#192b41; }
        body.dark-mode tr:hover td { background:#223b54; }
        body.dark-mode .admin-table-search,body.dark-mode input,body.dark-mode textarea,body.dark-mode select { background:#101c2d; color:#eef4fb; border-color:#344961; }
        @media(max-width:768px) {
            .side-menu { width:270px; left:-270px; }
            .side-menu.open { left:0; }
            .main-content { margin-left:0; padding:72px 12px 20px; }
            .top-bar { top:8px; padding:14px; }
            .top-bar h2 { font-size:18px; }
            .stats-container { grid-template-columns:repeat(2,minmax(0,1fr)); gap:10px; }
            .stat-card { padding:15px; min-height:105px; }
            .stat-card h1 { font-size:25px; }
            .table-section { padding:15px; }
        }
        @media(max-width:390px) { .stats-container { grid-template-columns:1fr; } }

        /* Dashboard overflow and responsive layout fix */
        html, body { max-width:100%; overflow-x:hidden; }
        .main-content {
            width:calc(100% - 270px);
            min-width:0;
            max-width:none;
            overflow-x:clip;
        }
        .stats-container {
            width:100%;
            min-width:0;
            grid-template-columns:repeat(auto-fit, minmax(min(100%, 220px), 1fr));
            align-items:stretch;
        }
        .stat-card { width:auto; min-width:0; }
        .stat-card h2 { overflow-wrap:anywhere; }
        .admin-quick-actions, .table-section { min-width:0; }
        .quick-action-grid {
            grid-template-columns:repeat(auto-fit, minmax(min(100%, 190px), 1fr));
        }
        @media (max-width:768px) {
            .main-content { width:100%; max-width:100%; }
            .stats-container { grid-template-columns:repeat(2, minmax(0, 1fr)); }
            .quick-action-grid { grid-template-columns:repeat(2, minmax(0, 1fr)); }
        }
        @media (max-width:390px) {
            .stats-container, .quick-action-grid { grid-template-columns:1fr; }
        }
        .admin-users-section { min-width:0; }
        .users-heading { display:flex; justify-content:space-between; align-items:center; gap:12px; margin-bottom:14px; }
        .users-heading h2 { margin-bottom:4px; }
        .users-summary { color:#718096; font-size:13px; }
        .users-filter-form { display:flex; flex-wrap:wrap; align-items:center; gap:9px; margin:14px 0 18px; }
        .users-filter-form input[type="search"] { flex:2 1 240px; min-width:180px; }
        .users-filter-form select { flex:1 1 150px; min-width:130px; }
        .users-filter-form input, .users-filter-form select { margin:0; min-height:40px; }
        .users-reset { color:#24527a; padding:9px 12px; text-decoration:none; font-weight:600; }
        .users-table-wrap { width:100%; overflow-x:auto; }
        .users-table { min-width:850px; }
        .users-table td { vertical-align:middle; }
        .user-status { display:inline-block; border-radius:20px; padding:4px 9px; font-size:12px; font-weight:700; white-space:nowrap; }
        .user-status.is-verified { color:#166534; background:#dcfce7; }
        .user-status.is-unverified { color:#92400e; background:#fef3c7; }
        .user-self-label { color:#64748b; font-size:12px; font-weight:700; }
        .users-empty { text-align:center; padding:28px; color:#64748b; }
        .users-pagination { display:flex; flex-wrap:wrap; gap:6px; margin-top:16px; }
        .users-pagination a { min-width:34px; padding:7px 10px; border-radius:7px; text-align:center; text-decoration:none; background:#edf2f7; color:#1e293b; }
        .users-pagination a.current { background:#0b1f3a; color:#fff; }
        body.dark-mode .users-summary { color:#b7c3d6; }
        body.dark-mode .users-reset { color:#9cc7ff; }
        body.dark-mode .users-pagination a { background:#263650; color:#f5f7fb; }
        body.dark-mode .users-pagination a.current { background:#4775aa; }
        @media(max-width:640px) {
            .users-filter-form { display:grid; grid-template-columns:1fr 1fr; }
            .users-filter-form input[type="search"] { grid-column:1 / -1; min-width:0; }
            .users-filter-form button { min-height:40px; }
        }
        .admin-messages-section { min-width:0; }
        .messages-overview { display:grid; grid-template-columns:repeat(auto-fit,minmax(145px,1fr)); gap:12px; margin-bottom:18px; }
        .message-stat { display:flex; flex-direction:column; gap:4px; padding:14px 16px; border:1px solid #dbe5e1; border-radius:10px; background:#f8fbfa; }
        .message-stat strong { font-size:24px; color:#0b1f3a; }
        .message-stat span { color:#64748b; font-size:13px; }
        .messages-filter-form { display:flex; flex-wrap:wrap; gap:10px; align-items:center; margin:12px 0 18px; }
        .messages-filter-form input[type="search"] { flex:2 1 250px; min-width:180px; }
        .messages-filter-form select { flex:1 1 160px; min-width:140px; }
        .messages-filter-form input,.messages-filter-form select { min-height:40px; margin:0; }
        .messages-reset { padding:9px 12px; text-decoration:none; font-weight:600; color:#24527a; }
        .messages-table-wrap { width:100%; overflow-x:auto; }
        .messages-table { min-width:1050px; }
        .messages-table th,.messages-table td { vertical-align:top; }
        .messages-table td { padding:12px; }
        .message-body-cell { max-width:280px; white-space:normal; overflow-wrap:anywhere; line-height:1.5; }
        .message-reply-cell { min-width:260px; }
        .message-reply-cell textarea { width:100%; min-height:78px; resize:vertical; }
        .message-status { display:inline-block; padding:5px 10px; border-radius:20px; font-size:12px; font-weight:700; white-space:nowrap; }
        .message-status.pending { background:#fff3cd; color:#8a5b00; }
        .message-status.replied { background:#dcfce7; color:#166534; }
        .message-actions { min-width:145px; display:flex; flex-direction:column; gap:8px; }
        .message-actions form { margin:0; }
        .message-actions button { width:100%; }
        .messages-empty { padding:28px; text-align:center; color:#64748b; }
        .messages-pagination { display:flex; flex-wrap:wrap; gap:6px; margin-top:16px; }
        .messages-pagination a { min-width:34px; padding:7px 10px; border-radius:7px; text-align:center; text-decoration:none; background:#edf2f7; color:#1e293b; }
        .messages-pagination a.current { background:#0b1f3a; color:#fff; }
        body.dark-mode .message-stat { background:#1e293b; border-color:#334155; }
        body.dark-mode .message-stat strong { color:#f8fafc; }
        body.dark-mode .message-stat span,body.dark-mode .messages-empty { color:#cbd5e1; }
        body.dark-mode .messages-reset { color:#9cc7ff; }
        body.dark-mode .messages-pagination a { background:#263650; color:#f5f7fb; }
        body.dark-mode .messages-pagination a.current { background:#4775aa; }
        @media(max-width:640px) {
            .messages-filter-form { display:grid; grid-template-columns:1fr 1fr; }
            .messages-filter-form input[type="search"] { grid-column:1 / -1; min-width:0; }
        }
        .admin-notifications-section,.admin-notification-history { min-width:0; }
        .notification-audience { display:flex; align-items:center; gap:13px; padding:15px 17px; margin-bottom:20px; border:1px solid #cfe4dc; border-radius:11px; background:#f0faf5; }
        .notification-audience-icon { font-size:25px; }
        .notification-audience strong { color:#14532d; font-size:16px; }
        .notification-audience p,.notification-help,.notification-history-heading p { color:#64748b; font-size:13px; margin-top:4px; }
        .notification-compose-form { display:flex; flex-direction:column; gap:9px; max-width:850px; margin-top:16px; }
        .notification-compose-form label { font-size:13px; font-weight:700; color:#334155; }
        .notification-compose-form input[type="text"],.notification-compose-form textarea { width:100%; margin:0; padding:11px 12px; border:1px solid #cbd5e1; border-radius:8px; font:inherit; }
        .notification-compose-form textarea { resize:vertical; min-height:130px; }
        .notification-compose-form .notification-confirm { display:flex; align-items:flex-start; gap:9px; font-weight:500; margin:5px 0; }
        .notification-compose-form .notification-confirm input { margin-top:3px; }
        .notification-compose-form button { align-self:flex-start; min-height:42px; padding:10px 18px; }
        .notification-history-heading { margin-bottom:14px; }
        .notification-search-form { display:flex; flex-wrap:wrap; gap:9px; align-items:center; margin:12px 0 16px; }
        .notification-search-form input[type="search"] { flex:1 1 260px; min-width:180px; margin:0; min-height:40px; }
        .notification-reset { padding:9px 12px; text-decoration:none; font-weight:600; color:#24527a; }
        .notification-table-wrap { width:100%; overflow-x:auto; }
        .notification-table { min-width:780px; }
        .notification-table td { vertical-align:top; }
        .notification-message-cell { max-width:480px; white-space:normal; overflow-wrap:anywhere; line-height:1.5; }
        .notification-empty { text-align:center; padding:26px; color:#64748b; }
        .notification-pagination { display:flex; flex-wrap:wrap; gap:6px; margin-top:15px; }
        .notification-pagination a { min-width:34px; padding:7px 10px; border-radius:7px; text-align:center; text-decoration:none; background:#edf2f7; color:#1e293b; }
        .notification-pagination a.current { background:#0b1f3a; color:#fff; }
        body.dark-mode .notification-audience { background:#173326; border-color:#285b40; }
        body.dark-mode .notification-audience strong { color:#bbf7d0; }
        body.dark-mode .notification-audience p,body.dark-mode .notification-help,body.dark-mode .notification-history-heading p,body.dark-mode .notification-empty { color:#cbd5e1; }
        body.dark-mode .notification-compose-form label { color:#e2e8f0; }
        body.dark-mode .notification-reset { color:#9cc7ff; }
        body.dark-mode .notification-pagination a { background:#263650; color:#f5f7fb; }
        body.dark-mode .notification-pagination a.current { background:#4775aa; }
        @media(max-width:640px) {
            .notification-search-form { display:grid; grid-template-columns:1fr auto; }
            .notification-search-form input[type="search"] { grid-column:1 / -1; min-width:0; }
            .notification-compose-form button { width:100%; }
        }
        .admin-requests-section,.admin-reviews-section { min-width:0; }
        .request-stats { display:grid; grid-template-columns:repeat(auto-fit,minmax(130px,1fr)); gap:10px; margin-bottom:16px; }
        .request-stats>div,.review-summary { display:flex; flex-direction:column; gap:3px; padding:13px 15px; border:1px solid #dbe5e1; border-radius:9px; background:#f8fbfa; }
        .request-stats strong,.review-summary strong { font-size:23px; color:#0b1f3a; }
        .request-stats span,.review-summary span { color:#64748b; font-size:13px; }
        .request-filter-form,.review-filter-form { display:flex; flex-wrap:wrap; gap:9px; align-items:center; margin:14px 0 17px; }
        .request-filter-form input[type=search],.review-filter-form input[type=search] { flex:2 1 250px; min-width:180px; }
        .request-filter-form select,.review-filter-form select { flex:1 1 150px; min-width:130px; }
        .request-filter-form input,.request-filter-form select,.review-filter-form input,.review-filter-form select { margin:0; min-height:40px; }
        .admin-filter-reset { padding:9px 12px; text-decoration:none; font-weight:600; color:#24527a; }
        .admin-wide-table { width:100%; overflow-x:auto; }
        .admin-requests-table { min-width:900px; }
        .admin-reviews-table { min-width:850px; }
        .admin-wide-table th,.admin-wide-table td { vertical-align:top; }
        .admin-wide-table td { padding:11px; }
        .admin-wide-table select { min-width:120px; }
        .review-text-cell { max-width:440px; white-space:normal; overflow-wrap:anywhere; line-height:1.5; }
        .review-stars { white-space:nowrap; font-weight:700; }
        .admin-empty { padding:26px; text-align:center; color:#64748b; }
        .admin-pagination { display:flex; flex-wrap:wrap; gap:6px; margin-top:15px; }
        .admin-pagination a { min-width:34px; padding:7px 10px; border-radius:7px; text-align:center; text-decoration:none; background:#edf2f7; color:#1e293b; }
        .admin-pagination a.current { background:#0b1f3a; color:#fff; }
        body.dark-mode .request-stats>div,body.dark-mode .review-summary { background:#1e293b; border-color:#334155; }
        body.dark-mode .request-stats strong,body.dark-mode .review-summary strong { color:#f8fafc; }
        body.dark-mode .request-stats span,body.dark-mode .review-summary span,body.dark-mode .admin-empty { color:#cbd5e1; }
        body.dark-mode .admin-filter-reset { color:#9cc7ff; }
        body.dark-mode .admin-pagination a { background:#263650; color:#f5f7fb; }
        body.dark-mode .admin-pagination a.current { background:#4775aa; }
        @media(max-width:640px) {
            .request-filter-form,.review-filter-form { display:grid; grid-template-columns:1fr 1fr; }
            .request-filter-form input[type=search],.review-filter-form input[type=search] { grid-column:1 / -1; min-width:0; }
        }
        .admin-books-section { min-width:0; }
        .books-heading { display:flex; justify-content:space-between; align-items:center; gap:12px; margin-bottom:12px; }
        .books-summary { color:#64748b; font-size:13px; margin-top:4px; }
        .books-filter-form { display:flex; flex-wrap:wrap; align-items:center; gap:9px; margin:12px 0 16px; }
        .books-filter-form input[type=search] { flex:1 1 280px; min-width:180px; margin:0; min-height:40px; }
        .books-reset { padding:9px 12px; text-decoration:none; font-weight:600; color:#24527a; }
        .books-table-wrap { width:100%; overflow-x:auto; }
        .books-table { min-width:850px; }
        .books-table td { vertical-align:middle; }
        .books-empty { text-align:center; padding:26px; color:#64748b; }
        .books-pagination { display:flex; flex-wrap:wrap; gap:6px; margin-top:15px; }
        .books-pagination a { min-width:34px; padding:7px 10px; border-radius:7px; text-align:center; text-decoration:none; background:#edf2f7; color:#1e293b; }
        .books-pagination a.current { background:#0b1f3a; color:#fff; }
        body.dark-mode .books-summary,body.dark-mode .books-empty { color:#cbd5e1; }
        body.dark-mode .books-reset { color:#9cc7ff; }
        body.dark-mode .books-pagination a { background:#263650; color:#f5f7fb; }
        body.dark-mode .books-pagination a.current { background:#4775aa; }
        @media(max-width:640px) { .books-filter-form { display:grid; grid-template-columns:1fr auto; } .books-filter-form input[type=search] { grid-column:1/-1; min-width:0; } }
    </style>
</head>
<body>
<?php echo notification_markup(); ?>

<div class="hamburger-mobile" onclick="toggleMobileMenu()">☰</div>

<div class="side-menu" id="sideMenu">
    <div class="logo">📖 Admin Panel</div>
    <a href="?page=dashboard" class="<?php echo $page == 'dashboard' ? 'active' : ''; ?>">🏠 Dashboard</a>
    <a href="?page=website" class="<?php echo $page == 'website' ? 'active' : ''; ?>">🌐 Website</a>
    <a href="?page=all_books" class="<?php echo $page == 'all_books' ? 'active' : ''; ?>">📚 All Books</a>
    <a href="?page=add_book" class="<?php echo $page == 'add_book' ? 'active' : ''; ?>">➕ Add Book</a>
    <a href="upload_book.php">📤 Upload PDF</a>
    <a href="admin_resources.php">📑 Student materials <span class="resource-admin-badge" data-resource-count><?php echo $pendingMaterials; ?></span></a>
    <a href="?page=categories" class="<?php echo $page == 'categories' ? 'active' : ''; ?>">📂 Categories</a>
    <a href="?page=all_users" class="<?php echo $page == 'all_users' ? 'active' : ''; ?>">👥 All Users</a>
    <a href="?page=messages" class="<?php echo $page == 'messages' ? 'active' : ''; ?>">💬 Messages</a>
    <a href="?page=notifications" class="<?php echo $page == 'notifications' ? 'active' : ''; ?>">🔔 Notifications</a>
    <a href="?page=auto_import" class="<?php echo $page == 'auto_import' ? 'active' : ''; ?>">📥 Auto Import</a>
    <a href="?page=book_requests" class="<?php echo $page == 'book_requests' ? 'active' : ''; ?>">📝 Book Requests</a>
    <a href="?page=reviews" class="<?php echo $page == 'reviews' ? 'active' : ''; ?>">⭐ Reviews</a>
    <a href="admin_analytics.php">📊 Analytics</a>
    <a href="admin_audit_logs.php">🧾 Audit Log</a>
    <a href="admin_user_activity.php">👥 User Activity</a>
    <a href="admin_settings.php">⚙️ Settings</a>
    <a href="admin_backup.php">💾 Backup</a>
    <a href="performance.php">🚀 Performance</a>
    <a href="admin_change_password.php">🔑 Change Password</a>
    <a href="admin_logout.php">🚪 Logout</a>
</div>

<div class="main-content">
    <div class="top-bar">
        <div><h2><?php echo ucfirst(str_replace('_', ' ', $page)); ?></h2><span class="admin-date">Library control centre · <?php echo date('D, d M Y'); ?></span></div>
        <button onclick="toggleDarkMode()" class="dark-btn">🌙 Dark Mode</button>
    </div>

    <!-- ========== DASHBOARD PAGE ========== -->
    <p class="resource-callout" data-resource-notice role="status" <?php if (!$pendingMaterials) echo 'hidden'; ?>><a href="admin_resources.php">🔔 <span data-resource-count><?php echo $pendingMaterials; ?></span> student uploads awaiting your approval — Review materials</a></p>
    <link rel="stylesheet" href="resources.css?v=1">
    <script src="resource-admin.js?v=1" defer></script>
    <?php if($page == 'dashboard'): ?>
    <div class="stats-container">
        <div class="stat-card"><h2>Total Users</h2><h1><?php echo $total_users; ?></h1></div>
        <div class="stat-card"><h2>Total Books</h2><h1><?php echo $total_books; ?></h1></div>
        <div class="stat-card"><h2>Total Categories</h2><h1><?php echo $total_categories; ?></h1></div>
        <div class="stat-card"><h2>Total Reviews</h2><h1><?php echo $total_reviews; ?></h1></div>
        <div class="stat-card"><h2>Total Downloads</h2><h1><?php echo $total_downloads; ?></h1></div>
        <div class="stat-card"><h2>Verified Users</h2><h1><?php echo $verified_users; ?></h1></div>
        <div class="stat-card"><h2>Pending Book Requests</h2><h1><?php echo $pending_requests; ?></h1></div>
    </div>

    <div class="table-section admin-quick-actions">
        <h2>Quick Actions</h2>
        <div class="quick-action-grid">
            <a href="admin_dashboard.php?page=add_book">＋ Add a Book</a>
            <a href="admin_dashboard.php?page=all_books">📚 Manage Books</a>
            <a href="admin_dashboard.php?page=all_users">👥 Manage Users</a>
            <a href="admin_dashboard.php?page=book_requests">📝 Review Book Requests</a>
            <a href="admin_resources.php">📂 Student Materials</a>
            <a href="admin_analytics.php">📊 View Analytics</a>
        </div>
    </div>

    <div class="table-section">
        <h2>Most Downloaded Books</h2>
        <table>
            <tr><th>Book Title</th><th>Downloads</th></tr>
            <?php while($row = mysqli_fetch_assoc($most_downloaded)){ ?>
            <tr><td><?php echo htmlspecialchars($row['title']); ?></td><td><?php echo $row['download_count']; ?></td></tr>
            <?php } ?>
        </table>
    </div>

    <div class="table-section">
        <h2>Most Favorited Books</h2>
        <table>
            <tr><th>Book Title</th><th>Favorites</th></tr>
            <?php while($row = mysqli_fetch_assoc($most_favorites)){ ?>
            <tr><td><?php echo htmlspecialchars($row['title']); ?></td><td><?php echo $row['total']; ?></td></tr>
            <?php } ?>
        </table>
    </div>
    <?php endif; ?>

    <!-- ========== ADD BOOK PAGE ========== -->
    <?php if($page == 'add_book'): ?>
    <div class="table-section">
        <h2>➕ Add New Book</h2>
        <form method="POST" action="" enctype="multipart/form-data">
<?php echo csrf_field(); ?>
            <input type="text" name="title" placeholder="Book Title" required>
            <input type="text" name="author" placeholder="Author" required>
            <select name="category">
                <option value="">Select Category</option>
                <?php 
                $cats = mysqli_query($conn, "SELECT * FROM categories");
                while($cat = mysqli_fetch_assoc($cats)){ ?>
                    <option value="<?php echo htmlspecialchars($cat['name']); ?>"><?php echo htmlspecialchars($cat['name']); ?></option>
                <?php } ?>
            </select>
            <textarea name="description" placeholder="Description" rows="4"></textarea>
            <input type="text" name="cover_image_url" placeholder="Cover Image URL (optional)">
            <label for="admin-book-cover">Or upload this book's own cover (JPG, PNG or WebP; max 3 MB)</label>
            <input id="admin-book-cover" type="file" name="cover_file" accept="image/jpeg,image/png,image/webp">
            <input type="text" name="read_link" placeholder="Read Link" required>
            <input type="text" name="download_epub_link" placeholder="Download EPUB Link">
            <button type="submit" name="add_book" class="green">Add Book</button>
        </form>
    </div>
    <?php endif; ?>

    <!-- ========== CATEGORIES PAGE ========== -->
    <?php if($page == 'categories'): ?>
    <div class="table-section">
        <h2>📂 Manage Categories</h2>
        <form method="POST" action="" style="display:flex;gap:10px;flex-wrap:wrap;margin-bottom:20px;">
<?php echo csrf_field(); ?>
            <input type="text" name="category" placeholder="Category Name" required style="flex:1;min-width:200px;">
            <button type="submit" name="add_category" class="green">Add Category</button>
        </form>
        <table>
            <tr><th>ID</th><th>Category Name</th><th>Action</th></tr>
            <?php while($cat = mysqli_fetch_assoc($categories)){ ?>
            <tr>
                <td><?php echo $cat['id']; ?></td>
                <td><?php echo htmlspecialchars($cat['name']); ?></td>
                <td><form method="POST" action="?page=categories" style="display:inline;" onsubmit="return confirm('Delete this category?')">
<?php echo csrf_field(); ?><input type="hidden" name="delete_category" value="<?php echo (int)$cat['id']; ?>"><button type="submit" class="red">Delete</button></form></td>
            </tr>
            <?php } ?>
        </table>
    </div>
    <?php endif; ?>

    <!-- ========== WEBSITE PAGE ========== -->
    <?php if($page == 'website'): ?>
    <div class="table-section">
        <h2>🌐 Website</h2>
        <p>Website is live at: <a href="index.php" target="_blank">index.php</a></p>
        <button onclick="location.href='index.php'">Go to Website</button>
    </div>
    <?php endif; ?>

    <!-- ========== ALL BOOKS PAGE ========== -->
    <?php if($page == 'all_books'): ?>
    <div class="table-section admin-books-section">
        <div class="books-heading"><div><h2>📚 All Books (<?php echo number_format($bookTotal); ?>)</h2><p class="books-summary">Showing <?php echo $bookTotal ? ($bookOffset + 1) : 0; ?>–<?php echo min($bookOffset + $bookPerPage, $bookTotal); ?> of <?php echo number_format($bookTotal); ?> books</p></div></div>
        <form method="GET" action="admin_dashboard.php" class="books-filter-form">
            <input type="hidden" name="page" value="all_books">
            <input type="search" name="book_search" value="<?php echo e($bookSearch); ?>" placeholder="Search title, author or category..." aria-label="Search books">
            <button type="submit" class="green">Search</button>
            <a href="admin_dashboard.php?page=all_books" class="books-reset">Reset</a>
        </form>
        <div class="books-table-wrap"><table class="books-table">
            <thead><tr><th>ID</th><th>Cover</th><th>Title</th><th>Author</th><th>Category</th><th>Downloads</th><th>Action</th></tr></thead>
            <tbody>
            <?php if ($books && mysqli_num_rows($books) > 0): while($row = mysqli_fetch_assoc($books)){ ?>
            <tr>
                <td><?php echo (int)$row['id']; ?></td>
                <?php $adminCover = safe_url($row['cover_image_url'] ?? '') ?: (safe_url($row['cover_file'] ?? '') ?: safe_url($row['image'] ?? '')); ?>
                <td><?php if ($adminCover !== ''): ?><img src="<?php echo e($adminCover); ?>" width="50" height="65" loading="lazy" alt="Book cover" onerror="this.replaceWith(document.createTextNode('📚'))" style="border-radius:5px;object-fit:cover;"><?php else: ?>📚<?php endif; ?></td>
                <td><?php echo e($row['title'] ?? ''); ?></td><td><?php echo e($row['author'] ?? ''); ?></td><td><?php echo e($row['category'] ?? 'N/A'); ?></td><td><?php echo (int)($row['download_count'] ?? 0); ?></td>
                <td><form method="POST" action="?page=all_books" style="display:inline;" onsubmit="return confirm('Delete this book and its associated uploaded files?')">
                    <?php echo csrf_field(); ?><input type="hidden" name="delete_book" value="<?php echo (int)$row['id']; ?>"><button type="submit" class="red">Delete</button>
                </form></td>
            </tr>
            <?php } else: ?><tr><td colspan="7" class="books-empty">No books match your search.</td></tr><?php endif; ?>
            </tbody>
        </table></div>
        <?php if ($bookPages > 1): ?><nav class="books-pagination" aria-label="Book pages">
            <?php for($p=1;$p<=$bookPages;$p++): ?><a class="<?php echo $p===$bookPage?'current':''; ?>" href="?page=all_books&amp;book_search=<?php echo rawurlencode($bookSearch); ?>&amp;book_page=<?php echo $p; ?>"><?php echo $p; ?></a><?php endfor; ?>
        </nav><?php endif; ?>
    </div>
    <?php endif; ?>

    <!-- ========== ALL USERS PAGE ========== -->
    <?php if($page == 'all_users'): ?>
    <div class="table-section admin-users-section">
        <div class="users-heading">
            <div><h2>👥 All Users</h2><p class="users-summary">Showing <?php echo $userTotal ? ($userOffset + 1) : 0; ?>–<?php echo min($userOffset + $userPerPage, $userTotal); ?> of <?php echo $userTotal; ?> users</p></div>
        </div>
        <form method="GET" action="admin_dashboard.php" class="users-filter-form">
            <input type="hidden" name="page" value="all_users">
            <input type="search" name="user_search" value="<?php echo e($userSearch); ?>" placeholder="Search name, email or user ID..." aria-label="Search users">
            <select name="user_role" aria-label="Filter by role">
                <option value="all" <?php echo $userRole === 'all' ? 'selected' : ''; ?>>All roles</option>
                <option value="admin" <?php echo $userRole === 'admin' ? 'selected' : ''; ?>>Admins</option>
                <option value="user" <?php echo $userRole === 'user' ? 'selected' : ''; ?>>Users</option>
            </select>
            <select name="user_verified" aria-label="Filter by verification">
                <option value="all" <?php echo $userVerified === 'all' ? 'selected' : ''; ?>>All verification</option>
                <option value="verified" <?php echo $userVerified === 'verified' ? 'selected' : ''; ?>>Verified</option>
                <option value="unverified" <?php echo $userVerified === 'unverified' ? 'selected' : ''; ?>>Unverified</option>
            </select>
            <button type="submit" class="green">Search / Filter</button>
            <a class="users-reset" href="admin_dashboard.php?page=all_users">Reset</a>
        </form>
        <div class="users-table-wrap">
        <table class="users-table">
            <thead><tr><th>ID</th><th>Profile</th><th>Full Name</th><th>Email</th><th>Role</th><th>Verification</th><th>Actions</th></tr></thead>
            <tbody>
            <?php if ($all_users && mysqli_num_rows($all_users) > 0): while($user = mysqli_fetch_assoc($all_users)){ ?>
            <tr>
                <td><?php echo (int)$user['id']; ?></td>
                <td><?php echo !empty($user['profile_image']) ? '<img src="'.e($user['profile_image']).'" width="40" height="40" alt="Profile" onerror="this.replaceWith(document.createTextNode(\'👤\'))" style="border-radius:50%;object-fit:cover;">' : '👤'; ?></td>
                <td><?php echo e($user['fullname'] ?? ''); ?></td>
                <td><?php echo e($user['email'] ?? ''); ?></td>
                <td><?php if(isset($user['is_admin']) && (int)$user['is_admin'] === 1){ ?><span class="badge-admin">👑 Admin</span><?php } else { ?>User<?php } ?></td>
                <td><?php if(isset($user['is_verified']) && (int)$user['is_verified'] === 1){ ?><span class="user-status is-verified">Verified</span><?php } else { ?><span class="user-status is-unverified">Unverified</span><?php } ?></td>
                <td class="action-buttons">
                    <?php if((int)$user['id'] === $me){ ?><span class="user-self-label">You</span><?php } elseif(isset($user['is_admin']) && (int)$user['is_admin'] === 1){ ?>
                        <form method="POST" action="?page=all_users" style="display:inline;" onsubmit="return confirm('Remove admin rights from this user?')">
                        <?php echo csrf_field(); ?><input type="hidden" name="remove_admin" value="<?php echo (int)$user['id']; ?>"><button type="submit" style="background:#ffc72c;color:#0b1f3a;">Remove Admin</button></form>
                    <?php } else { ?>
                        <form method="POST" action="?page=all_users" style="display:inline;" onsubmit="return confirm('Give this user admin rights?')">
                        <?php echo csrf_field(); ?><input type="hidden" name="make_admin" value="<?php echo (int)$user['id']; ?>"><button type="submit" class="green">Make Admin</button></form>
                    <?php } ?>
                    <?php if((int)$user['id'] !== $me){ ?><form method="POST" action="?page=all_users" style="display:inline;" onsubmit="return confirm('Permanently delete this user? This cannot be undone.')">
                    <?php echo csrf_field(); ?><input type="hidden" name="delete_user" value="<?php echo (int)$user['id']; ?>"><button type="submit" class="red">Delete</button></form><?php } ?>
                </td>
            </tr>
            <?php } else: ?><tr><td colspan="7" class="users-empty">No users match these filters.</td></tr><?php endif; ?>
            </tbody>
        </table>
        </div>
        <?php if ($userPages > 1): ?><nav class="users-pagination" aria-label="User pages">
            <?php for($p = 1; $p <= $userPages; $p++): ?><a class="<?php echo $p === $userPage ? 'current' : ''; ?>" href="?page=all_users&amp;user_search=<?php echo rawurlencode($userSearch); ?>&amp;user_role=<?php echo e($userRole); ?>&amp;user_verified=<?php echo e($userVerified); ?>&amp;user_page=<?php echo $p; ?>"><?php echo $p; ?></a><?php endfor; ?>
        </nav><?php endif; ?>
    </div>
    <?php endif; ?>

    <!-- ========== MESSAGES PAGE ========== -->
    <?php if($page == 'messages'): ?>
    <div class="table-section admin-messages-section">
        <div class="messages-overview">
            <div class="message-stat"><strong><?php echo $messageTotal; ?></strong><span>Matching messages</span></div>
            <div class="message-stat"><strong><?php echo $messagePending; ?></strong><span>Pending replies</span></div>
            <div class="message-stat"><strong><?php echo $messageReplied; ?></strong><span>Replied</span></div>
        </div>
        <form method="GET" action="admin_dashboard.php" class="messages-filter-form">
            <input type="hidden" name="page" value="messages">
            <input type="search" name="message_search" value="<?php echo e($messageSearch); ?>" placeholder="Search name, email or message..." aria-label="Search messages">
            <select name="message_status" aria-label="Filter message status">
                <option value="all" <?php echo $messageStatus === 'all' ? 'selected' : ''; ?>>All messages</option>
                <option value="pending" <?php echo $messageStatus === 'pending' ? 'selected' : ''; ?>>Pending</option>
                <option value="replied" <?php echo $messageStatus === 'replied' ? 'selected' : ''; ?>>Replied</option>
            </select>
            <button type="submit" class="green">Search / Filter</button>
            <a href="admin_dashboard.php?page=messages" class="messages-reset">Reset</a>
        </form>
        <div class="messages-table-wrap">
        <table class="messages-table">
            <thead><tr><th>ID</th><th>Sender</th><th>Message</th><th>Admin Reply</th><th>Status</th><th>Actions</th></tr></thead>
            <tbody>
            <?php if ($messages && mysqli_num_rows($messages) > 0): while($row = mysqli_fetch_assoc($messages)){ ?>
                <tr>
                    <td><?php echo (int)$row['id']; ?></td>
                    <td><strong><?php echo e($row['name'] ?? ''); ?></strong><br><a href="mailto:<?php echo e($row['email'] ?? ''); ?>"><?php echo e($row['email'] ?? ''); ?></a></td>
                    <td class="message-body-cell"><?php echo nl2br(e($row['message'] ?? '')); ?></td>
                    <td class="message-reply-cell">
                        <form id="message-<?php echo (int)$row['id']; ?>" method="POST" action="admin_dashboard.php?page=messages">
                            <?php echo csrf_field(); ?>
                            <input type="hidden" name="message_id" value="<?php echo (int)$row['id']; ?>">
                            <textarea name="reply" rows="3" maxlength="5000" placeholder="Write a helpful reply..." required><?php echo e($row['reply'] ?? ''); ?></textarea>
                        </form>
                    </td>
                    <td><?php if (trim((string)($row['reply'] ?? '')) !== ''): ?><span class="message-status replied">Replied</span><?php else: ?><span class="message-status pending">Pending</span><?php endif; ?></td>
                    <td class="message-actions">
                        <button type="submit" form="message-<?php echo (int)$row['id']; ?>" name="reply_submit" value="1" class="green">Reply / Update</button>
                        <form method="POST" action="admin_dashboard.php?page=messages" onsubmit="return confirm('Permanently delete this message?')">
                            <?php echo csrf_field(); ?>
                            <input type="hidden" name="delete_message" value="<?php echo (int)$row['id']; ?>">
                            <button type="submit" class="red">Delete</button>
                        </form>
                    </td>
                </tr>
            <?php } else: ?><tr><td colspan="6" class="messages-empty">No messages found. Try changing your search or filter.</td></tr><?php endif; ?>
            </tbody>
        </table>
        </div>
        <?php if ($messagePages > 1): ?><nav class="messages-pagination" aria-label="Message pages">
            <?php for($p = 1; $p <= $messagePages; $p++): ?><a class="<?php echo $p === $messagePage ? 'current' : ''; ?>" href="?page=messages&amp;message_search=<?php echo rawurlencode($messageSearch); ?>&amp;message_status=<?php echo e($messageStatus); ?>&amp;message_page=<?php echo $p; ?>"><?php echo $p; ?></a><?php endfor; ?>
        </nav><?php endif; ?>
    </div>
    <?php endif; ?>

    <!-- ========== NOTIFICATIONS PAGE ========== -->
    <?php if($page == 'notifications'): ?>
    <div class="table-section admin-notifications-section">
        <div class="notification-audience">
            <span class="notification-audience-icon">👥</span>
            <div><strong><?php echo number_format($verifiedRecipientCount); ?> verified email recipients</strong><p>Only verified users with an email address receive the email broadcast.</p></div>
        </div>
        <h2>🔔 Send Notification</h2>
        <p class="notification-help">The notification is saved in the library and emailed to the verified users counted above.</p>
        <form method="POST" action="admin_dashboard.php?page=notifications" class="notification-compose-form" onsubmit="return confirm('Send this notification by email to <?php echo (int)$verifiedRecipientCount; ?> verified users? This action cannot be undone.');">
            <?php echo csrf_field(); ?>
            <label for="notification-title">Notification title</label>
            <input id="notification-title" type="text" name="title" maxlength="150" placeholder="e.g. New books have been added" required>
            <label for="notification-message">Message</label>
            <textarea id="notification-message" name="message" maxlength="5000" rows="6" placeholder="Write a clear message for your library users..." required></textarea>
            <label class="notification-confirm"><input type="checkbox" name="confirm_broadcast" value="yes" required> I understand this will email all verified users.</label>
            <button type="submit" name="send_notification" value="1" class="green">📢 Send notification to verified users</button>
        </form>
    </div>
    <div class="table-section admin-notification-history">
        <div class="notification-history-heading"><div><h2>📋 Notification History</h2><p><?php echo number_format($notificationTotal); ?> saved notifications</p></div></div>
        <form method="GET" action="admin_dashboard.php" class="notification-search-form">
            <input type="hidden" name="page" value="notifications">
            <input type="search" name="notification_search" value="<?php echo e($notificationSearch); ?>" placeholder="Search notification title or message..." aria-label="Search notification history">
            <button type="submit" class="green">Search</button>
            <a href="admin_dashboard.php?page=notifications" class="notification-reset">Reset</a>
        </form>
        <div class="notification-table-wrap">
        <table class="notification-table">
            <thead><tr><th>ID</th><th>Title</th><th>Message</th><th>Date</th></tr></thead>
            <tbody>
            <?php if ($notifications && mysqli_num_rows($notifications) > 0): while($notif = mysqli_fetch_assoc($notifications)){ ?>
            <tr><td><?php echo (int)$notif['id']; ?></td><td><strong><?php echo e($notif['title'] ?? ''); ?></strong></td><td class="notification-message-cell"><?php echo nl2br(e($notif['message'] ?? '')); ?></td><td><?php echo e($notif['created_at'] ?? ''); ?></td></tr>
            <?php } else: ?><tr><td colspan="4" class="notification-empty">No notifications found.</td></tr><?php endif; ?>
            </tbody>
        </table>
        </div>
        <?php if ($notificationPages > 1): ?><nav class="notification-pagination" aria-label="Notification history pages">
            <?php for($p=1; $p<=$notificationPages; $p++): ?><a class="<?php echo $p === $notificationPage ? 'current' : ''; ?>" href="?page=notifications&amp;notification_search=<?php echo rawurlencode($notificationSearch); ?>&amp;notification_page=<?php echo $p; ?>"><?php echo $p; ?></a><?php endfor; ?>
        </nav><?php endif; ?>
    </div>
    <?php endif; ?>

    <!-- ========== AUTO IMPORT PAGE ========== -->
    <?php if($page == 'auto_import'): ?>
    <div class="table-section">
        <h2>📥 Auto Import Books</h2>
        <a href="auto_scrape_books.php"><button class="green">🚀 Auto Import Books</button></a>
    </div>
    <?php endif; ?>

    <!-- ========== BOOK REQUESTS PAGE ========== -->
    <?php if($page == 'book_requests'): ?>
    <div class="table-section admin-requests-section">
        <div class="request-stats">
            <div><strong><?php echo number_format($requestTotal); ?></strong><span>Matching requests</span></div>
            <div><strong><?php echo number_format($requestPending); ?></strong><span>Pending</span></div>
            <div><strong><?php echo number_format($requestApproved); ?></strong><span>Approved</span></div>
            <div><strong><?php echo number_format($requestAdded); ?></strong><span>Added</span></div>
        </div>
        <form method="GET" action="admin_dashboard.php" class="request-filter-form">
            <input type="hidden" name="page" value="book_requests">
            <input type="search" name="request_search" value="<?php echo e($requestSearch); ?>" placeholder="Search user, email, book or category...">
            <select name="request_status">
                <option value="all" <?php echo $requestStatus==='all'?'selected':''; ?>>All statuses</option>
                <?php foreach($allowedRequestStatuses as $st): ?><option value="<?php echo e($st); ?>" <?php echo $requestStatus===$st?'selected':''; ?>><?php echo e($st); ?></option><?php endforeach; ?>
            </select>
            <button type="submit" class="green">Search / Filter</button>
            <a href="admin_dashboard.php?page=book_requests" class="admin-filter-reset">Reset</a>
        </form>
        <div class="admin-wide-table"><table class="admin-requests-table">
            <thead><tr><th>ID</th><th>User</th><th>Email</th><th>Book</th><th>Category</th><th>Status</th><th>Action</th></tr></thead>
            <tbody>
            <?php if($book_requests && mysqli_num_rows($book_requests)>0): while($req = mysqli_fetch_assoc($book_requests)){ ?>
                <tr>
                    <td><?php echo (int)$req['id']; ?></td><td><?php echo e($req['fullname'] ?? ''); ?></td><td><?php echo e($req['email'] ?? ''); ?></td>
                    <td><?php echo e($req['book_name'] ?? ''); ?></td><td><?php echo e($req['category'] ?? ''); ?></td>
                    <td><form id="request-<?php echo (int)$req['id']; ?>" method="POST" action="update_request_status.php">
                        <?php echo csrf_field(); ?><input type="hidden" name="request_id" value="<?php echo (int)$req['id']; ?>">
                        <select name="status" aria-label="Request status">
                            <?php foreach($allowedRequestStatuses as $st): ?><option value="<?php echo e($st); ?>" <?php echo ($req['status'] ?? '')===$st?'selected':''; ?>><?php echo e($st); ?></option><?php endforeach; ?>
                        </select>
                    </form></td>
                    <td><button type="submit" form="request-<?php echo (int)$req['id']; ?>" name="update_status" value="1" class="green">Update</button></td>
                </tr>
            <?php } else: ?><tr><td colspan="7" class="admin-empty">No book requests match your filters.</td></tr><?php endif; ?>
            </tbody>
        </table></div>
        <?php if($requestPages>1): ?><nav class="admin-pagination" aria-label="Request pages"><?php for($p=1;$p<=$requestPages;$p++): ?><a class="<?php echo $p===$requestPage?'current':''; ?>" href="?page=book_requests&amp;request_search=<?php echo rawurlencode($requestSearch); ?>&amp;request_status=<?php echo e($requestStatus); ?>&amp;request_page=<?php echo $p; ?>"><?php echo $p; ?></a><?php endfor; ?></nav><?php endif; ?>
    </div>
    <?php endif; ?>

    <!-- ========== REVIEWS PAGE ========== -->
    <?php if($page == 'reviews'): ?>
    <div class="table-section admin-reviews-section">
        <div class="review-summary"><strong><?php echo number_format($reviewTotal); ?></strong><span>Matching reviews</span></div>
        <form method="GET" action="admin_dashboard.php" class="review-filter-form">
            <input type="hidden" name="page" value="reviews">
            <input type="search" name="review_search" value="<?php echo e($reviewSearch); ?>" placeholder="Search user, book or review text...">
            <select name="review_rating" aria-label="Filter by star rating">
                <option value="all" <?php echo $reviewRating==='all'?'selected':''; ?>>All ratings</option>
                <?php for($star=5;$star>=1;$star--): ?><option value="<?php echo $star; ?>" <?php echo $reviewRating===(string)$star?'selected':''; ?>><?php echo $star; ?> stars</option><?php endfor; ?>
            </select>
            <button type="submit" class="green">Search / Filter</button>
            <a href="admin_dashboard.php?page=reviews" class="admin-filter-reset">Reset</a>
        </form>
        <div class="admin-wide-table"><table class="admin-reviews-table">
            <thead><tr><th>ID</th><th>User</th><th>Book</th><th>Rating</th><th>Review</th><th>Action</th></tr></thead>
            <tbody>
            <?php if($reviews && mysqli_num_rows($reviews)>0): while($review = mysqli_fetch_assoc($reviews)){ ?>
            <tr>
                <td><?php echo (int)$review['id']; ?></td><td><?php echo e($review['fullname'] ?? ''); ?></td><td><?php echo e($review['title'] ?? ''); ?></td>
                <td><span class="review-stars"><?php echo (int)$review['rating']; ?> / 5 ⭐</span></td><td class="review-text-cell"><?php echo nl2br(e($review['review'] ?? '')); ?></td>
                <td><form method="POST" action="delete_review.php" onsubmit="return confirm('Delete this review permanently?')">
                    <?php echo csrf_field(); ?><input type="hidden" name="id" value="<?php echo (int)$review['id']; ?>"><button type="submit" class="red">Delete</button>
                </form></td>
            </tr>
            <?php } else: ?><tr><td colspan="6" class="admin-empty">No reviews match your filters.</td></tr><?php endif; ?>
            </tbody>
        </table></div>
        <?php if($reviewPages>1): ?><nav class="admin-pagination" aria-label="Review pages"><?php for($p=1;$p<=$reviewPages;$p++): ?><a class="<?php echo $p===$reviewPage?'current':''; ?>" href="?page=reviews&amp;review_search=<?php echo rawurlencode($reviewSearch); ?>&amp;review_rating=<?php echo e($reviewRating); ?>&amp;review_page=<?php echo $p; ?>"><?php echo $p; ?></a><?php endfor; ?></nav><?php endif; ?>
    </div>
    <?php endif; ?>

</div>

<script>
document.addEventListener('DOMContentLoaded', function(){
    document.querySelectorAll('.main-content .table-section table').forEach(function(table){
        if (table.rows.length < 3) return;
        const search = document.createElement('input');
        search.type = 'search';
        search.className = 'admin-table-search';
        search.placeholder = 'Search this table...';
        search.setAttribute('aria-label', 'Search table rows');
        table.parentNode.insertBefore(search, table);
        search.addEventListener('input', function(){
            const q = this.value.toLocaleLowerCase().trim();
            const rows = table.tBodies[0] ? Array.from(table.tBodies[0].rows) : Array.from(table.rows);
            rows.forEach(function(row){
                if (row.querySelector('th')) return;
                row.hidden = q !== '' && !row.textContent.toLocaleLowerCase().includes(q);
            });
        });
    });
});
function toggleMobileMenu() {
    document.getElementById('sideMenu').classList.toggle('open');
}

if(localStorage.getItem("theme") === "dark"){
    document.body.classList.add("dark-mode");
}

function toggleDarkMode(){
    document.body.classList.toggle("dark-mode");
    if(document.body.classList.contains("dark-mode")){
        localStorage.setItem("theme", "dark");
    } else {
        localStorage.setItem("theme", "light");
    }
}
</script>

</body>
</html>
