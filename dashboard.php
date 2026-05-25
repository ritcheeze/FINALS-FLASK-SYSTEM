<?php
session_start();
// Redirect to login page if not logged in
if (!isset($_SESSION['user_id'])) {
    header("Location: login.php");
    exit;
}

include('db.php');

// FIXED: Changed $_SESSION['users_id'] to $_SESSION['user_id'] to match login.php
$stmt = $conn->prepare("SELECT * FROM users WHERE id = ?");
$stmt->bind_param("i", $_SESSION['user_id']);
$stmt->execute();
$student = $stmt->get_result()->fetch_assoc();

// Fallback array if the database record is somehow missing or empty
if (!$student) {
    $student = [];
}

if (isset($_GET['drop_subject_id'])) {
    $subjectId = (int)$_GET['drop_subject_id'];

    $conn->begin_transaction();
    try {
        $dropStmt = $conn->prepare("DELETE FROM student_enrollments WHERE user_id = ? AND subject_id = ?");
        $dropStmt->bind_param("ii", $_SESSION['user_id'], $subjectId);
        $dropStmt->execute();
        $dropStmt->close();

        $gradeDropStmt = $conn->prepare("DELETE FROM student_grades WHERE user_id = ? AND subject_id = ?");
        $gradeDropStmt->bind_param("ii", $_SESSION['user_id'], $subjectId);
        $gradeDropStmt->execute();
        $gradeDropStmt->close();

        $recomputeStmt = $conn->prepare(
            "UPDATE users u
             SET u.gpa = (
                SELECT IFNULL(AVG(sg.grade), 0)
                FROM student_grades sg
                JOIN student_enrollments se
                  ON se.user_id = sg.user_id
                 AND se.subject_id = sg.subject_id
                WHERE sg.user_id = u.id
                  AND sg.grade IS NOT NULL
             )
             WHERE u.id = ?"
        );
        $recomputeStmt->bind_param("i", $_SESSION['user_id']);
        $recomputeStmt->execute();
        $recomputeStmt->close();

        $conn->commit();
    } catch (Throwable $t) {
        $conn->rollback();
        echo "<script>alert('Unable to drop subject. Please try again.'); window.location.href='dashboard.php';</script>";
        exit;
    }

    echo "<script>alert('Subject dropped successfully. GPA updated.'); window.location.href='dashboard.php';</script>";
    exit;
}


$subjectsStmt = $conn->prepare(
    "SELECT se.status, se.schedule_day_time, cs.id AS subject_id, cs.subject_name
     FROM student_enrollments se
     JOIN curriculum_subjects cs ON se.subject_id = cs.id
     WHERE se.user_id = ?
     ORDER BY cs.subject_name ASC"
);
$subjectsStmt->bind_param("i", $_SESSION['user_id']);
$subjectsStmt->execute();
$enrolledSubjects = $subjectsStmt->get_result()->fetch_all(MYSQLI_ASSOC);
$subjectsStmt->close();

