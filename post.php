<?php
session_start();
// --- FIX: FORCE NO CACHE (Ensures comments show instantly) ---
header("Cache-Control: no-cache, no-store, must-revalidate");
header("Pragma: no-cache");
header("Expires: 0");

require 'db_config.php'; // Required for Database & Theme Loading
require 'bbcode.php';    // Required for formatting

// Authenticate: Allow Guests if present, otherwise redirect
if (!isset($_SESSION['fully_authenticated']) && (!isset($_SESSION['is_guest']) || !$_SESSION['is_guest'])) { 
    header("Location: login.php"); exit; 
}

// Guest Config: Assign Negative ID & Rank 0
if (isset($_SESSION['is_guest']) && $_SESSION['is_guest']) {
    // FORCE Guest Mode: Calculate negative ID from Token
    $my_id = -1 * abs($_SESSION['guest_token_id'] ?? 0);
    $my_rank = 0;
} else {
    // Standard User Mode
    $my_id = $_SESSION['user_id'] ?? 0;
    $my_rank = $_SESSION['rank'] ?? 1;
}

// Inject ID into session for compatibility with existing SQL queries below
$_SESSION['user_id'] = $my_id;

// Fetch Post
$id = $_GET['id'] ?? 0;

// Update Views (Session Locked to prevent spam)
if (!isset($_SESSION['viewed_posts'])) $_SESSION['viewed_posts'] = [];

// [FIX] Ensure we track views, but also allow basic refresh counting if session is weird
if (!in_array($id, $_SESSION['viewed_posts'])) {
    try { 
        $pdo->prepare("UPDATE posts SET views = views + 1 WHERE id = ?")->execute([$id]); 
        $_SESSION['viewed_posts'][] = $id;
    } catch(Exception $e) {
        // Fallback if column is weirdly null (should be fixed by schema, but just in case)
        $pdo->prepare("UPDATE posts SET views = 1 WHERE id = ? AND views IS NULL")->execute([$id]);
    }
}

$stmt = $pdo->prepare("SELECT p.*, u.username FROM posts p JOIN users u ON p.user_id = u.id WHERE p.id = ?");
$stmt->execute([$id]);
$post = $stmt->fetch();

if (!$post) die("TRANSMISSION NOT FOUND.");

// Check Rank Clearance - STYLED ERROR PAGE
if ($post['min_rank'] > $my_rank) {
    ?>
    <!DOCTYPE html>
    <html><head><title>Restricted</title><link rel="stylesheet" href="style.css"></head>
    <body style="background:#000;">
    <div class="login-wrapper">
        <div class="terminal-header"><span class="term-title">SYSTEM_ALERT</span></div>
        <div style="padding:40px; text-align:center;">
            <h1 style="color:#e06c75; font-size:1.5rem; margin-top:0;">ACCESS DENIED</h1>
            <p style="color:#666; font-family:monospace; margin-bottom:30px;">
                INSUFFICIENT CLEARANCE.<br>
                LEVEL <?= $post['min_rank'] ?> SECURITY REQUIRED.
            </p>
            <a href="index.php" class="btn-primary" style="display:inline-block; width:auto; text-decoration:none;">RETURN TO FEED</a>
        </div>
    </div>
    </body></html>
    <?php
    exit;
}

// --- LOGIC MOVED TO TOP (FIXES REDIRECT) ---

// 0. Fetch Settings (For OP Moderation)
$stmt_s = $pdo->query("SELECT setting_value FROM settings WHERE setting_key = 'allow_op_mod'");
$op_mod_enabled = ($stmt_s->fetchColumn() === '1');

