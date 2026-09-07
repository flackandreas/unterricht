<?php
/**
 * src/feedback_view.php
 * Evaluation view for a specific feedback session.
 */

require_once __DIR__ . '/config/database.php';
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/error_page.php';

require_login();

$session_id = (int)($_GET['id'] ?? 0);
$conn = db_connect();

// 1. Fetch session details
$stmt = $conn->prepare("SELECT * FROM feedback_sessions WHERE id = ? AND teacher_id = ?");
$stmt->execute([$session_id, get_current_user_id()]);
$session = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$session) {
    error_page("Sitzung nicht gefunden", "Sie existiert nicht oder gehört einer anderen Lehrkraft.", 404, "/feedback.php");
}

// 2. Fetch questions
$stmt_q = $conn->prepare("SELECT * FROM feedback_questions WHERE session_id = ? ORDER BY sort_order ASC");
$stmt_q->execute([$session_id]);
$questions = $stmt_q->fetchAll(PDO::FETCH_ASSOC);

// 3. Fetch responses
$stmt_res = $conn->prepare("SELECT question_id, score, response_text FROM feedback_responses WHERE session_id = ?");
$stmt_res->execute([$session_id]);
$responses = $stmt_res->fetchAll(PDO::FETCH_ASSOC);

// 4. Process data for charts and calculate averages
$data = [];
foreach ($questions as $q) {
    $type = $q['question_type'] ?? 'emoji';
    $data[$q['id']] = [
        'text' => $q['question_text'],
        'type' => $type,
        'options' => $q['options'] ?? '',
        'scores' => [],
        'responses' => [],
        'total_score' => 0,
        'count' => 0,
        'avg' => 0
    ];
    
    if ($type === 'emoji') {
        $data[$q['id']]['scores'] = [1=>0, 2=>0, 3=>0, 4=>0, 5=>0];
    } elseif ($type === 'mc') {
        $opts = array_map('trim', explode(',', $q['options'] ?? ''));
        foreach ($opts as $opt) {
            if (!empty($opt)) {
                $data[$q['id']]['scores'][$opt] = 0;
            }
        }
    }
}

$total_emoji_score = 0;
$total_emoji_count = 0;

foreach ($responses as $r) {
    $q_id = $r['question_id'];
    if (isset($data[$q_id])) {
        $type = $data[$q_id]['type'];
        if ($type === 'emoji') {
            $score = (int)$r['score'];
            $data[$q_id]['scores'][$score]++;
            $data[$q_id]['total_score'] += $score;
            $data[$q_id]['count']++;
            
            $total_emoji_score += $score;
            $total_emoji_count++;
        } elseif ($type === 'mc') {
            $val = trim($r['response_text'] ?? '');
            if ($val !== '') {
                if (!isset($data[$q_id]['scores'][$val])) {
                    $data[$q_id]['scores'][$val] = 0;
                }
                $data[$q_id]['scores'][$val]++;
                $data[$q_id]['count']++;
            }
        } elseif ($type === 'text') {
            $val = trim($r['response_text'] ?? '');
            if ($val !== '') {
                $data[$q_id]['responses'][] = $val;
                $data[$q_id]['count']++;
            }
        }
    }
}

// Calculate individual question averages
foreach ($data as $q_id => &$q_data) {
    if ($q_data['type'] === 'emoji') {
        $q_data['avg'] = ($q_data['count'] > 0) ? ($q_data['total_score'] / $q_data['count']) : 0;
    }
}
unset($q_data);

// Calculate overall session average
$session_average = ($total_emoji_count > 0) ? ($total_emoji_score / $total_emoji_count) : 0;

$num_questions = count($questions);
$total_votes = ($num_questions > 0) ? count($responses) / $num_questions : 0;

require_once __DIR__ . '/includes/twig_setup.php';

echo $twig->render('feedback_view.twig', [
    'session' => $session,
    'data' => $data,
    'total_votes' => (int)$total_votes,
    'session_average' => $session_average,
    'current_user_name' => get_current_user_name(),
    'is_admin' => is_current_user_admin(),
    'is_logged_in' => true
]);