$gradesStmt = $conn->prepare(
    "SELECT cs.subject_name, sg.grade
     FROM student_grades sg
     JOIN student_enrollments se
       ON se.user_id = sg.user_id
      AND se.subject_id = sg.subject_id
     JOIN curriculum_subjects cs ON sg.subject_id = cs.id
     WHERE sg.user_id = ?
     ORDER BY cs.subject_name ASC"
);
$gradesStmt->bind_param("i", $_SESSION['user_id']);
$gradesStmt->execute();
$grades = $gradesStmt->get_result()->fetch_all(MYSQLI_ASSOC);
$gradesStmt->close();
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Student Dashboard</title>
    <link rel="stylesheet" href="dashboard.css">
    <style>
        .table-wrap { overflow-x:auto; margin-top:1rem; }
        table { width:100%; border-collapse:collapse; }
        th, td { padding:10px; border-bottom:1px solid #e2e8f0; text-align:left; }
        th { background:#f8fafc; color:#475569; }
        .status-pill { display:inline-block; padding:4px 8px; border-radius:999px; font-size:12px; font-weight:700; text-transform:uppercase; }
        .status-pill.pending { background:#fef9c3; color:#854d0e; }
        .status-pill.approved { background:#dcfce7; color:#166534; }
        .btn-danger { background:#ef4444; color:white; text-decoration:none; padding:6px 10px; border-radius:6px; font-size:12px; font-weight:700; }
    </style>
</head>
<body>
    <nav class="navbar">
        <div class="nav-brand">Campus Portal</div>
        <div class="user-info">
            <span>Welcome, <strong><?php echo htmlspecialchars($student['fullname'] ?? $_SESSION['user_name'] ?? 'Student'); ?></strong></span>
            <a href="logout.php" class="logout-link">Logout</a>
        </div>
    </nav>

    <div class="dashboard-container">
        <aside class="sidebar">
            <ul>
                <li><a href="#" class="active">Overview</a></li>
                <li><a href="#grades">Academic Records</a></li>
                <li><a href="#classes">Enrolled Classes</a></li>
                <li><a href="#" onclick="alert('Settings module coming soon!')">Account Settings</a></li>
                <li><a href="enrollment.php">Enroll</a></li>
            </ul>
        </aside>

        <main class="main-content">
            <h2>Dashboard Overview</h2>
            
            <div class="card-grid">
                <div class="card">
                    <h3>Student Info</h3>
                    <p><strong>ID:</strong> <?php echo htmlspecialchars($student['student_id'] ?? 'Not Assigned'); ?></p>
                    <p><strong>Course:</strong> <?php echo htmlspecialchars($student['course'] ?? 'Not Enrolled'); ?></p>
                    <p><strong>Year Level:</strong> <?php echo htmlspecialchars($student['year_level'] ?? 'N/A'); ?> Year</p>
                    <p><strong>Section:</strong> <?php echo htmlspecialchars($student['section'] ?? 'Unassigned'); ?></p>
                    <p><strong>Status:</strong> <?php echo htmlspecialchars($student['enrollment_status'] ?? 'Regular'); ?></p>
                </div>

                <div class="card Highlight-card">
                    <h3>Academic Standing</h3>
                    <div class="gpa-display">
                        <span class="gpa-num"><?php echo htmlspecialchars($student['gpa'] ?? '0.00'); ?></span>
                        <span class="gpa-label">Current GPA</span>
                    </div>
                </div>

                <div class="card">
                    <h3>System Messages</h3>
                    <p class="notice">✓ Midterm examinations schedule has been posted.</p>
                    <p class="notice">✓ Please clear any outstanding balances before enrollment ends.</p>
                </div>
            </div>

            <div class="card" id="classes" style="margin-top:1.5rem;">
                <h3>Enrolled Classes</h3>
                <div class="table-wrap">
                    <table>
                        <thead>
                            <tr>
                                <th>Subject</th>
                                <th>Schedule</th>
                                <th>Status</th>
                                <th>Action</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if (!$enrolledSubjects): ?>
                                <tr><td colspan="4">No enrolled subjects yet.</td></tr>
                            <?php endif; ?>
                            <?php foreach ($enrolledSubjects as $subject): ?>
                                <tr>
                                    <td><strong><?php echo htmlspecialchars($subject['subject_name']); ?></strong></td>
                                    <td><?php echo htmlspecialchars($subject['schedule_day_time']); ?></td>
                                    <td><span class="status-pill <?php echo htmlspecialchars($subject['status']); ?>"><?php echo htmlspecialchars($subject['status']); ?></span></td>
                                    <td>
                                        <a class="btn-danger" href="dashboard.php?drop_subject_id=<?php echo (int)$subject['subject_id']; ?>" onclick="return confirm('Drop this subject?')">Drop</a>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </div>

            <div class="card" id="grades" style="margin-top:1.5rem;">
                <h3>Academic Records</h3>
                <div class="table-wrap">
                    <table>
                        <thead>
                            <tr>
                                <th>Subject</th>
                                <th>Grade</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if (!$grades): ?>
                                <tr><td colspan="2">No grades posted yet.</td></tr>
                            <?php endif; ?>
                            <?php foreach ($grades as $grade): ?>
                                <tr>
                                    <td><?php echo htmlspecialchars($grade['subject_name']); ?></td>
                                    <td><strong><?php echo htmlspecialchars($grade['grade'] ?? 'Pending'); ?></strong></td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </main>
    </div>
</body>
</html>