// 1. Handle Deletion (With Anti-Cache Redirect)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['delete_comment'])) {
    $del_id = (int)$_POST['delete_comment'];
    
    // Check ownership
    $stmt_chk = $pdo->prepare("SELECT user_id FROM post_comments WHERE id = ?");
    $stmt_chk->execute([$del_id]);
    $c_owner = $stmt_chk->fetchColumn(); // Returns ID (mixed) or false
    
    $is_admin = ($_SESSION['rank'] ?? 0) >= 9;
    // Fix: Check for row existence (!== false) allows ID 0 or Negative IDs to be deleted
    $is_author = ($c_owner !== false && $c_owner == $_SESSION['user_id']);
    $is_op = ($post['user_id'] == $_SESSION['user_id'] && $op_mod_enabled);

    if (($c_owner !== false) && ($is_admin || $is_author || $is_op)) {
        // --- CASCADING THREAD DELETE ---
        // 1. Find all IDs in this thread (Parent + Children + Grandchildren)
        $ids_to_nuke = [$del_id];
        $pointer = 0;
        
        // Loop through and collect children recursively
        while($pointer < count($ids_to_nuke)) {
            $curr = $ids_to_nuke[$pointer];
            $stmt_kids = $pdo->prepare("SELECT id FROM post_comments WHERE parent_id = ?");
            $stmt_kids->execute([$curr]);
            $children = $stmt_kids->fetchAll(PDO::FETCH_COLUMN);
            foreach($children as $child) {
                $ids_to_nuke[] = $child;
            }
            $pointer++;
        }

        // 2. Delete All Collected IDs
        $in_str = implode(',', array_fill(0, count($ids_to_nuke), '?'));
        
        // Delete Comments
        $pdo->prepare("DELETE FROM post_comments WHERE id IN ($in_str)")->execute($ids_to_nuke);
        // Delete Votes attached to them (Cleanup)
        $pdo->prepare("DELETE FROM votes WHERE target_type='comment' AND target_id IN ($in_str)")->execute($ids_to_nuke);

        $uid = uniqid(); 
        http_response_code(303); 
        header("Location: post.php?id=$id&r=$uid#comments"); exit; 
    }
}

// 2. Handle New Comment (With Anti-Cache Redirect)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['post_comment'])) {
    $c_body = trim($_POST['comment_body']);
    $parent = !empty($_POST['parent_id']) ? (int)$_POST['parent_id'] : null;
    
    if ($c_body) {
        $stmt = $pdo->prepare("INSERT INTO post_comments (post_id, user_id, parent_id, body) VALUES (?, ?, ?, ?)");
        $stmt->execute([$id, $my_id, $parent, $c_body]);
        $uid = uniqid(); 
        http_response_code(303); 
        header("Location: post.php?id=$id&r=$uid#comments"); exit;
    }
}

// 3. Handle Voting (Toggle Logic)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['cast_vote'])) {
    $type = $_POST['vote_type']; // 'post' or 'comment'
    $tid = (int)$_POST['vote_id'];
    $val = (int)$_POST['vote_val']; // 1 or -1
    
    // Check existing vote
    $stmt = $pdo->prepare("SELECT vote_val FROM votes WHERE user_id=? AND target_type=? AND target_id=?");
    $stmt->execute([$_SESSION['user_id'], $type, $tid]);
    $existing = $stmt->fetchColumn();

    if ($existing) {
        // If clicking same button -> Delete (Toggle Off)
        // If clicking diff button -> Update
        if ($existing == $val) {
            $pdo->prepare("DELETE FROM votes WHERE user_id=? AND target_type=? AND target_id=?")->execute([$_SESSION['user_id'], $type, $tid]);
        } else {
            $pdo->prepare("UPDATE votes SET vote_val=? WHERE user_id=? AND target_type=? AND target_id=?")->execute([$val, $_SESSION['user_id'], $type, $tid]);
        }
    } else {
        // Insert new
        $pdo->prepare("INSERT INTO votes (user_id, target_type, target_id, vote_val) VALUES (?, ?, ?, ?)")->execute([$_SESSION['user_id'], $type, $tid, $val]);
    }
    
    $uid = uniqid();
    http_response_code(303);
    // Redirect to anchor if it was a comment vote
    $anchor = ($type === 'comment') ? "#c$tid" : "";
    header("Location: post.php?id=$id&r=$uid$anchor"); exit;
}

// 4. Fetch Vote Data (Optimized)
// A. Post Score
$stmt_ps = $pdo->prepare("SELECT SUM(vote_val) FROM votes WHERE target_type='post' AND target_id=?");
$stmt_ps->execute([$id]);
$post_score = $stmt_ps->fetchColumn() ?: 0;
// B. My Post Vote
$stmt_mp = $pdo->prepare("SELECT vote_val FROM votes WHERE user_id=? AND target_type='post' AND target_id=?");
$stmt_mp->execute([$_SESSION['user_id'], $id]);
$my_post_vote = $stmt_mp->fetchColumn() ?: 0;

// C. Fetch ALL Comment Scores & My Votes in one go (Prevents N+1 queries)
$comment_scores = [];
$my_comment_votes = [];

// Scores
$stmt_cs = $pdo->prepare("SELECT target_id, SUM(vote_val) as score FROM votes WHERE target_type='comment' AND target_id IN (SELECT id FROM post_comments WHERE post_id=?) GROUP BY target_id");
$stmt_cs->execute([$id]);
while($r = $stmt_cs->fetch()) { $comment_scores[$r['target_id']] = $r['score']; }

// My Votes
$stmt_mv = $pdo->prepare("SELECT target_id, vote_val FROM votes WHERE user_id=? AND target_type='comment' AND target_id IN (SELECT id FROM post_comments WHERE post_id=?)");
$stmt_mv->execute([$_SESSION['user_id'], $id]);
while($r = $stmt_mv->fetch()) { $my_comment_votes[$r['target_id']] = $r['vote_val']; }
?>
<!DOCTYPE html>
<html>
<head>
    <title><?= htmlspecialchars($post['title']) ?></title>
    <link rel="stylesheet" href="style.css">
    <style>body { display: block !important; }</style>
</head>
<body class="<?= $theme_cls ?? '' ?>" <?= $bg_style ?? '' ?> style="display: block;">

<div class="main-container" style="width: 800px; margin: 0 auto;">
    
    <div class="nav-bar" style="background: #161616; border-bottom: 1px solid #333; padding: 15px 20px; display: flex; justify-content: space-between; align-items: center;">
        <div style="display:flex; align-items:center; gap: 20px;">
            <div>
                <a href="index.php" class="term-logo">Placebo</a>
                <span style="color:#333; font-size:0.75rem; font-family:monospace; margin-left:5px;">// Archive</span>
            </div>
            <div style="font-size: 0.75rem; font-family: monospace;">
                <a href="chat.php" style="color:#888; margin-right:10px; text-decoration:none;">[ CHAT ]</a>
                <a href="index.php" style="color:#888; text-decoration:none;">[ HOME ]</a>
            </div>
        </div>
        <div class="nav-links" style="font-size: 0.75rem; font-family: monospace;">
             <a href="logout.php">LOGOUT</a>
        </div>
    </div>

    <div style="padding: 30px; background: #0d0d0d; min-height: 80vh;">
        <div style="background: var(--panel-bg); border: 1px solid var(--border-color); padding: 30px; border-radius: 4px;">
            <h1 style="color: var(--accent-primary); margin-top: 0; display:flex; justify-content:space-between; align-items:flex-start; border-bottom: 1px solid #333; padding-bottom: 15px; margin-bottom: 20px;">
                <span style="overflow-wrap: anywhere; word-break: break-word; max-width: 80%;"><?= parse_bbcode($post['title']) ?></span>
                
                <div style="display:flex; flex-direction:column; align-items:flex-end; gap:5px;">
                    <?php if($post['min_rank'] > 1): ?>
                        <span style="font-size:0.6rem; border:1px solid var(--accent-secondary); color:var(--accent-secondary); padding:4px 8px; border-radius:4px; letter-spacing:1px;">
                            LEVEL <?= $post['min_rank'] ?>
                        </span>
                    <?php endif; ?>
                    
                    <div style="display:flex; align-items:center; gap:8px;">
                        <form method="POST" style="margin:0;">
                            <input type="hidden" name="cast_vote" value="1"><input type="hidden" name="vote_type" value="post"><input type="hidden" name="vote_id" value="<?= $id ?>"><input type="hidden" name="vote_val" value="1">
                            <button type="submit" style="background:none; border:none; cursor:pointer; font-family:monospace; font-size:1.2rem; line-height:1; color:<?= ($my_post_vote==1)?'#6a9c6a':'#444' ?>;">+</button>
                        </form>
                        <span style="color:#888; font-family:monospace; font-weight:bold; font-size:0.9rem;"><?= $post_score ?></span>
                        <form method="POST" style="margin:0;">
                            <input type="hidden" name="cast_vote" value="1"><input type="hidden" name="vote_type" value="post"><input type="hidden" name="vote_id" value="<?= $id ?>"><input type="hidden" name="vote_val" value="-1">
                            <button type="submit" style="background:none; border:none; cursor:pointer; font-family:monospace; font-size:1.2rem; line-height:1; color:<?= ($my_post_vote==-1)?'#e06c75':'#444' ?>;">-</button>
                        </form>
                    </div>
                </div>
            </h1>
            
            <div style="margin-bottom: 25px; font-size: 0.75rem; color: #555;">
                TRANSMISSION BY: <span style="color:var(--accent-secondary);"><?= htmlspecialchars($post['username']) ?></span> 
                <span style="margin: 0 10px;">|</span> 
                DATE: <?= $post['created_at'] ?>
                <span style="margin: 0 10px;">|</span> 
                VIEWS: <span style="color:#ccc;"><?= number_format($post['views'] ?? 0) ?></span>
            </div>
            
            <div style="line-height: 1.6; color: var(--text-main); font-family: inherit; font-size: 0.9rem; overflow-wrap: anywhere; word-break: break-word;">
                <?= parse_bbcode($post['body']) ?>
            </div>
            
            <?php if(isset($_SESSION['rank']) && $_SESSION['rank'] >= 9): ?>
                <div style="margin-top: 40px; border-top: 1px dashed #333; padding-top: 15px; text-align: right;">
                     <a href="create_post.php?edit=<?= $post['id'] ?>" style="color: #e06c75; font-size: 0.7rem; text-decoration: none; border: 1px solid #e06c75; padding: 5px 10px;">[ EDIT TRANSMISSION ]</a>
                </div>
            <?php endif; ?>
        </div>

<?php
// 2. Fetch Comments
        $c_stmt = $pdo->prepare("
            SELECT c.*, 
                   COALESCE(
                       u.username, 
                       gt.guest_username, 
                       CASE WHEN c.user_id < 0 THEN CONCAT('Guest_', ABS(c.user_id)) ELSE 'Unknown' END
                   ) AS username, 
                   COALESCE(u.rank, 0) AS rank, 
                   COALESCE(u.chat_color, gt.guest_color, '#888888') AS chat_color 
            FROM post_comments c 
            LEFT JOIN users u ON c.user_id = u.id 
            LEFT JOIN guest_tokens gt ON (c.user_id < 0 AND gt.id = ABS(c.user_id))
            WHERE c.post_id = ? 
            ORDER BY c.created_at ASC
        ");
        $c_stmt->execute([$id]);
        $all_comments = $c_stmt->fetchAll();
// 3. Recursive Render Function
        function render_comments($comments, $parent_id = null, $depth = 0) {
            // FIX: Added vote arrays to global scope so function can see them
            global $post, $op_mod_enabled, $comment_scores, $my_comment_votes;

            foreach ($comments as $c) {
                if ($c['parent_id'] == $parent_id) {
                    $margin = $depth * 20;
                    $border_color = ($depth > 0) ? '#333' : '#444';
                    
                    // Determine Delete Rights
                    $can_delete = (
                        ($_SESSION['rank']??0) >= 9 || 
                        $c['user_id'] == $_SESSION['user_id'] || 
                        ($post['user_id'] == $_SESSION['user_id'] && $op_mod_enabled)
                    );
                    
                    echo "<div id='c{$c['id']}' style='margin-left: {$margin}px; margin-top: 10px; border-left: 2px solid $border_color; padding-left: 10px;'>
                        <div style='display:flex; justify-content:space-between; align-items:center; background:#111; padding:5px; border:1px solid #222;'>
                            <span style='color:{$c['chat_color']}; font-weight:bold; font-size:0.75rem;'>{$c['username']}</span>
                            <span style='color:#555; font-size:0.65rem;'>{$c['created_at']}</span>
                        </div>
                        <div style='padding: 8px; color:#ccc; font-size:0.8rem; background:#0a0a0a; border:1px solid #222; border-top:none;'>
                            " . parse_bbcode($c['body']) . "
                            <div style='display:flex; justify-content:space-between; align-items:center; margin-top:5px; padding-top:5px; border-top:1px dashed #222;'>
                                <div style='display:flex; align-items:center; gap:10px; opacity:0.8;'>
                                    <form method='POST' style='margin:0;'>
                                        <input type='hidden' name='cast_vote' value='1'><input type='hidden' name='vote_type' value='comment'><input type='hidden' name='vote_id' value='{$c['id']}'><input type='hidden' name='vote_val' value='1'>
                                        <button type='submit' style='background:none; border:none; cursor:pointer; font-size:1rem; line-height:1; padding:0; color:" . (($my_comment_votes[$c['id']]??0)==1 ? '#6a9c6a' : '#444') . ";'>+</button>
                                    </form>
                                    <span style='color:#777; font-size:0.75rem; font-family:monospace; font-weight:bold;'>" . ($comment_scores[$c['id']] ?? 0) . "</span>
                                    <form method='POST' style='margin:0;'>
                                        <input type='hidden' name='cast_vote' value='1'><input type='hidden' name='vote_type' value='comment'><input type='hidden' name='vote_id' value='{$c['id']}'><input type='hidden' name='vote_val' value='-1'>
                                        <button type='submit' style='background:none; border:none; cursor:pointer; font-size:1rem; line-height:1; padding:0; color:" . (($my_comment_votes[$c['id']]??0)==-1 ? '#e06c75' : '#444') . ";'>-</button>
                                    </form>
                                </div>
                                
                                <div style='display:flex; align-items:center; gap:10px;'>
                                " . ($can_delete ? "
                                <form method='POST' onsubmit=\"return confirm('WARNING: Deleting this signal will also wipe all replies attached to it. Proceed?');\" style='margin:0;'>
                                    <button type='submit' name='delete_comment' value='{$c['id']}' style='background:none; border:none; color:#e06c75; font-size:0.65rem; cursor:pointer; text-decoration:none;'>[ DELETE ]</button>
                                </form>" : "") . "
                                <a href='post.php?id={$c['post_id']}&reply={$c['id']}#reply_box' style='font-size:0.65rem; color:#6a9c6a; text-decoration:none;'>[ REPLY ]</a>
                            </div>
                        </div>
                    </div>";
                    
                    render_comments($comments, $c['id'], $depth + 1);
                }
            }
        }
        ?>
        
        <div id="comments" style="margin-top: 30px; border-top: 1px solid #333; padding-top: 20px;">
            <h3 style="color:#e5c07b; font-size:0.9rem; margin-bottom:15px;">ENCRYPTED CHATTER (<?= count($all_comments) ?>)</h3>
            
            <?php if(empty($all_comments)): ?>
                <div style="color:#444; font-style:italic;">No signals detected on this frequency.</div>
            <?php else: ?>
                <?php render_comments($all_comments, null, 0); ?>
            <?php endif; ?>
        </div>

        <div id="reply_box" style="margin-top: 30px; background: #111; border: 1px solid #333; padding: 15px;">
            <?php 
                $reply_id = isset($_GET['reply']) ? (int)$_GET['reply'] : null;
                $reply_label = "NEW TRANSMISSION";
                
                // UI: Fetch target username so we know who we are talking to
                if ($reply_id) {
                    $chk = $pdo->prepare("SELECT u.username FROM post_comments c JOIN users u ON c.user_id = u.id WHERE c.id = ?");
                    $chk->execute([$reply_id]);
                    if($target = $chk->fetch()) {
                        $reply_label = "REPLYING TO: <span style='color:#fff;'>" . htmlspecialchars($target['username']) . "</span>";
                    } else {
                        $reply_label = "REPLYING TO ID #$reply_id";
                    }
                }
            ?>
            <div style="color:#6a9c6a; font-size:0.8rem; font-weight:bold; margin-bottom:10px; border-bottom:1px dashed #333; padding-bottom:5px;">
                <?= $reply_label ?>
            </div>
            
            <form method="POST">
                <?php if($reply_id): ?><input type="hidden" name="parent_id" value="<?= $reply_id ?>"><?php endif; ?>
                <input type="text" name="comment_body" placeholder="Broadcast message... (Press Enter)" required autocomplete="off" style="width:100%; height:45px; background:#000; color:#fff; border:1px solid #333; padding:0 15px; box-sizing:border-box; font-family:monospace;">
                <div style="margin-top:5px; text-align:right;">
                    <?php if($reply_id): ?>
                        <a href="post.php?id=<?= $id ?>#comments" style="color:#e06c75; font-size:0.7rem; margin-right:10px; text-decoration:none;">[ CANCEL ]</a>
                    <?php endif; ?>
                    <button type="submit" name="post_comment" class="btn-primary" style="width:auto; padding:5px 15px;">TRANSMIT</button>
                </div>
            </form>
        </div>
        
        <div style="margin-top: 20px; text-align: center;">
            <a href="index.php" style="color: #666; font-size: 0.75rem; text-decoration: none;">&lt; RETURN TO FEED</a>
        </div>
    </div>

    <div style="border-top: 1px solid #222; padding: 20px; text-align: center; color: #444; font-size: 0.7rem; background: #161616;">
         Session ID: <?= strtoupper(substr(session_id(), 0, 8)) ?>
    </div>
</div>

</body>
</html>